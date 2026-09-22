<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AllApiService
{
    private const PAID_STATUSES = ['success', 'successful', 'completed', 'paid', 'captured'];

    private const FAILED_STATUSES = ['failed', 'failure', 'expired', 'cancelled', 'canceled', 'rejected'];

    protected string $token;
    protected string $baseUrl;

    public function __construct()
    {
        $this->token = (string) config('allapi.token', '');
        $this->baseUrl = rtrim((string) config('allapi.base_url', 'https://allapi.in'), '/');
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    /**
     * Create an AllAPI order and return the hosted UPI payment page URL
     *
     * @throws Exception
     */
    public function createOrder(string $orderId, float $amount, array $customer): string
    {
        if (!$this->isConfigured()) {
            throw new Exception('AllAPI token is not configured in backend .env.');
        }

        if ($amount <= 0) {
            throw new Exception('Order amount must be greater than zero.');
        }

        $response = $this->post('/order/create', [
            'order_id' => $orderId,
            'txn_amount' => round($amount, 2),
            'txn_note' => 'Wallet top-up ' . $orderId,
            'product_name' => 'Wallet Top-up',
            'customer_name' => $customer['name'] ?? 'Customer',
            'customer_mobile' => $customer['mobile'] ?? '',
            'customer_email' => $customer['email'] ?? '',
            'redirect_url' => $this->redirectUrl($orderId),
        ]);

        $paymentUrl = $response['results']['payment_url'] ?? null;

        // Only ever send customers to AllAPI's own payment page
        if (!filter_var($response['status'] ?? false, FILTER_VALIDATE_BOOLEAN) || !is_string($paymentUrl) || !$this->isGatewayUrl($paymentUrl)) {
            throw new Exception('AllAPI order creation failed: ' . ($response['message'] ?? 'no valid payment URL returned'));
        }

        return $paymentUrl;
    }

    /**
     * Ask AllAPI for the current status of an order.
     * Only an explicit success status counts as paid; anything unknown stays pending.
     *
     * @return array{paid: bool, failed: bool, status: string, amount: ?float, reference: ?string}
     * @throws Exception
     */
    public function fetchPayment(string $orderId): array
    {
        $response = $this->post('/order/status', ['order_id' => $orderId]);

        $results = $response['results'] ?? [];
        if (is_array($results) && array_is_list($results)) {
            $results = $results[0] ?? [];
        }
        $results = is_array($results) ? $results : [];

        $rawStatus = $results['status'] ?? $results['txn_status'] ?? $results['payment_status'] ?? '';
        $status = is_string($rawStatus) ? strtolower(trim($rawStatus)) : '';
        $amount = $results['txn_amount'] ?? $results['amount'] ?? null;
        $reference = $results['utr'] ?? $results['utr_number'] ?? $results['bank_rrn'] ?? $results['txn_id'] ?? null;

        $payment = [
            'paid' => in_array($status, self::PAID_STATUSES, true),
            'failed' => in_array($status, self::FAILED_STATUSES, true),
            'status' => $status,
            'amount' => is_numeric($amount) ? (float) $amount : null,
            'reference' => is_scalar($reference) ? (string) $reference : null,
        ];

        if (!$payment['paid'] && !$payment['failed'] && $results !== []) {
            // Keeps a record of any status format we do not recognise yet
            Log::info('AllAPI order status not recognised as paid or failed', ['order_id' => $orderId, 'results' => $results]);
        }

        return $payment;
    }

    protected function redirectUrl(string $orderId): string
    {
        $base = (string) config('allapi.redirect_url') ?: rtrim((string) config('app.url'), '/') . '/user/wallet.html';

        return $base . (str_contains($base, '?') ? '&' : '?') . 'allapi_order=' . urlencode($orderId);
    }

    protected function isGatewayUrl(string $url): bool
    {
        $gatewayHost = strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && $gatewayHost !== ''
            && ($host === $gatewayHost || str_ends_with($host, '.' . $gatewayHost));
    }

    /**
     * @throws Exception
     */
    protected function post(string $path, array $data): array
    {
        $response = Http::timeout(20)
            ->acceptJson()
            ->asJson()
            ->withOptions(['verify' => filter_var(config('allapi.verify_ssl', true), FILTER_VALIDATE_BOOLEAN)])
            ->post($this->baseUrl . $path, ['token' => $this->token] + $data);

        $json = $response->json();

        if (!is_array($json)) {
            throw new Exception("AllAPI {$path} returned HTTP {$response->status()} without a JSON body.");
        }

        return $json;
    }
}
