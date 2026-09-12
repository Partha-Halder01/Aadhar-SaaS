<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique()->index();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->string('icon', 100)->nullable()->default('fa-solid fa-layer-group');
            $table->string('icon_bg', 50)->nullable()->default('#eff6ff');
            $table->string('icon_color', 50)->nullable()->default('#0066cc');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // Seed initial standard categories
        $now = now();
        $defaultCategories = [
            [
                'slug' => 'print',
                'name' => 'PVC Card Print Services',
                'description' => 'High-definition PVC smart cards for Aadhaar, Voter ID, PAN card, Ayushman Bharat & photo ID cards.',
                'image' => null,
                'icon' => 'fa-solid fa-id-card',
                'icon_bg' => '#fff7ed',
                'icon_color' => '#e04d00',
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'pan_find',
                'name' => 'PAN Find & Recovery',
                'description' => 'Instant search & lost PAN number retrieval using 12-digit Aadhaar number or demographic citizen matching.',
                'image' => null,
                'icon' => 'fa-solid fa-magnifying-glass-location',
                'icon_bg' => '#f0fdf4',
                'icon_color' => '#03a93a',
                'sort_order' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'document',
                'name' => 'Digital Document Services',
                'description' => 'Automated document verification, digital certificates, and citizen utility document downloads.',
                'image' => null,
                'icon' => 'fa-solid fa-file-invoice',
                'icon_bg' => '#eff6ff',
                'icon_color' => '#2563eb',
                'sort_order' => 3,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('service_categories')->insert($defaultCategories);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_categories');
    }
};
