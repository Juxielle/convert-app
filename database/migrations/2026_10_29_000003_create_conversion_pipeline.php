<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One durable mutex serializes admission, lifecycle and capacity checks on both SQLite and MySQL.
        Schema::create('conversion_locks', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('version')->default(0);
        });
        DB::table('conversion_locks')->insert(['id' => 1, 'version' => 0]);

        Schema::create('conversion_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('owner_key', 64)->index();
            $table->char('session_token_hash', 64)->nullable();
            $table->string('tool', 80);
            $table->string('operation_type', 30);
            $table->string('source_format', 12);
            $table->string('target_format', 12);
            $table->string('original_name', 160);
            $table->string('status', 16)->index();
            $table->unsignedBigInteger('queue_id')->nullable()->index();
            $table->string('input_path');
            $table->string('output_path')->nullable();
            $table->unsignedBigInteger('input_size');
            $table->unsignedBigInteger('output_size')->nullable();
            $table->unsignedBigInteger('reserved_bytes');
            $table->unsignedBigInteger('max_output_bytes');
            $table->unsignedInteger('timeout_seconds');
            $table->json('options_json');
            $table->unsignedTinyInteger('progress')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('delete_requested_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['owner_key', 'created_at']);
        });

        Schema::create('usage_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('conversion_job_id')->nullable()->unique();
            $table->foreign('conversion_job_id')->references('id')->on('conversion_jobs')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('owner_key', 64);
            $table->string('operation_type', 30);
            $table->unsignedBigInteger('bytes');
            $table->boolean('billable')->default(true);
            $table->string('status', 16);
            $table->timestamps();
            $table->index(['owner_key', 'billable', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
        Schema::dropIfExists('conversion_jobs');
        Schema::dropIfExists('conversion_locks');
    }
};
