<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServiceInputFieldsTest extends TestCase
{
    use DatabaseTransactions;

    private function adminToken(): string
    {
        return User::where('email', 'admin@utkalprint.com')->first()->createToken('admin_token')->plainTextToken;
    }

    private function serviceWithFields(): Service
    {
        return Service::create([
            'name' => 'Voter ID Mobile Link',
            'slug' => 'voter-id-mobile-link-test',
            'category' => 'voter_service',
            'price' => 55,
            'is_active' => true,
            'required_fields' => [
                ['name' => 'epic_number', 'label' => 'EPIC Number', 'type' => 'text', 'required' => true, 'placeholder' => ''],
                ['name' => 'request_type', 'label' => 'Request Type', 'type' => 'select', 'required' => true, 'placeholder' => '', 'options' => ['New', 'Correction']],
                ['name' => 'epic_copy', 'label' => 'EPIC Copy', 'type' => 'file', 'required' => true, 'placeholder' => ''],
                ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'required' => false, 'placeholder' => ''],
            ],
        ]);
    }

    public function test_admin_can_define_input_fields_per_service(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->postJson('/api/admin/services', [
                'name' => 'Voter ID Mobile Number Link',
                'category' => 'voter_service',
                'price' => 55,
                'required_fields' => json_encode([
                    ['label' => 'EPIC Number', 'type' => 'text', 'required' => true, 'placeholder' => 'ABC1234567'],
                    ['label' => 'EPIC Number', 'type' => 'unknown-type', 'required' => false],
                    ['label' => 'Request Type', 'type' => 'select', 'required' => true, 'options' => ['New', ' Correction ', '']],
                    ['label' => '<b>Mobile</b>', 'type' => 'tel', 'required' => 'true'],
                ]),
            ]);

        $response->assertStatus(201);
        $fields = Service::findOrFail($response->json('data.id'))->required_fields;

        $this->assertSame(['epic_number', 'epic_number_2', 'request_type', 'mobile'], array_column($fields, 'name'));
        $this->assertSame('text', $fields[1]['type']);
        $this->assertSame(['New', 'Correction'], $fields[2]['options']);
        $this->assertSame('Mobile', $fields[3]['label']);
        $this->assertTrue($fields[3]['required']);
    }

    public function test_dropdown_field_without_options_is_rejected(): void
    {
        $service = $this->serviceWithFields();

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->postJson("/api/admin/services/{$service->id}", [
                'name' => $service->name,
                'category' => $service->category,
                'price' => 55,
                'required_fields' => json_encode([['label' => 'Request Type', 'type' => 'select', 'options' => []]]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['required_fields']);
    }

    public function test_order_must_include_required_service_inputs(): void
    {
        Storage::fake('local');
        $service = $this->serviceWithFields();
        $user = User::where('email', 'demo@utkalprint.com')->first();
        $user->forceFill(['wallet_balance' => 500])->save();
        $token = $user->createToken('test_token')->plainTextToken;

        // Missing EPIC number and upload, invalid dropdown choice -> rejected without charging the wallet
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/orders', [
                'service_id' => $service->id,
                'payment_method' => 'wallet',
                'input_data' => json_encode(['request_type' => 'Something Else']),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['epic_number', 'request_type', 'epic_copy']);

        $this->assertEquals(500.0, (float) $user->fresh()->wallet_balance);

        // Complete submission -> order placed with the answers stored
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post('/api/orders', [
                'service_id' => $service->id,
                'payment_method' => 'wallet',
                'input_data' => json_encode(['epic_number' => 'ABC1234567', 'request_type' => 'Correction', 'remarks' => 'Flat 12/B, 15/09/2026']),
                'file_epic_copy' => UploadedFile::fake()->create('epic.pdf', 50, 'application/pdf'),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $order = ServiceOrder::findOrFail($response->json('data.id'));
        $this->assertSame('ABC1234567', $order->input_data['epic_number']);
        $this->assertSame('Flat 12/B, 15/09/2026', $order->input_data['remarks']);
        $this->assertArrayHasKey('epic_copy', $order->input_data);
        $this->assertEquals(445.0, (float) $user->fresh()->wallet_balance);
    }
}
