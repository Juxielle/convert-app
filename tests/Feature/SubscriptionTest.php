<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanCatalog;
use App\Services\Settings;
use App\Services\Subscriptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Plan $plan): array
    {
        return [...$plan->only(['name', 'description', 'active', 'currency', 'monthly_price', 'yearly_price', 'billing_periods', 'features', 'allowed_tools', 'display_order']),
            'quotas' => $plan->is_free ? app(Settings::class)->limits('registered') : $plan->quotas];
    }

    public function test_catalog_has_one_free_plan_and_two_unpriced_unavailable_paid_plans(): void
    {
        $this->getJson('/api/v1/plans')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.id', 'free')
            ->assertJsonPath('data.0.monthly_price', 0)->assertJsonPath('data.0.guest_limits', config('conversion.limits.guest'))
            ->assertJsonPath('data.0.limits', config('conversion.limits.registered'))
            ->assertJsonPath('data.1.monthly_price', 3900)->assertJsonPath('data.1.yearly_price', 39000)
            ->assertJsonPath('data.1.available', true)->assertJsonPath('data.1.limits.monthly_jobs', 300)->assertJsonPath('data.2.status', 'trial_available');
        $this->withoutVite()->get('/tarifs')->assertOk()->assertSee('Tarifs');
        $this->withoutVite()->get('/mon-abonnement')->assertOk()->assertSee('noindex, nofollow');
    }

    public function test_registration_assigns_free_once_and_guests_use_their_session(): void
    {
        $this->getJson('/api/v1/me/subscription')->assertOk()->assertJsonPath('data.is_guest', true)->assertJsonPath('data.limits.daily_jobs', 10)->assertJsonPath('data.renews_at', null)->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v1/auth/register', ['name' => 'Alice', 'email' => 'alice@example.test', 'password' => 'A long password 123!', 'password_confirmation' => 'A long password 123!'])->assertCreated()->assertJsonPath('data.user.plan', 'free');
        $this->getJson('/api/v1/me/subscription')->assertOk()->assertJsonPath('data.is_guest', false)->assertJsonPath('data.plan.id', 'free')->assertJsonPath('data.limits.daily_jobs', 10)->assertJsonCount(1, 'data.history');
        $this->getJson('/api/v1/me/subscription')->assertOk();
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('subscription_events', 1);
        $this->assertNotNull(User::first()->current_subscription_id);
    }

    public function test_forged_paid_label_or_subscription_never_grants_paid_access(): void
    {
        $user = User::factory()->create();
        $paid = Subscription::create(['user_id' => $user->id, 'plan_id' => Plan::where('slug', 'pro')->value('id'), 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'payment_confirmed_at' => now(), 'payment_provider' => 'forged', 'provider_reference' => 'fake', 'price_amount' => 100, 'billing_period' => 'monthly']);
        $user->forceFill(['plan' => 'pro', 'current_subscription_id' => $paid->id])->save();
        $this->actingAs($user)->getJson('/api/v1/auth/session')->assertJsonPath('data.user.plan', 'free');
        $this->getJson('/api/v1/me/subscription')->assertJsonPath('data.plan.id', 'free')->assertJsonPath('data.payment_available', false);
        $this->assertSame('canceled', $paid->fresh()->status);
        $this->assertSame(config('conversion.limits.registered'), app(PlanCatalog::class)->limits($user));
    }

    public function test_expiration_falls_back_to_free_and_history_is_private(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        Subscription::find($first->current_subscription_id)->update(['ends_at' => now()->subSecond()]);
        $this->actingAs($first)->getJson('/api/v1/me/subscription')->assertOk()->assertJsonPath('data.plan.id', 'free')->assertJsonCount(3, 'data.history');
        $this->actingAs($second)->getJson('/api/v1/me/subscription')->assertOk()->assertJsonCount(1, 'data.history');
        $this->assertSame(1, Subscription::where('user_id', $first->id)->where('status', 'active')->count());
        $this->artisan('subscriptions:reconcile')->assertSuccessful();
        $this->assertSame($second->current_subscription_id, app(Subscriptions::class)->current($second)->id);
    }

    public function test_plan_management_is_admin_only_and_checkout_cannot_activate_a_plan(): void
    {
        $this->getJson('/api/v1/admin/plans')->assertUnauthorized();
        $this->postJson('/api/v1/subscriptions/checkout', ['plan' => 'pro', 'period' => 'monthly'])->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/admin/plans')->assertForbidden();
        $this->postJson('/api/v1/admin/plans', [])->assertForbidden();
        $this->patchJson('/api/v1/admin/plans/1', [])->assertForbidden();
        $this->postJson('/api/v1/subscriptions/checkout', ['plan' => 'pro', 'period' => 'monthly', 'payment_confirmed_at' => now()->toIso8601String()])->assertStatus(422);
        $this->patchJson('/api/v1/me/profile', ['name' => 'Any', 'email' => $user->email, 'current_password' => 'password', 'current_subscription_id' => 2])->assertUnprocessable();
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_admin_prices_and_features_are_persistent_but_do_not_open_checkout(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pro = Plan::where('slug', 'pro')->first();
        $payload = [...$this->payload($pro), 'monthly_price' => 1500, 'yearly_price' => 19000, 'features' => ['Fonctionnalité validée'], 'quotas' => $pro->quotas];
        $this->actingAs($admin)->patchJson('/api/v1/admin/plans/'.$pro->id, $payload)->assertOk()->assertJsonPath('data.monthly_price', 1500);
        $this->getJson('/api/v1/plans')->assertJsonPath('data.1.monthly_price', 1500)->assertJsonPath('data.1.yearly_price', 19000)->assertJsonPath('data.1.available', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'plan.updated', 'target' => 'pro']);
        $this->postJson('/api/v1/admin/plans', [...$payload, 'slug' => 'team', 'name' => 'Équipe'])->assertCreated();
        $this->patchJson('/api/v1/admin/plans/'.$pro->id, [...$payload, 'active' => false])->assertOk();
        $this->getJson('/api/v1/plans')->assertJsonCount(3, 'data');
        $this->assertSame('free', app(Subscriptions::class)->current($admin)->plan->slug);
    }

    public function test_free_quota_edits_share_existing_settings_and_are_enforced_by_admission(): void
    {
        Storage::fake('conversions');
        $admin = User::factory()->create(['role' => 'admin']);
        $free = Plan::where('slug', 'free')->first();
        $quotas = [...config('conversion.limits.registered'), 'daily_jobs' => 1, 'hourly_jobs' => 1];
        $payload = [...$this->payload($free), 'quotas' => $quotas];
        $this->actingAs($admin)->patchJson('/api/v1/admin/plans/'.$free->id, $payload)->assertOk();
        $this->getJson('/api/v1/me/usage')->assertJsonPath('data.limits.daily_jobs', 1);
        $this->getJson('/api/v1/plans')->assertJsonPath('data.0.limits.daily_jobs', 1);
        $this->getJson('/api/v1/admin/settings')->assertJsonPath('data.limits.registered.daily_jobs', 1);
        $this->postJson('/api/v1/conversions', ['tool' => 'convertir-image', 'target_format' => 'png', 'file' => UploadedFile::fake()->image('one.png', 20, 20)])->assertStatus(202);
        $this->postJson('/api/v1/conversions', ['tool' => 'convertir-image', 'target_format' => 'png', 'file' => UploadedFile::fake()->image('two.png', 20, 20)])->assertStatus(429);
        $this->patchJson('/api/v1/admin/plans/'.$free->id, [...$payload, 'allowed_tools' => []])->assertOk();
        $this->postJson('/api/v1/conversions', ['tool' => 'convertir-image', 'target_format' => 'png', 'file' => UploadedFile::fake()->image('three.png', 20, 20)])->assertStatus(403)->assertJsonPath('error.code', 'TOOL_NOT_INCLUDED');
        $this->assertDatabaseCount('conversion_jobs', 1);
    }

    public function test_free_plan_and_resource_bounds_cannot_be_bypassed_by_admin_input(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $free = Plan::where('slug', 'free')->first();
        foreach ([['active' => false], ['monthly_price' => 1], ['is_free' => false], ['currency' => 'USD'], ['quotas' => []], ['quotas' => [...config('conversion.limits.registered'), 'concurrent_jobs' => 999]], ['allowed_tools' => ['not-an-engine']]] as $changes) {
            $this->patchJson('/api/v1/admin/plans/'.$free->id, [...$this->payload($free), ...$changes])->assertUnprocessable();
        }
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame(0, $free->fresh()->monthly_price);
    }
}
