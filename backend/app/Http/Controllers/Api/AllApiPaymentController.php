<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AllApiService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AllApiPaymentController extends Controller
{
    public function __construct(protected AllApiService $allApi)
    {
    }

    /**
     * Create an AllAPI UPI order for a wallet top-up and return its payment page
     */
    public function createWalletOrder(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'amount' => 'required|numeric|min:10|max:50000',
        ]);

        if (!$this->allApi->isConfigured()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Online payment is not configured yet. Please contact support.'
            ], 503);
        }

        $amount = round((float) $request->input('amount'), 2);
        $orderId = 'ODS' . $user->id . 'T' . now()->format('ymdHis') . strtoupper(Str::random(4));

        $tx = WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => $amount,
            'balance_after' => $user->wallet_balance, // balance unchanged until the payment is confirmed
            'description' => 'Wallet Recharge via UPI',
            'payment_method' => 'allapi',
            'gateway_order_id' => $orderId,
            'status' => 'pending',
        ]);

        try {
            $paymentUrl = $this->allApi->createOrder($orderId, $amount, [
                'name' => $user->name,
                'mobile' => $user->phone ?? '',
                'email' => $user->email,
            ]);
        } catch (Exception $e) {
            $tx->update(['status' => 'rejected', 'rejection_reason' => 'Payment link could not be created.']);
            Log::error('AllAPI wallet order creation failed', ['user_id' => $user->id, 'order_id' => $orderId, 'error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to start the payment right now. Please try again in a few minutes.'
            ], 502);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Payment link created.',
            'payment_url' => $paymentUrl,
            'order_id' => $orderId,
            'transaction_id' => $tx->id,
            'expires_in_minutes' => (int) config('allapi.order_timeout_minutes', 30),
        ], 201);
    }

    /**
     * Customer returns from the payment page: confirm the order with AllAPI
     */
    public function verify(Request $request)
    {
        $request->validate([
            'order_id' => 'required|string|max:64',
        ]);

        $tx = WalletTransaction::where('gateway_order_id', trim($request->input('order_id')))
            ->where('user_id', $request->user()->id)
            ->where('payment_method', 'allapi')
            ->first();

        if (!$tx) {
            return response()->json([
                'status' => 'error',
                'message' => 'No matching wallet recharge found for this payment.'
            ], 404);
        }

        return response()->json($this->settle($tx));
    }

    /**
     * AllAPI webhook. The body is only used to find the order; the payment itself
     * is always re-confirmed through the AllAPI status API before any credit.
     */
    public function webhook(Request $request)
    {
        $key = (string) config('allapi.webhook_key', '');
        if ($key !== '' && !hash_equals($key, (string) $request->query('key', ''))) {
            return response()->json(['status' => 'error', 'message' => 'Invalid webhook key.'], 403);
        }

        $orderId = $request->input('order_id') ?? $request->input('results.order_id');
        if (!is_string($orderId) || trim($orderId) === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing order_id.'], 400);
        }

        $tx = WalletTransaction::where('gateway_order_id', trim($orderId))
            ->where('payment_method', 'allapi')
            ->first();

        if (!$tx) {
            return response()->json(['status' => 'error', 'message' => 'Unknown order.'], 404);
        }

        $result = $this->settle($tx);

        return response()->json(['status' => 'success', 'payment_status' => $result['payment_status']]);
    }

    /**
     * Confirm the payment with AllAPI and credit the wallet exactly once
     */
    protected function settle(WalletTransaction $tx): array
    {
        if ($tx->status === 'approved') {
            return $this->result('approved', 'Payment already confirmed and credited.', $tx);
        }

        try {
            $payment = $this->allApi->fetchPayment($tx->gateway_order_id);
        } catch (Exception $e) {
            Log::warning('AllAPI status check failed', ['order_id' => $tx->gateway_order_id, 'error' => $e->getMessage()]);

            return $this->result('pending', 'We could not confirm the payment yet. Please refresh in a minute.', $tx);
        }

        if ($payment['paid']) {
            if ($payment['amount'] === null || abs($payment['amount'] - (float) $tx->amount) > 0.009) {
                Log::warning('AllAPI paid amount does not match wallet recharge', [
                    'order_id' => $tx->gateway_order_id,
                    'expected' => (float) $tx->amount,
                    'reported' => $payment['amount'],
                ]);

                return $this->result('pending', 'Payment is under review. Please contact support with your UPI reference.', $tx);
            }

            // Atomic claim: only the request that flips the row to approved may credit the wallet,
            // so the customer's verify call and a concurrent webhook can never credit twice.
            // 'rejected' stays claimable because a late payment can arrive after the order expired locally.
            DB::transaction(function () use ($tx, $payment) {
                $claimed = WalletTransaction::where('id', $tx->id)
                    ->whereIn('status', ['pending', 'rejected'])
                    ->update([
                        'status' => 'approved',
                        'rejection_reason' => null,
                        'gateway_reference' => $payment['reference'],
                        'utr_number' => $payment['reference'],
                    ]);

                if ($claimed === 0) {
                    return;
                }

                $lockedUser = User::lockForUpdate()->findOrFail($tx->user_id);
                $lockedUser->wallet_balance += $tx->amount;
                $lockedUser->save();

                WalletTransaction::where('id', $tx->id)->update(['balance_after' => $lockedUser->wallet_balance]);
            });

            return $this->result('approved', 'Payment confirmed! Funds added to your wallet.', $tx->fresh());
        }

        $expiresAt = $tx->created_at->copy()->addMinutes((int) config('allapi.order_timeout_minutes', 30) + 5);

        if ($payment['failed'] || now()->greaterThan($expiresAt)) {
            WalletTransaction::where('id', $tx->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected', 'rejection_reason' => 'Payment was not completed.']);

            return $this->result('failed', 'Payment was not completed. No money was added to your wallet.', $tx->fresh());
        }

        return $this->result('pending', 'Waiting for payment confirmation...', $tx);
    }

    protected function result(string $paymentStatus, string $message, WalletTransaction $tx): array
    {
        return [
            'status' => 'success',
            'payment_status' => $paymentStatus,
            'message' => $message,
            'wallet_balance' => (float) User::whereKey($tx->user_id)->value('wallet_balance'),
            'transaction' => $tx,
        ];
    }
}
