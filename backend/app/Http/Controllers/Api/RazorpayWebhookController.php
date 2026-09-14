<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RazorpayWebhookLog;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\RazorpayService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RazorpayWebhookController extends Controller
{
    protected RazorpayService $razorpayService;

    public function __construct(RazorpayService $razorpayService)
    {
        $this->razorpayService = $razorpayService;
    }

    /**
     * Handle incoming Razorpay Webhook
     */
    public function handle(Request $request)
    {
        $signature = $request->header('X-Razorpay-Signature');
        $rawPayload = $request->getContent();

        if (empty($signature)) {
            Log::warning('Razorpay webhook missing signature header');
            return response()->json(['status' => 'error', 'message' => 'Missing webhook signature.'], 400);
        }

        // Verify webhook cryptographic signature with RAZORPAY_WEBHOOK_SECRET
        $isValid = $this->razorpayService->verifyWebhookSignature($rawPayload, $signature);

        if (!$isValid) {
            Log::warning('Razorpay webhook invalid signature');
            return response()->json(['status' => 'error', 'message' => 'Invalid webhook signature.'], 400);
        }

        $payload = json_decode($rawPayload, true);
        if (!is_array($payload) || !isset($payload['event'])) {
            return response()->json(['status' => 'error', 'message' => 'Invalid webhook payload.'], 400);
        }

        $eventId = $payload['event_id'] ?? ($request->header('X-Razorpay-Event-Id') ?? ('evt_' . md5($rawPayload)));
        $event = $payload['event'];

        // Strict Event Idempotency: Ignore duplicate webhook deliveries
        $existingLog = RazorpayWebhookLog::where('event_id', $eventId)->first();
        if ($existingLog) {
            return response()->json([
                'status' => 'success',
                'message' => 'Webhook event already processed.'
            ], 200);
        }

        try {
            DB::transaction(function () use ($eventId, $event, $payload) {
                // Record the incoming event log
                RazorpayWebhookLog::create([
                    'event_id' => $eventId,
                    'event' => $event,
                    'payload' => $payload,
                    'processed_status' => 'processing',
                ]);

                // Route event to handler
                switch ($event) {
                    case 'payment.captured':
                        $this->handlePaymentCaptured($payload);
                        break;

                    case 'payment.failed':
                        $this->handlePaymentFailed($payload);
                        break;

                    case 'refund.created':
                    case 'refund.processed':
                        $this->handleRefund($payload, $event);
                        break;

                    default:
                        Log::info("Razorpay webhook event unhandled: {$event}");
                        break;
                }

                RazorpayWebhookLog::where('event_id', $eventId)->update(['processed_status' => 'completed']);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Webhook handled successfully.'
            ], 200);
        } catch (Exception $e) {
            Log::error('Razorpay webhook handling error', [
                'event' => $event,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Webhook processing failed.'
            ], 500);
        }
    }

    /**
     * Handle payment.captured event
     */
    protected function handlePaymentCaptured(array $payload): void
    {
        $payment = $payload['payload']['payment']['entity'] ?? [];
        $orderId = $payment['order_id'] ?? null;
        $paymentId = $payment['id'] ?? null;

        if (!$orderId) {
            return;
        }

        // 1. Check if it matches a Wallet Top-up
        $walletTx = WalletTransaction::where('razorpay_order_id', $orderId)->first();
        if ($walletTx) {
            if (isset($payment['amount']) && (int) $payment['amount'] !== (int) round($walletTx->amount * 100)) {
                Log::warning('Razorpay webhook amount mismatch for wallet top-up', ['razorpay_order_id' => $orderId]);
                return;
            }

            // Atomic claim so a concurrent /payment/razorpay/verify call cannot credit the same top-up twice
            $claimed = WalletTransaction::where('id', $walletTx->id)
                ->whereIn('status', ['pending', 'rejected'])
                ->update([
                    'status' => 'approved',
                    'razorpay_payment_id' => $paymentId,
                ]);

            if ($claimed === 1) {
                $lockedUser = User::lockForUpdate()->findOrFail($walletTx->user_id);
                $lockedUser->wallet_balance += $walletTx->amount;
                $lockedUser->save();

                WalletTransaction::where('id', $walletTx->id)
                    ->update(['balance_after' => $lockedUser->wallet_balance]);
            }
            return;
        }

        // 2. Check if it matches a Service Order
        $serviceOrder = ServiceOrder::where('razorpay_order_id', $orderId)->first();
        if ($serviceOrder) {
            ServiceOrder::where('id', $serviceOrder->id)
                ->whereIn('payment_status', ['pending', 'rejected'])
                ->update([
                    'payment_status' => 'approved',
                    'order_status' => 'processing',
                    'rejection_reason' => null,
                    'razorpay_payment_id' => $paymentId,
                ]);
        }
    }

    /**
     * Handle payment.failed event
     */
    protected function handlePaymentFailed(array $payload): void
    {
        $payment = $payload['payload']['payment']['entity'] ?? [];
        $orderId = $payment['order_id'] ?? null;
        $reason = $payment['error_description'] ?? 'Payment failed on Razorpay';

        if (!$orderId) {
            return;
        }

        $walletTx = WalletTransaction::where('razorpay_order_id', $orderId)
            ->where('status', 'pending')
            ->first();

        if ($walletTx) {
            $walletTx->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
            ]);
            return;
        }

        $serviceOrder = ServiceOrder::where('razorpay_order_id', $orderId)
            ->where('payment_status', 'pending')
            ->first();

        if ($serviceOrder) {
            $serviceOrder->update([
                'payment_status' => 'rejected',
                'order_status' => 'rejected',
                'rejection_reason' => $reason,
            ]);
        }
    }

    /**
     * Handle refund.created / refund.processed events
     */
    protected function handleRefund(array $payload, string $event): void
    {
        $refund = $payload['payload']['refund']['entity'] ?? [];
        $paymentId = $refund['payment_id'] ?? null;

        Log::info("Razorpay refund event [{$event}] recorded", [
            'payment_id' => $paymentId,
            'refund_id' => $refund['id'] ?? null,
            'amount' => isset($refund['amount']) ? ($refund['amount'] / 100) : null,
        ]);
    }
}
