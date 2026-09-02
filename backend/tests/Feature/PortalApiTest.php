<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    public function test_public_services_and_settings_can_be_retrieved(): void
    {
        $response = $this->getJson('/api/services');
        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'data']);

        $settings = $this->getJson('/api/settings/public');
        $settings->assertStatus(200)
            ->assertJsonFragment(['upi_id' => 'utkalprint@upi']);
    }

    public function test_user_login_and_token_generation(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'identifier' => 'demo@utkalprint.com',
            'password' => 'User@123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'token', 'user'])
            ->assertJsonFragment(['email' => 'demo@utkalprint.com', 'role' => 'user']);
    }

    public function test_order_creation_with_wallet_balance_deducts_atomically(): void
    {
        $user = User::where('email', 'demo@utkalprint.com')->first();
        $service = Service::where('slug', 'aadhaar-smart-card-pvc-print')->first(); // Price: 50
        $initialBalance = $user->wallet_balance; // 105

        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/orders', [
                'service_id' => $service->id,
                'payment_method' => 'wallet',
                'input_data' => json_encode(['aadhaar_number' => '123456789012', 'applicant_name' => 'Aritra']),
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['payment_status' => 'approved', 'order_status' => 'processing']);

        $user->refresh();
        $this->assertEquals($initialBalance - 50.00, (float) $user->wallet_balance);
    }

    public function test_admin_can_verify_and_fulfill_order(): void
    {
        $admin = User::where('email', 'admin@utkalprint.com')->first();
        $user = User::where('email', 'demo@utkalprint.com')->first();
        $service = Service::first();

        $order = ServiceOrder::create([
            'order_number' => 'UTK-TEST-001',
            'user_id' => $user->id,
            'service_id' => $service->id,
            'amount' => $service->price,
            'payment_method' => 'direct_upi',
            'payment_status' => 'pending',
            'order_status' => 'pending',
            'utr_number' => '123456789012',
        ]);

        $adminToken = $admin->createToken('admin_token')->plainTextToken;

        // Verify payment
        $verifyRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson("/api/admin/orders/{$order->id}/verify-payment", [
                'action' => 'approve'
            ]);
        $verifyRes->assertStatus(200);

        $order->refresh();
        $this->assertEquals('approved', $order->payment_status);
        $this->assertEquals('processing', $order->order_status);

        // Fulfill with PDF upload
        $fakePdf = UploadedFile::fake()->create('aadhaar_card.pdf', 100, 'application/pdf');

        $fulfillRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson("/api/admin/orders/{$order->id}/fulfill", [
                'delivery_file' => $fakePdf,
                'admin_notes' => 'Aadhaar PVC Generated successfully',
            ]);

        $fulfillRes->assertStatus(200);
        $order->refresh();
        $this->assertEquals('completed', $order->order_status);
        $this->assertNotNull($order->delivery_file);
    }

    public function test_non_admin_cannot_access_admin_endpoints(): void
    {
        $user = User::where('email', 'demo@utkalprint.com')->first();
        $token = $user->createToken('user_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/stats');

        $response->assertStatus(403);
    }
}
