<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AllApiPaymentTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'allapi.token' => 'test_allapi_token',
            'allapi.base_url' => 'https://allapi.in',
            'allapi.webhook_key' => 'hook_secret_123',
            'allapi.redirect_url' => 'https://onlinedigitalservice.xyz/user/wallet.html',
        ]);

        $this->user = User::where('email', 'demo@utkalprint.com')->first();
        $this->user->forceFill(['wallet_balance' => 100])->save();
        $this->token = $this->user->createToken('test_token')->plainTextToken;
    }

    private function pendingTopup(string $orderId, float $amount = 250): WalletTransaction
    {
        return WalletTransaction::create([
            'user_id' => $this->user->id,
            'type' => 'credit',
            'amount' => $amount,
            'balance_after' => 100,
            'description' => 'Wallet Recharge via UPI',
            'payment_method' => 'allapi',
            'gateway_order_id' => $orderId,
            'status' => 'pending',
        ]);
    }

    private function fakeStatus(string &$status, float $amount = 250): void
    {
        Http::fake(function (HttpRequest $request) use (&$status, $amount) {
            return Http::response([
                'status' => true,
                'message' => 'Transaction Details',
                'results' => [
                    'txn_id' => '85548511',
                    'order_id' => $request['order_id'],
                    'txn_amount' => $amount,
                    'status' => $status,
                    'utr' => 'UTR123456789',
                ],
            ]);
        });
    }

    public function test_create_wallet_order_returns_allapi_payment_page(): void
    {
        Http::fake(['allapi.in/order/create' => Http::response([
            'status' => true,
            'message' => 'Order Created Successfully',
            'results' => ['txn_id' => 85548511, 'payment_url' => 'https://allapi.in/order/payment/abc123'],
        ])]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/payment/allapi/create-wallet-order', ['amount' => 250]);

        $response->assertStatus(201)->assertJsonFragment(['payment_url' => 'https://allapi.in/order/payment/abc123']);

        $tx = WalletTransaction::where('gateway_order_id', $response->json('order_id'))->firstOrFail();
        $this->assertSame('pending', $tx->status);
        $this->assertEquals(250.0, (float) $tx->amount);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://allapi.in/order/create'
            && $r['token'] === 'test_allapi_token'
            && (float) $r['txn_amount'] === 250.0
            && str_contains($r['redirect_url'], 'allapi_order=' . $response->json('order_id')));
    }

    public function test_create_rejects_a_payment_url_outside_allapi(): void
    {
        Http::fake(['allapi.in/order/create' => Http::response([
            'status' => true,
            'results' => ['payment_url' => 'https://evil.example/pay'],
        ])]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/payment/allapi/create-wallet-order', ['amount' => 250])
            ->assertStatus(502);

        $this->assertDatabaseHas('wallet_transactions', ['user_id' => $this->user->id, 'payment_method' => 'allapi', 'status' => 'rejected']);
    }

    public function test_verify_credits_wallet_exactly_once_when_paid(): void
    {
        $tx = $this->pendingTopup('ODS_TEST_PAID');
        $status = 'Success';
        $this->fakeStatus($status);

        for ($i = 0; $i < 2; $i++) {
            $this->withHeader('Authorization', 'Bearer ' . $this->token)
                ->postJson('/api/payment/allapi/verify', ['order_id' => 'ODS_TEST_PAID'])
                ->assertOk()
                ->assertJsonFragment(['payment_status' => 'approved']);
        }

        $this->assertEquals(350.0, (float) $this->user->fresh()->wallet_balance);
        $this->assertSame('approved', $tx->fresh()->status);
        $this->assertSame('UTR123456789', $tx->fresh()->gateway_reference);
    }

    public function test_verify_does_not_credit_when_amount_differs(): void
    {
        $tx = $this->pendingTopup('ODS_TEST_MISMATCH');
        $status = 'Success';
        $this->fakeStatus($status, 1);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/payment/allapi/verify', ['order_id' => 'ODS_TEST_MISMATCH'])
            ->assertOk()
            ->assertJsonFragment(['payment_status' => 'pending']);

        $this->assertEquals(100.0, (float) $this->user->fresh()->wallet_balance);
        $this->assertSame('pending', $tx->fresh()->status);
    }

    public function test_unpaid_order_stays_pending(): void
    {
        $this->pendingTopup('ODS_TEST_UNPAID');
        $status = 'Pending';
        $this->fakeStatus($status);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/payment/allapi/verify', ['order_id' => 'ODS_TEST_UNPAID'])
            ->assertOk()
            ->assertJsonFragment(['payment_status' => 'pending']);

        $this->assertEquals(100.0, (float) $this->user->fresh()->wallet_balance);
    }

    public function test_webhook_needs_key_and_rechecks_status_before_crediting(): void
    {
        $tx = $this->pendingTopup('ODS_TEST_HOOK');
        $status = 'Pending';
        $this->fakeStatus($status);

        // Wrong key is refused
        $this->postJson('/api/webhooks/allapi?key=wrong', ['order_id' => 'ODS_TEST_HOOK', 'status' => 'Success'])
            ->assertStatus(403);

        // Payload claims success, but AllAPI still says pending: nothing is credited
        $this->postJson('/api/webhooks/allapi?key=hook_secret_123', ['order_id' => 'ODS_TEST_HOOK', 'status' => 'Success'])
            ->assertOk()
            ->assertJsonFragment(['payment_status' => 'pending']);
        $this->assertEquals(100.0, (float) $this->user->fresh()->wallet_balance);

        // Once AllAPI confirms, the webhook credits the wallet
        $status = 'Success';
        $this->postJson('/api/webhooks/allapi?key=hook_secret_123', ['order_id' => 'ODS_TEST_HOOK'])
            ->assertOk()
            ->assertJsonFragment(['payment_status' => 'approved']);
        $this->assertEquals(350.0, (float) $this->user->fresh()->wallet_balance);
        $this->assertSame('approved', $tx->fresh()->status);
    }

    public function test_user_cannot_verify_another_users_order(): void
    {
        $other = User::where('email', 'admin@utkalprint.com')->first();
        WalletTransaction::create([
            'user_id' => $other->id,
            'type' => 'credit',
            'amount' => 250,
            'balance_after' => 0,
            'description' => 'Wallet Recharge via UPI',
            'payment_method' => 'allapi',
            'gateway_order_id' => 'ODS_TEST_OTHER',
            'status' => 'pending',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/payment/allapi/verify', ['order_id' => 'ODS_TEST_OTHER'])
            ->assertStatus(404);
    }
}
