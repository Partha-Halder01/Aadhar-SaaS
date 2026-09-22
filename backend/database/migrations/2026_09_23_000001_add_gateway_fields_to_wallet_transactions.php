<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Generic gateway references for non-Razorpay wallet top-ups (e.g. AllAPI UPI)
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->string('gateway_order_id')->nullable()->unique()->after('razorpay_signature');
            $table->string('gateway_reference')->nullable()->index()->after('gateway_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropUnique(['gateway_order_id']);
            $table->dropIndex(['gateway_reference']);
            $table->dropColumn(['gateway_order_id', 'gateway_reference']);
        });
    }
};
