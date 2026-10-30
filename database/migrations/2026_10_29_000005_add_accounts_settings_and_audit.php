<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('user');
            $table->string('plan', 30)->default('free');
        });
        Schema::create('tool_settings', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->json('value');
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->string('target', 100);
            $table->json('metadata');
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('tool_settings');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['role', 'plan']);
        });
    }
};
