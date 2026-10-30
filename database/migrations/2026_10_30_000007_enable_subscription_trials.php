<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('trial_enabled')->default(false);
            $table->json('entitlements')->nullable();
        });
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('trial_started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable()->index();
        });
        Schema::create('trial_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('ends_at');
        });
        Schema::create('subscription_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('mode', 10);
            $table->string('period', 10);
            $table->unsignedBigInteger('amount');
            $table->unsignedSmallInteger('trial_days');
            $table->string('fingerprint', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('subscription_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('key', 30);
            $table->text('message');
            $table->timestamp('created_at');
            $table->unique(['subscription_id', 'key']);
        });
        Schema::table('conversion_jobs', function (Blueprint $table): void {
            $table->timestamp('history_expires_at')->nullable()->index();
            $table->boolean('priority')->default(false)->index();
        });
        $free = ['max_file_bytes' => 25_000_000, 'storage_bytes' => 100_000_000, 'hourly_jobs' => 10, 'daily_jobs' => 10, 'monthly_jobs' => null, 'concurrent_jobs' => 1, 'timeout_seconds' => 60, 'retention_hours' => 24, 'history_hours' => 24];
        foreach (['guest', 'registered'] as $profile) {
            DB::table('tool_settings')->where('key', 'limits.'.$profile)->update(['value' => json_encode($free), 'updated_at' => now()]);
        }
        DB::table('plans')->where('slug', 'free')->update(['features' => json_encode(['Conversions standard', 'Fichiers privés', 'Historique de 24 heures']), 'entitlements' => json_encode(['priority_processing' => false, 'advanced_conversions' => false, 'batch_processing' => false, 'priority_support' => false])]);
        foreach (['pro' => [3900, 39000, 500_000_000, 300, 3, 720], 'business' => [9900, 99000, 2_000_000_000, 1500, 10, 2160]] as $slug => [$monthly, $yearly, $bytes, $jobs, $concurrent, $history]) {
            $business = $slug === 'business';
            $quotas = ['max_file_bytes' => $bytes, 'storage_bytes' => $business ? 24_000_000_000 : 2_000_000_000, 'hourly_jobs' => $jobs, 'daily_jobs' => $jobs, 'monthly_jobs' => $jobs, 'concurrent_jobs' => $concurrent, 'timeout_seconds' => 180, 'retention_hours' => 24, 'history_hours' => $history];
            $features = ['Traitement prioritaire', 'Conversions avancées'];
            if ($business) {
                $features = [...$features, 'Traitement par lots', 'Support prioritaire'];
            }
            DB::table('plans')->where('slug', $slug)->update(['monthly_price' => $monthly, 'yearly_price' => $yearly, 'trial_days' => 15, 'trial_enabled' => true, 'billing_periods' => '["monthly","yearly"]', 'quotas' => json_encode($quotas), 'features' => json_encode($features), 'entitlements' => json_encode(['priority_processing' => true, 'advanced_conversions' => true, 'batch_processing' => $business, 'priority_support' => $business]), 'description' => $business ? 'Pour les volumes importants et le travail par lots.' : 'Pour vos conversions régulières et avancées.']);
        }
        DB::table('subscriptions')->where('status', 'cancelled')->update(['status' => 'canceled']);
        DB::table('conversion_jobs')->select(['id', 'created_at'])->orderBy('id')->chunkById(100, function ($jobs): void {
            foreach ($jobs as $job) {
                DB::table('conversion_jobs')->where('id', $job->id)->update(['history_expires_at' => Carbon::parse($job->created_at)->addDay()]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_notices');
        Schema::dropIfExists('subscription_intents');
        Schema::dropIfExists('trial_grants');
        Schema::table('conversion_jobs', fn (Blueprint $table) => $table->dropColumn(['history_expires_at', 'priority']));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn(['trial_started_at', 'trial_ends_at']));
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn(['trial_days', 'trial_enabled', 'entitlements']));
    }
};
