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
        Schema::table('service_orders', function (Blueprint $table) {
            $table->string('doc_request_title')->nullable()->after('rejection_reason');
            $table->text('doc_request_message')->nullable()->after('doc_request_title');
            $table->string('doc_request_status')->default('none')->index()->after('doc_request_message'); // none, pending, submitted
            $table->timestamp('doc_request_requested_at')->nullable()->after('doc_request_status');
            $table->string('doc_response_file')->nullable()->after('doc_request_requested_at');
            $table->text('doc_response_notes')->nullable()->after('doc_response_file');
            $table->timestamp('doc_response_submitted_at')->nullable()->after('doc_response_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn([
                'doc_request_title',
                'doc_request_message',
                'doc_request_status',
                'doc_request_requested_at',
                'doc_response_file',
                'doc_response_notes',
                'doc_response_submitted_at',
            ]);
        });
    }
};
