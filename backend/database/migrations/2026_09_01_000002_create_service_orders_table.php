<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique()->index();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('service_id')->constrained()->onDelete('cascade');
            $table->json('input_data')->nullable();
            $table->decimal('amount', 10, 2);
            $table->enum('payment_method', ['wallet', 'direct_upi'])->default('wallet');
            $table->enum('payment_status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->enum('order_status', ['pending', 'processing', 'completed', 'rejected'])->default('pending')->index();
            $table->text('rejection_reason')->nullable();
            $table->string('payment_proof_image')->nullable();
            $table->string('utr_number')->nullable()->index();
            $table->string('delivery_file')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_orders');
    }
};
