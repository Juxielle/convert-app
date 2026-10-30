<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 100);
            $table->text('description');
            $table->boolean('is_free')->default(false);
            $table->boolean('active')->default(true);
            $table->string('currency', 3)->default('XAF');
            // Whole FCFA amounts. Null means unvalidated, never a free trial.
            $table->unsignedBigInteger('monthly_price')->nullable();
            $table->unsignedBigInteger('yearly_price')->nullable();
            $table->json('billing_periods');
            $table->json('features');
            $table->json('quotas')->nullable();
            $table->json('allowed_tools')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('renews_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('billing_period', 10)->nullable();
            $table->unsignedBigInteger('price_amount')->nullable();
            $table->string('currency', 3)->default('XAF');
            $table->string('payment_provider', 60)->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestamp('payment_confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->unique(['payment_provider', 'provider_reference']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->foreignId('current_subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete());
        Schema::create('subscription_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);
            $table->json('metadata');
            $table->timestamp('created_at');
        });
        $now = now();
        $freeId = DB::table('plans')->insertGetId([
            'slug' => 'free', 'name' => 'Gratuit', 'description' => 'Découvrez les conversions et extractions, sans carte bancaire.',
            'is_free' => true, 'active' => true, 'currency' => 'XAF', 'monthly_price' => 0, 'yearly_price' => 0,
            'billing_periods' => '[]', 'features' => json_encode(['Conversions et extractions disponibles', 'Téléchargements privés', 'Retouches manuelles des images']),
            'display_order' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach (['pro' => 'Pro', 'business' => 'Business'] as $slug => $name) {
            DB::table('plans')->insert([
                'slug' => $slug, 'name' => $name, 'description' => 'Une formule en préparation. Tarifs, fonctionnalités et quotas à définir.',
                'is_free' => false, 'active' => true, 'currency' => 'XAF', 'billing_periods' => '["monthly","yearly"]',
                'features' => '[]', 'display_order' => $slug === 'pro' ? 10 : 20, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        // Legacy plan labels have never been proof of payment.
        DB::table('users')->orderBy('id')->chunkById(100, function ($users) use ($freeId, $now): void {
            foreach ($users as $user) {
                $id = DB::table('subscriptions')->insertGetId(['user_id' => $user->id, 'plan_id' => $freeId, 'status' => 'active', 'starts_at' => $now, 'price_amount' => 0, 'currency' => 'XAF', 'created_at' => $now, 'updated_at' => $now]);
                DB::table('users')->where('id', $user->id)->update(['current_subscription_id' => $id, 'plan' => 'free']);
                DB::table('subscription_events')->insert(['subscription_id' => $id, 'type' => 'free_assigned', 'metadata' => json_encode(['reason' => 'migration', 'plan' => 'Gratuit']), 'created_at' => $now]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_subscription_id'));
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
