<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('subscription_id')->unique()->constrained()->restrictOnDelete();
            $table->string('session_id')->nullable()->unique();
            $table->string('transaction_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->json('request_payload')->nullable();
            $table->string('status')->default('PENDING');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
