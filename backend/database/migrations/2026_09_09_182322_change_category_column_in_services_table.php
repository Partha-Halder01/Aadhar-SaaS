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
        if (DB::getDriverName() !== 'mysql') {
            // SQLite (used by the test suite) has no MODIFY COLUMN
            Schema::table('services', function (Blueprint $table) {
                $table->string('category', 100)->default('print')->change();
            });
            return;
        }

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
