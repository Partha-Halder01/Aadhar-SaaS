<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
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
        $service->update(['required_fields' => []]); // this test is about wallet debits, not the service's input form
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

    public function test_admin_user_index_excludes_admin_accounts(): void
    {
        $admin = User::where('email', 'admin@utkalprint.com')->first();
        $adminToken = $admin->createToken('admin_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/admin/users');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        foreach ($data as $item) {
            $this->assertNotEquals('admin', $item['role']);
            $this->assertNotEquals('admin@utkalprint.com', $item['email']);
        }
    }

    public function test_admin_can_block_user_and_delete_inappropriate_review(): void
    {
        $admin = User::where('email', 'admin@utkalprint.com')->first();
        $user = User::where('email', 'demo@utkalprint.com')->first();
        $adminToken = $admin->createToken('admin_token')->plainTextToken;

        // Create a review by user
        $review = \App\Models\Review::create([
            'user_id' => $user->id,
            'author_name' => $user->name,
            'rating' => 1,
            'comment' => 'Inappropriate content to moderate',
            'is_approved' => true,
        ]);

        // Admin blocks user from review
        $blockRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson("/api/admin/reviews/{$review->id}/block-user", [
                'action' => 'block'
            ]);

        $blockRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $user->refresh();
        $this->assertEquals('blocked', $user->status);

        // Admin deletes the inappropriate review
        $deleteRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->deleteJson("/api/admin/reviews/{$review->id}");

        $deleteRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_admin_can_request_missing_document_and_user_can_submit_document(): void
    {
        $admin = User::where('email', 'admin@utkalprint.com')->first();
        $user = User::where('email', 'demo@utkalprint.com')->first();
        $service = Service::first();

        $order = ServiceOrder::create([
            'order_number' => 'UTK-TEST-DOC-001',
            'user_id' => $user->id,
            'service_id' => $service->id,
            'amount' => $service->price,
            'payment_method' => 'wallet',
            'payment_status' => 'approved',
            'order_status' => 'processing',
        ]);

        $adminToken = $admin->createToken('admin_token')->plainTextToken;
        $userToken = $user->createToken('user_token')->plainTextToken;

        // 1. Admin requests missing document
        $requestRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson("/api/admin/orders/{$order->id}/request-document", [
                'doc_name' => 'Back side of Aadhaar Card',
                'message' => 'Please provide a clear image of the back side showing your full address.',
            ]);

        $requestRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $order->refresh();
        $this->assertEquals('Back side of Aadhaar Card', $order->doc_request_title);
        $this->assertEquals('Please provide a clear image of the back side showing your full address.', $order->doc_request_message);
        $this->assertEquals('pending', $order->doc_request_status);
        $this->assertNotNull($order->doc_request_requested_at);

        // 2. User submits the missing document
        $fakeDoc = UploadedFile::fake()->create('aadhaar_back.jpg', 150, 'image/jpeg');

        $submitRes = $this->withHeader('Authorization', 'Bearer ' . $userToken)
            ->postJson("/api/orders/{$order->id}/submit-document", [
                'document_file' => $fakeDoc,
                'notes' => 'Attached clear photo of back side with address.',
            ]);

        $submitRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $order->refresh();
        $this->assertEquals('submitted', $order->doc_request_status);
        $this->assertNotNull($order->doc_response_file);
        $this->assertEquals('Attached clear photo of back side with address.', $order->doc_response_notes);
        $this->assertNotNull($order->doc_response_submitted_at);
    }
}
