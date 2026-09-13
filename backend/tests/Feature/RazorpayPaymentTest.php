<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\RazorpayService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

class RazorpayPaymentTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('email', 'demo@utkalprint.com')->first();
        if (!$this->user) {
            $this->user = User::factory()->create([
                'name' => 'Demo Retailer',
                'email' => 'demo@utkalprint.com',
                'role' => 'user',
                'wallet_balance' => 100.00,
            ]);
        }

        $this->token = $this->user->createToken('test_token')->plainTextToken;

        // Set test credentials in config
        config([
            'razorpay.key_id' => 'rzp_test_dummy_key_123',
            'razorpay.key_secret' => 'dummy_secret_key_456',
            'razorpay.webhook_secret' => 'dummy_webhook_secret_789',
        ]);
    }

    public function test_wallet_order_creation_validates_amount_and_creates_razorpay_order(): void
    {
        $mockRazorpay = Mockery::mock(RazorpayService::class);
        $mockRazorpay->shouldReceive('isConfigured')->andReturn(true);
        $mockRazorpay->shouldReceive('getKeyId')->andReturn('rzp_test_dummy_key_123');
        $mockRazorpay->shouldReceive('createOrder')
            ->once()
            ->with(500.0, Mockery::type('string'), Mockery::type('array'))
            ->andReturn([
                'id' => 'order_wallet_test_001',
                'amount' => 50000,
                'currency' => 'INR',
                'receipt' => 'WLT_test',
            ]);

        $this->app->instance(RazorpayService::class, $mockRazorpay);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/payment/razorpay/create-wallet-order', [
                'amount' => 500,
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'status' => 'success',
                'key_id' => 'rzp_test_dummy_key_123',
                'order_id' => 'order_wallet_test_001',
                'amount' => 50000,
                'currency' => 'INR',
            ]);

        // Secret keys must never be exposed
        $responseContent = $response->getContent();
        $this->assertStringNotContainsString('dummy_secret_key_456', $responseContent);
        $this->assertStringNotContainsString('dummy_webhook_secret_789', $responseContent);

        // Assert pending transaction created in DB
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $this->user->id,
            'amount' => 500.00,
            'payment_method' => 'razorpay',
            'razorpay_order_id' => 'order_wallet_test_001',
            'status' => 'pending',
        ]);
    }

    public function test_wallet_order_creation_rejects_invalid_amounts(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/payment/razorpay/create-wallet-order', [
                'amount' => 5, // Below min 10
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_service_order_calculates_price_strictly_from_database(): void
    {
        $service = Service::where('slug', 'aadhaar-smart-card-pvc-print')->first() ?: Service::first();
        $actualDbPrice = (float) $service->price;

        $mockRazorpay = Mockery::mock(RazorpayService::class);
        $mockRazorpay->shouldReceive('isConfigured')->andReturn(true);
        $mockRazorpay->shouldReceive('getKeyId')->andReturn('rzp_test_dummy_key_123');
        $mockRazorpay->shouldReceive('createOrder')
            ->once()
            ->with($actualDbPrice, Mockery::type('string'), Mockery::type('array'))
            ->andReturn([
                'id' => 'order_srv_test_002',
                'amount' => (int) round($actualDbPrice * 100),
                'currency' => 'INR',
            ]);

        $this->app->instance(RazorpayService::class, $mockRazorpay);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/orders', [
                'service_id' => $service->id,
                'payment_method' => 'razorpay',
                'amount' => 1, // Tampered client-side amount (must be ignored)
                'input_data' => json_encode(['aadhaar_number' => '123456789012']),
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'status' => 'success',
                'requires_payment' => true,
                'order_id' => 'order_srv_test_002',
            ]);

        // Verify that database record has actual service price, NOT the tampered amount
        $this->assertDatabaseHas('service_orders', [
            'user_id' => $this->user->id,
            'service_id' => $service->id,
            'amount' => $actualDbPrice,
            'payment_method' => 'razorpay',
            'payment_status' => 'pending',
            'razorpay_order_id' => 'order_srv_test_002',
        ]);
    }

    public function test_payment_verification_with_valid_signature_credits_wallet_atomically(): void
    {
        $initialBalance = (float) $this->user->wallet_balance;
        $rechargeAmount = 250.00;

        $tx = WalletTransaction::create([
            'user_id' => $this->user->id,
            'type' => 'credit',
            'amount' => $rechargeAmount,
            'balance_after' => $initialBalance,
            'description' => 'Wallet Recharge via Razorpay',
            'payment_method' => 'razorpay',
            'razorpay_order_id' => 'order_verify_test_003',
            'status' => 'pending',
        ]);

        $orderId = 'order_verify_test_003';
        $paymentId = 'pay_verify_test_003';
        $secret = 'dummy_secret_key_456';
        $validSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/payment/razorpay/verify', [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature' => $validSignature,
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => 'success',
            ]);

        $this->user->refresh();
        $this->assertEquals($initialBalance + $rechargeAmount, (float) $this->user->wallet_balance);

        $tx->refresh();
        $this->assertEquals('approved', $tx->status);
        $this->assertEquals($paymentId, $tx->razorpay_payment_id);
    }

    public function test_payment_verification_with_tampered_signature_is_rejected(): void
    {
        $initialBalance = (float) $this->user->wallet_balance;

        WalletTransaction::create([
            'user_id' => $this->user->id,
            'type' => 'credit',
            'amount' => 100.00,
            'balance_after' => $initialBalance,
            'description' => 'Wallet Recharge via Razorpay',
            'payment_method' => 'razorpay',
            'razorpay_order_id' => 'order_fraud_test_004',
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/payment/razorpay/verify', [
                'razorpay_order_id' => 'order_fraud_test_004',
                'razorpay_payment_id' => 'pay_fraud_test_004',
                'razorpay_signature' => 'invalid_fake_signature_hash',
            ]);

        $response->assertStatus(400)
            ->assertJsonFragment(['status' => 'error']);

        $this->user->refresh();
        $this->assertEquals($initialBalance, (float) $this->user->wallet_balance);
    }

    public function test_payment_verification_is_idempotent(): void
    {
        $initialBalance = (float) $this->user->wallet_balance;
        $rechargeAmount = 300.00;

        $tx = WalletTransaction::create([
            'user_id' => $this->user->id,
            'type' => 'credit',
            'amount' => $rechargeAmount,
            'balance_after' => $initialBalance,
            'description' => 'Wallet Recharge via Razorpay',
            'payment_method' => 'razorpay',
            'razorpay_order_id' => 'order_idem_test_005',
            'status' => 'pending',
        ]);

        $orderId = 'order_idem_test_005';
        $paymentId = 'pay_idem_test_005';
        $secret = 'dummy_secret_key_456';
        $signature = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);

        // 1st verify call
        $res1 = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/payment/razorpay/verify', [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature' => $signature,
            ]);
        $res1->assertStatus(200);

        $this->user->refresh();
        $balanceAfterFirst = (float) $this->user->wallet_balance;
        $this->assertEquals($initialBalance + $rechargeAmount, $balanceAfterFirst);

        // 2nd duplicate verify call (must NOT credit again)
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/payment/razorpay/verify', [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature' => $signature,
            ]);
        $res2->assertStatus(200);

        $this->user->refresh();
        $this->assertEquals($balanceAfterFirst, (float) $this->user->wallet_balance);
    }

    public function test_webhook_signature_verification_and_event_handling(): void
    {
        $initialBalance = (float) $this->user->wallet_balance;
        $topupAmount = 150.00;
        $orderId = 'order_webhook_test_006';
        $paymentId = 'pay_webhook_test_006';

        $tx = WalletTransaction::create([
            'user_id' => $this->user->id,
            'type' => 'credit',
            'amount' => $topupAmount,
            'balance_after' => $initialBalance,
            'description' => 'Wallet Top-up via Razorpay',
            'payment_method' => 'razorpay',
            'razorpay_order_id' => $orderId,
            'status' => 'pending',
        ]);

        $webhookPayload = [
            'event_id' => 'evt_wh_test_999',
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $paymentId,
                        'order_id' => $orderId,
                        'amount' => 15000,
                        'status' => 'captured',
                    ]
                ]
            ]
        ];

        $rawBody = json_encode($webhookPayload);
        $signature = hash_hmac('sha256', $rawBody, 'dummy_webhook_secret_789');

        // Post to public webhook endpoint
        $response = $this->call(
            'POST',
            '/api/webhooks/razorpay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
            ],
            $rawBody
        );

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'success']);

        $this->user->refresh();
        $this->assertEquals($initialBalance + $topupAmount, (float) $this->user->wallet_balance);

        $tx->refresh();
        $this->assertEquals('approved', $tx->status);
        $this->assertEquals($paymentId, $tx->razorpay_payment_id);

        // Duplicate delivery should be ignored idempotently
        $dupResponse = $this->call(
            'POST',
            '/api/webhooks/razorpay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
            ],
            $rawBody
        );

        $dupResponse->assertStatus(200);
        $this->user->refresh();
        $this->assertEquals($initialBalance + $topupAmount, (float) $this->user->wallet_balance);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $payload = json_encode([
            'event_id' => 'evt_fake_001',
            'event' => 'payment.captured',
        ]);

        $response = $this->call(
            'POST',
            '/api/webhooks/razorpay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RAZORPAY_SIGNATURE' => 'invalid_signature_hash',
            ],
            $payload
        );

        $response->assertStatus(400)
            ->assertJsonFragment(['status' => 'error']);
    }
}
