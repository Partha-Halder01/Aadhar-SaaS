<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OTP codes are now stored as SHA-256 hashes (64 hex characters)
     */
    public function up(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            $table->string('otp', 64)->change();
        });
    }

    public function down(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            $table->string('otp', 10)->change();
        });
    }
};
