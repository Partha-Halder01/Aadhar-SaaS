<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\RazorpayService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RazorpayPaymentController extends Controller
{
    protected RazorpayService $razorpayService;

    public function __construct(RazorpayService $razorpayService)
    {
        $this->razorpayService = $razorpayService;
    }

    /**
     * Create a Razorpay Order for Wallet Top-Up
     */
    public function createWalletOrder(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'amount' => 'required|numeric|min:10|max:50000',
        ]);

        $amount = (float) $request->input('amount');

        if (!$this->razorpayService->isConfigured()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Online payment gateway is not configured yet. Please use Direct UPI or contact support.'
            ], 503);
        }

        try {
            $receipt = 'WLT_' . $user->id . '_' . time();
            $notes = [
                'type' => 'wallet_topup',
                'user_id' => (string) $user->id,
                'user_email' => (string) $user->email,
            ];

            $rzpOrder = $this->razorpayService->createOrder($amount, $receipt, $notes);

            // Create pending wallet transaction linked to razorpay_order_id
            $tx = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'credit',
                'amount' => $amount,
                'balance_after' => $user->wallet_balance, // balance remains unchanged until verified
                'description' => 'Wallet Recharge via Razorpay',
                'payment_method' => 'razorpay',
                'razorpay_order_id' => $rzpOrder['id'],
                'status' => 'pending',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Razorpay order created successfully.',
                'razorpay' => [
                    'key_id' => $this->razorpayService->getKeyId(),
                    'order_id' => $rzpOrder['id'],
                    'amount' => $rzpOrder['amount'], // in paise
                    'currency' => $rzpOrder['currency'],
                    'name' => 'Utkal Print Portal',
                    'description' => "Wallet Top-up: ₹" . number_format($amount, 2),
                    'prefill' => [
                        'name' => $user->name,
                        'email' => $user->email,
                        'contact' => $user->phone ?? '',
                    ],
                ],
                'transaction_id' => $tx->id,
            ], 201);
        } catch (Exception $e) {
            Log::error('Razorpay wallet order creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to initialize Razorpay checkout. Please try again.'
            ], 500);
        }
    }

    /**
     * Verify payment signature and atomically confirm payment
     */
    public function verifyPayment(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'razorpay_order_id' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        $orderId = trim($request->input('razorpay_order_id'));
        $paymentId = trim($request->input('razorpay_payment_id'));
        $signature = trim($request->input('razorpay_signature'));

        // Cryptographically verify signature with secret
        $isValid = $this->razorpayService->verifySignature($orderId, $paymentId, $signature);

        if (!$isValid) {
            Log::warning('Razorpay payment signature verification failed', [
                'user_id' => $user->id,
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Payment signature verification failed. Invalid or tampered transaction.'
            ], 400);
        }

        // Case 1: Check if this corresponds to a Wallet Top-up Transaction
        $walletTx = WalletTransaction::where('razorpay_order_id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if ($walletTx) {
            // Atomic claim: only the request that flips the row to approved may credit the wallet,
            // so parallel verify calls or a concurrent webhook can never credit the same top-up twice.
            // 'rejected' stays claimable because Razorpay reports failed attempts before a successful retry.
            $updatedUser = DB::transaction(function () use ($walletTx, $paymentId, $signature) {
                $claimed = WalletTransaction::where('id', $walletTx->id)
                    ->whereIn('status', ['pending', 'rejected'])
                    ->update([
                        'status' => 'approved',
                        'razorpay_payment_id' => $paymentId,
                        'razorpay_signature' => $signature,
                    ]);

                if ($claimed === 0) {
                    return null;
                }

                $lockedUser = User::lockForUpdate()->findOrFail($walletTx->user_id);
                $lockedUser->wallet_balance += $walletTx->amount;
                $lockedUser->save();

                WalletTransaction::where('id', $walletTx->id)
                    ->update(['balance_after' => $lockedUser->wallet_balance]);

                return $lockedUser;
            });

            if (!$updatedUser) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment already verified and credited.',
                    'wallet_balance' => (float) $user->fresh()->wallet_balance,
                    'transaction' => $walletTx->fresh(),
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payment verified successfully! Funds credited to your wallet.',
                'wallet_balance' => (float) $updatedUser->wallet_balance,
                'transaction' => $walletTx->fresh(),
            ]);
        }

        // Case 2: Check if this corresponds to a Service Order
        $serviceOrder = ServiceOrder::where('razorpay_order_id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if ($serviceOrder) {
            // Atomic claim (same reasoning as wallet top-ups above)
            $claimed = ServiceOrder::where('id', $serviceOrder->id)
                ->whereIn('payment_status', ['pending', 'rejected'])
                ->update([
                    'payment_status' => 'approved',
                    'order_status' => 'processing',
                    'rejection_reason' => null,
                    'razorpay_payment_id' => $paymentId,
                    'razorpay_signature' => $signature,
                ]);

            if ($claimed === 0) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Order payment already verified and processing.',
                    'order' => $serviceOrder->fresh('service'),
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payment verified successfully! Your order is now processing.',
                'order' => $serviceOrder->fresh('service'),
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'No matching order or wallet recharge found for this transaction.'
        ], 404);
    }
}
