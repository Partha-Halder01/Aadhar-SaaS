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
        DB::statement("ALTER TABLE services MODIFY COLUMN category VARCHAR(100) NOT NULL DEFAULT 'print'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE services MODIFY COLUMN category ENUM('print', 'pan_find', 'document') NOT NULL DEFAULT 'print'");
    }
};
