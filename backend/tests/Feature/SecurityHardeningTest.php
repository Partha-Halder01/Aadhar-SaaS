<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $phone, string $email): User
    {
        $user = (new User)->forceFill([
            'name' => 'Hardening User',
            'email' => $email,
            'phone' => $phone,
            'password' => Hash::make('Password123'),
        ]);
        $user->save();

        return $user->fresh();
    }

    public function test_query_string_tokens_are_rejected(): void
    {
        $user = $this->makeUser('9777700001', 'querytoken@example.com');
        $token = $user->createToken('query_token')->plainTextToken;

        $this->getJson('/api/auth/me?token=' . urlencode($token))->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/auth/me')->assertStatus(200);
    }

    public function test_signed_download_link_serves_file_and_rejects_tampering(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('deliveries/signed_card.pdf', 'FAKE PDF');

        $owner = $this->makeUser('9777700002', 'signed-owner@example.com');
        $other = $this->makeUser('9777700003', 'signed-other@example.com');

        $order = ServiceOrder::create([
            'order_number' => 'UTK-TEST-SIGNED-01',
            'user_id' => $owner->id,
            'service_id' => Service::first()->id,
            'amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'approved',
            'order_status' => 'completed',
            'delivery_file' => 'deliveries/signed_card.pdf',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $other->createToken('other')->plainTextToken)
            ->getJson("/api/orders/{$order->id}/download-link/delivery")
            ->assertStatus(403);

        $url = $this->withHeader('Authorization', 'Bearer ' . $owner->createToken('owner')->plainTextToken)
            ->getJson("/api/orders/{$order->id}/download-link/delivery")
            ->assertStatus(200)
            ->json('url');

        $this->flushHeaders();
        $this->get($url)->assertStatus(200);
        $this->get(str_replace("/orders/{$order->id}/", '/orders/999999/', $url))->assertStatus(403);
    }

    public function test_verify_then_webhook_credits_wallet_only_once(): void
    {
        config([
            'razorpay.key_id' => 'rzp_test_dummy_key_123',
            'razorpay.key_secret' => 'dummy_secret_key_456',
            'razorpay.webhook_secret' => 'dummy_webhook_secret_789',
        ]);

        $user = $this->makeUser('9777700004', 'double-credit@example.com');
        $token = $user->createToken('pay')->plainTextToken;

        WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => 200.00,
            'balance_after' => 0,
            'description' => 'Wallet Recharge via Razorpay',
            'payment_method' => 'razorpay',
            'razorpay_order_id' => 'order_hardening_001',
            'status' => 'pending',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/payment/razorpay/verify', [
                'razorpay_order_id' => 'order_hardening_001',
                'razorpay_payment_id' => 'pay_hardening_001',
                'razorpay_signature' => hash_hmac('sha256', 'order_hardening_001|pay_hardening_001', 'dummy_secret_key_456'),
            ])
            ->assertStatus(200);

        $rawBody = json_encode([
            'event_id' => 'evt_hardening_001',
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_hardening_001',
                'order_id' => 'order_hardening_001',
                'amount' => 20000,
                'status' => 'captured',
            ]]],
        ]);

        $this->flushHeaders();
        $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $rawBody, 'dummy_webhook_secret_789'),
        ], $rawBody)->assertStatus(200);

        $this->assertEquals(200.00, (float) $user->fresh()->wallet_balance);
    }

    public function test_otp_request_does_not_reveal_unregistered_numbers(): void
    {
        $this->postJson('/api/auth/send-otp', ['phone' => '9777700099', 'purpose' => 'login'])
            ->assertStatus(200)
            ->assertJsonPath('message', 'If this mobile number is registered, an OTP has been sent.');

        $this->assertDatabaseMissing('otps', ['phone' => '9777700099']);
    }

    public function test_password_login_locks_account_after_five_failures(): void
    {
        $this->makeUser('9777700005', 'lockout@example.com');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['identifier' => 'lockout@example.com', 'password' => 'WrongPass1'])
                ->assertStatus(422);
        }

        // Correct password is refused while the account is locked
        $this->postJson('/api/auth/login', ['identifier' => 'lockout@example.com', 'password' => 'Password123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['identifier']);
    }

    public function test_registration_rejects_html_in_name(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => '<img src=x onerror=alert(1)>',
            'email' => 'xss-name@example.com',
            'phone' => '9777700006',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'otp' => '123456',
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_role_and_wallet_balance_are_not_mass_assignable(): void
    {
        $user = $this->makeUser('9777700007', 'mass-assign@example.com');

        $user->update(['name' => 'Renamed User', 'role' => 'admin', 'wallet_balance' => 99999]);

        $fresh = $user->fresh();
        $this->assertSame('Renamed User', $fresh->name);
        $this->assertSame('user', $fresh->role);
        $this->assertEquals(0.0, (float) $fresh->wallet_balance);
    }
}
