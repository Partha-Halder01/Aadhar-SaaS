<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

class RazorpayService
{
    protected ?Api $api = null;
    protected string $keyId;
    protected string $keySecret;
    protected string $webhookSecret;
    protected string $currency;

    public function __construct()
    {
        $this->keyId = (string) config('razorpay.key_id', '');
        $this->keySecret = (string) config('razorpay.key_secret', '');
        $this->webhookSecret = (string) config('razorpay.webhook_secret', '');
        $this->currency = (string) config('razorpay.currency', 'INR');

        if ($this->isConfigured()) {
            $this->api = new Api($this->keyId, $this->keySecret);
        }
    }

    /**
     * Check if Razorpay credentials are fully configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->keyId) && !empty($this->keySecret);
    }

    /**
     * Get the public Key ID for client-side checkout
     */
    public function getKeyId(): string
    {
        return $this->keyId;
    }

    /**
     * Get configured currency
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Create a Razorpay Order
     *
     * @param float|int $amountInRupees Actual amount in Rupees (e.g. 499)
     * @param string $receipt Unique order receipt identifier
     * @param array $notes Metadata notes (never put secrets here)
     * @return array Razorpay order array
     * @throws Exception
     */
    public function createOrder(float|int $amountInRupees, string $receipt, array $notes = []): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('Razorpay API keys are not configured in backend .env.');
        }

        if ($amountInRupees <= 0) {
            throw new Exception('Order amount must be greater than zero.');
        }

        // Amount in paise (e.g., ₹499 = 49900 paise)
        $amountInPaise = (int) round($amountInRupees * 100);

        try {
            $orderData = [
                'receipt' => $receipt,
                'amount' => $amountInPaise,
                'currency' => $this->currency,
                'notes' => $notes,
            ];

            $razorpayOrder = $this->api->order->create($orderData);

            return $razorpayOrder->toArray();
        } catch (Exception $e) {
            Log::error('Razorpay order creation error', [
                'receipt' => $receipt,
                'amount_paise' => $amountInPaise,
                'error' => $e->getMessage()
            ]);
            throw new Exception('Failed to generate Razorpay order: ' . $e->getMessage());
        }
    }

    /**
     * Cryptographically verify the payment signature returned by Checkout
     *
     * @param string $orderId Razorpay Order ID (e.g. order_xxx)
     * @param string $paymentId Razorpay Payment ID (e.g. pay_xxx)
     * @param string $signature Razorpay Signature received from frontend
     * @return bool
     */
    public function verifySignature(string $orderId, string $paymentId, string $signature): bool
    {
        if (empty($this->keySecret) || empty($orderId) || empty($paymentId) || empty($signature)) {
            return false;
        }

        try {
            // Standard HMAC-SHA256 verification (order_id + '|' + payment_id)
            $expectedSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret);

            if (hash_equals($expectedSignature, $signature)) {
                return true;
            }

            // Fallback via official SDK utility if available
            if ($this->api) {
                $this->api->utility->verifyPaymentSignature([
                    'razorpay_order_id' => $orderId,
                    'razorpay_payment_id' => $paymentId,
                    'razorpay_signature' => $signature,
                ]);
                return true;
            }

            return false;
        } catch (SignatureVerificationError $e) {
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Cryptographically verify the webhook signature from Razorpay
     *
     * @param string $rawPayload Raw HTTP request body
     * @param string $signature X-Razorpay-Signature header value
     * @return bool
     */
    public function verifyWebhookSignature(string $rawPayload, string $signature): bool
    {
        if (empty($this->webhookSecret) || empty($rawPayload) || empty($signature)) {
            return false;
        }

        try {
            $expectedSignature = hash_hmac('sha256', $rawPayload, $this->webhookSecret);
            return hash_equals($expectedSignature, $signature);
        } catch (Exception $e) {
            return false;
        }
    }
}
