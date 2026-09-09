<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('icon_type', 20)->default('icon')->after('description');
            $table->string('icon', 100)->nullable()->after('icon_type');
            $table->string('icon_image', 255)->nullable()->after('icon');
            $table->string('icon_bg', 50)->nullable()->after('icon_image');
            $table->string('icon_color', 50)->nullable()->after('icon_bg');
            $table->string('btn_text', 100)->nullable()->after('icon_color');
            $table->string('btn_icon', 100)->nullable()->after('btn_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'icon_type',
                'icon',
                'icon_image',
                'icon_bg',
                'icon_color',
                'btn_text',
                'btn_icon',
            ]);
        });
    }
};
