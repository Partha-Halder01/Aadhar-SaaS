<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_no')->unique()->index();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->onDelete('set null');
            $table->string('order_number')->nullable();
            $table->string('subject');
            $table->text('message');
            $table->enum('status', ['open', 'in_progress', 'resolved'])->default('open')->index();
            $table->text('admin_reply')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
