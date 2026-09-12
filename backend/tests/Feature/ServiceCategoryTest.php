<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServiceCategoryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_public_service_categories_can_be_retrieved(): void
    {
        $response = $this->getJson('/api/service-categories');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    '*' => ['id', 'slug', 'name', 'description', 'icon', 'sort_order', 'is_active', 'services_count', 'image_url']
                ]
            ]);

        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('print', $slugs);
        $this->assertContains('pan_find', $slugs);
    }

    public function test_admin_can_retrieve_and_update_category_with_image_upload(): void
    {
        $admin = User::where('role', 'admin')->first();
        $token = $admin->createToken('admin_test_token')->plainTextToken;

        // 1. Admin gets all categories
        $listResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/service-categories');
        $listResponse->assertStatus(200)
            ->assertJsonStructure(['status', 'data']);

        // 2. Admin uploads a category banner image
        $cat = ServiceCategory::where('slug', 'print')->first();
        $fakeImage = UploadedFile::fake()->create('pvc_banner.jpg', 100, 'image/jpeg');

        $updateResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/service-categories/{$cat->id}", [
                'name' => 'PVC Card Print Services High-Res',
                'description' => 'Updated high definition smart card printing',
                'image' => $fakeImage,
                'icon' => 'fa-solid fa-id-card',
                'icon_bg' => '#fff7ed',
                'icon_color' => '#e04d00',
                'sort_order' => 1,
            ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.name', 'PVC Card Print Services High-Res');

        $updatedCat = $cat->fresh();
        $this->assertNotNull($updatedCat->image);
        Storage::disk('public')->assertExists($updatedCat->image);
        $this->assertNotNull($updatedCat->image_url);

        // 3. Admin toggles category
        $toggleResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/service-categories/{$cat->id}/toggle");
        $toggleResponse->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }
}
