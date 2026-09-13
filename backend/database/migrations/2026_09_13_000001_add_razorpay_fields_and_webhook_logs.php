<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add Razorpay fields to service_orders
        Schema::table('service_orders', function (Blueprint $table) {
            $table->string('payment_method')->default('wallet')->change();
            $table->string('razorpay_order_id')->nullable()->index()->after('utr_number');
            $table->string('razorpay_payment_id')->nullable()->index()->after('razorpay_order_id');
            $table->string('razorpay_signature')->nullable()->after('razorpay_payment_id');
        });

        // 2. Add Razorpay fields to wallet_transactions
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->default('manual_upi')->after('utr_number');
            $table->string('razorpay_order_id')->nullable()->index()->after('payment_method');
            $table->string('razorpay_payment_id')->nullable()->index()->after('razorpay_order_id');
            $table->string('razorpay_signature')->nullable()->after('razorpay_payment_id');
        });

        // 3. Create razorpay_webhook_logs table for audit & strict event idempotency
        Schema::create('razorpay_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique()->index();
            $table->string('event')->index();
            $table->json('payload')->nullable();
            $table->string('processed_status')->default('processed');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('razorpay_webhook_logs');

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'razorpay_order_id',
                'razorpay_payment_id',
                'razorpay_signature',
            ]);
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn([
                'razorpay_order_id',
                'razorpay_payment_id',
                'razorpay_signature',
            ]);
        });
    }
};
