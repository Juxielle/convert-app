<?php

namespace Tests\Feature;

use App\Jobs\ProcessConversion;
use App\Models\ConversionJob;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TrialGrant;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Conversions\EngineRegistry;
use App\Services\Conversions\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEngineRegistry;
use Tests\TestCase;

class TrialSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Exercise the existing trial lifecycle independently of provider setup.
        config(['billing.trial_requires_payment' => false]);
    }

    private function quote(string $plan = 'pro', string $mode = 'trial', string $period = 'monthly'): string
    {
        return $this->postJson('/api/v1/subscriptions/quote', [...compact('plan', 'mode', 'period'), 'payment_method' => 'card'])->assertOk()->json('data.id');
    }

    public function test_payment_methods_are_validated_and_kept_pending_without_sensitive_data(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->getJson('/api/v1/payment-methods')->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.methods.0.id', 'card')->assertJsonPath('data.methods.1.id', 'mobile_money');
        $this->actingAs(User::factory()->create());
        $payload = ['plan' => 'pro', 'mode' => 'pay', 'period' => 'monthly'];
        $this->postJson('/api/v1/subscriptions/quote', $payload)->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['payment_method']]]);
        $this->postJson('/api/v1/subscriptions/quote', [...$payload, 'payment_method' => 'unknown'])->assertUnprocessable();
        foreach (['card', 'mobile_money'] as $method) {
            $quote = $this->postJson('/api/v1/subscriptions/quote', [...$payload, 'payment_method' => $method])->assertOk()->assertJsonPath('data.payment_method', $method)->json('data.id');
            $this->postJson('/api/v1/subscriptions/checkout', ['quote_id' => $quote, 'confirmed' => true, 'cvv' => 'sensitive-value'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['cvv']]]);
            $response = $this->postJson('/api/v1/subscriptions/checkout', ['quote_id' => $quote, 'confirmed' => true])->assertStatus(202)->assertJsonPath('data.payment_method', $method)->assertJsonPath('data.status', 'pending_payment')->assertJsonPath('data.charged_amount', 0);
            $this->assertDatabaseHas('subscriptions', ['id' => $response->json('data.id'), 'payment_method' => $method, 'payment_confirmed_at' => null]);
        }
        $this->postJson('/api/v1/subscriptions/quote', [...$payload, 'payment_method' => 'card', 'card_number' => 'sensitive-value'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['card_number']]]);
        $this->postJson('/api/v1/subscriptions/quote', [...$payload, 'mode' => 'trial'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['payment_method']]]);
    }

    public function test_prices_limits_and_annual_savings_are_exact_and_backend_driven(): void
    {
        $this->getJson('/api/v1/plans')->assertOk()->assertJsonPath('data.0.limits.max_file_bytes', 25_000_000)->assertJsonPath('data.0.limits.daily_jobs', 10)
            ->assertJsonPath('data.0.limits.history_hours', 24)->assertJsonPath('data.1.monthly_price', 3900)->assertJsonPath('data.1.yearly_price', 39000)
            ->assertJsonPath('data.1.annual_savings', 7800)->assertJsonPath('data.1.annual_free_months', 2)
            ->assertJsonPath('data.1.limits.monthly_jobs', 300)->assertJsonPath('data.1.limits.concurrent_jobs', 3)
            ->assertJsonPath('data.2.monthly_price', 9900)->assertJsonPath('data.2.yearly_price', 99000)->assertJsonPath('data.2.limits.max_file_bytes', 2_000_000_000)
            ->assertJsonPath('data.2.limits.monthly_jobs', 1500)->assertJsonPath('data.2.limits.concurrent_jobs', 10)->assertJsonPath('data.2.limits.history_hours', 2160);
    }

    public function test_confirmation_grants_trial_immediately_once_without_a_payment(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create();
        $this->actingAs($user);
        $id = $this->quote();
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $id, 'confirmed' => false])->assertUnprocessable();
        $response = $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $id, 'confirmed' => true])->assertOk()->assertJsonPath('data.status', 'trialing')->assertJsonPath('data.charged_amount', 0);
        $subscription = Subscription::findOrFail($response->json('data.id'));
        $this->assertTrue($subscription->trial_ends_at->equalTo(now()->addDays(15)));
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $id, 'confirmed' => true])->assertOk()->assertJsonPath('data.id', $subscription->id);
        $this->assertDatabaseCount('trial_grants', 1);
        $this->assertDatabaseCount('subscriptions', 2);
        $this->assertNull($subscription->payment_confirmed_at);
        $this->getJson('/api/v1/me/subscription')->assertJsonPath('data.trial_remaining_seconds', 15 * 86400)->assertJsonPath('data.trial_eligible', false)->assertJsonPath('data.plan.id', 'pro');
        $this->getJson('/api/v1/me/usage')->assertJsonPath('data.limits.max_file_bytes', 500_000_000)->assertJsonPath('data.limits.monthly_jobs', 300)->assertJsonPath('data.entitlements.advanced_conversions', true);
    }

    public function test_trial_cannot_be_restarted_or_changed_to_another_plan_and_period_does_not_extend_it(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->actingAs(User::factory()->create());
        $id = $this->quote();
        $other = $this->quote('business');
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $id, 'confirmed' => true])->assertOk();
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $other, 'confirmed' => true])->assertUnprocessable();
        $end = TrialGrant::first()->ends_at;
        $this->patchJson('/api/v1/subscriptions/period', ['period' => 'yearly', 'expected_amount' => 1, 'confirmed' => true])->assertUnprocessable();
        $this->patchJson('/api/v1/subscriptions/period', ['period' => 'yearly', 'expected_amount' => 39000, 'confirmed' => true])->assertOk();
        $this->assertTrue(Subscription::where('status', 'trialing')->first()->trial_ends_at->equalTo($end));
        $this->postJson('/api/v1/subscriptions/cancel-trial', ['confirmed' => true])->assertOk()->assertJsonPath('data.plan.id', 'free');
        $this->postJson('/api/v1/subscriptions/quote', ['plan' => 'business', 'mode' => 'trial', 'period' => 'monthly'])->assertUnprocessable();
        $this->assertDatabaseCount('trial_grants', 1);
    }

    public function test_reminders_are_once_at_j3_and_j1_and_exact_expiration_returns_to_free(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create();
        $this->actingAs($user);
        $id = $this->quote('business');
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $id, 'confirmed' => true])->assertOk();
        $this->travel(12)->days();
        $this->artisan('subscriptions:reconcile')->assertSuccessful();
        $this->artisan('subscriptions:reconcile')->assertSuccessful();
        $this->assertDatabaseCount('subscription_notices', 1);
        $this->getJson('/api/v1/me/subscription')->assertJsonCount(1, 'data.notices');
        $this->travel(2)->days();
        $this->artisan('subscriptions:reconcile')->assertSuccessful();
        $this->assertDatabaseCount('subscription_notices', 2);
        $this->travel(1)->days();
        $this->getJson('/api/v1/me/subscription')->assertJsonPath('data.plan.id', 'free')->assertJsonPath('data.status', 'active')->assertJsonPath('data.trial_eligible', false);
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'status' => 'expired']);
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $id, 'confirmed' => true])->assertJsonPath('data.status', 'expired');
        $this->getJson('/api/v1/me/usage')->assertJsonPath('data.limits.max_file_bytes', 25_000_000);
    }

    public function test_pending_payment_preserves_the_current_trial_and_cannot_be_used_as_confirmation(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->quote();
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $id, 'confirmed' => true])->assertOk();
        $pay = $this->quote('business', 'pay', 'yearly');
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $pay, 'confirmed' => true])->assertStatus(422);
        $response = $this->postJson('/api/v1/subscriptions/checkout', ['quote_id' => $pay, 'confirmed' => true])->assertStatus(202)->assertJsonPath('data.status', 'pending_payment')->assertJsonPath('data.charged_amount', 0);
        $this->postJson('/api/v1/subscriptions/checkout', ['quote_id' => $pay, 'confirmed' => true])->assertJsonPath('data.id', $response->json('data.id'));
        $this->getJson('/api/v1/me/subscription')->assertJsonPath('data.plan.id', 'pro')->assertJsonPath('data.status', 'trialing')->assertJsonCount(1, 'data.pending_payments');
    }

    public function test_quotes_are_owned_expiring_and_invalidated_when_admin_changes_conditions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);
        $id = $this->quote();
        $this->actingAs($other)->postJson('/api/v1/subscriptions/trial', ['quote_id' => $id, 'confirmed' => true])->assertNotFound();
        $this->actingAs($user);
        Plan::where('slug', 'pro')->update(['monthly_price' => 4000]);
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $id, 'confirmed' => true])->assertUnprocessable();
        $id = $this->quote();
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $id, 'confirmed' => true])->assertUnprocessable();
        $this->assertDatabaseCount('trial_grants', 0);
    }

    public function test_monthly_quota_priority_and_batch_entitlements_are_enforced_by_real_admission(): void
    {
        Storage::fake('conversions');
        $this->app->instance(EngineRegistry::class, new FakeEngineRegistry);
        $user = User::factory()->create();
        $this->actingAs($user);
        $id = $this->quote();
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $id, 'confirmed' => true])->assertOk();
        $files = fn () => [UploadedFile::fake()->createWithContent('one.txt', 'content one'), UploadedFile::fake()->createWithContent('two.txt', 'content two')];
        $this->postJson('/api/v1/conversions', ['tool' => 'pipeline-test', 'target_format' => 'txt', 'files' => $files()])->assertStatus(403);
        $this->postJson('/api/v1/conversions', ['tool' => 'pipeline-test', 'target_format' => 'txt', 'file' => $files()[0]])->assertStatus(202);
        $this->assertTrue(ConversionJob::first()->priority);
        $this->assertEqualsWithDelta(720, now()->diffInHours(ConversionJob::first()->history_expires_at), .01);
        UsageEvent::insert(array_fill(0, 299, ['user_id' => $user->id, 'owner_key' => hash('sha256', 'user:'.$user->id), 'operation_type' => 'image', 'bytes' => 1, 'status' => 'completed', 'billable' => true, 'created_at' => now()->subDays(2), 'updated_at' => now()]));
        $this->postJson('/api/v1/conversions', ['tool' => 'pipeline-test', 'target_format' => 'txt', 'file' => $files()[0]])->assertStatus(429);
        $this->patchJson('/api/v1/subscriptions/period', ['period' => 'yearly', 'expected_amount' => 39000, 'confirmed' => true])->assertOk();
        $this->getJson('/api/v1/me/usage')->assertJsonPath('data.used.monthly_jobs', 300)->assertJsonPath('data.quota_used', 300);
        $this->actingAs(User::factory()->create());
        $id = $this->quote('business');
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $id, 'confirmed' => true])->assertOk();
        $this->postJson('/api/v1/conversions', ['tool' => 'pipeline-test', 'target_format' => 'txt', 'files' => $files()])->assertStatus(202);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/conversions', ['tool' => 'pipeline-test', 'target_format' => 'txt', 'files' => $files()])->assertStatus(202);
        }
        $this->getJson('/api/v1/me/usage')->assertJsonPath('data.used.active_jobs', 10);
        $this->postJson('/api/v1/conversions', ['tool' => 'pipeline-test', 'target_format' => 'txt', 'file' => $files()[0]])->assertStatus(429);
    }

    public function test_guest_and_forged_trial_are_denied_and_admin_views_are_protected(): void
    {
        $this->postJson('/api/v1/subscriptions/trial', [])->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/admin/subscriptions?status=trialing')->assertForbidden();
        $trial = Subscription::create(['user_id' => $user->id, 'plan_id' => Plan::where('slug', 'pro')->value('id'), 'status' => 'trialing', 'starts_at' => now(), 'ends_at' => now()->addDays(15), 'trial_ends_at' => now()->addDays(15)]);
        $user->forceFill(['current_subscription_id' => $trial->id])->save();
        $this->getJson('/api/v1/me/subscription')->assertJsonPath('data.plan.id', 'free');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/v1/admin/subscriptions?status=canceled')->assertOk();
    }

    public function test_history_window_changes_without_extending_previously_expired_history(): void
    {
        Storage::fake('conversions');
        $this->app->instance(EngineRegistry::class, new FakeEngineRegistry);
        $this->actingAs(User::factory()->create());
        $quote = $this->quote('business');
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $quote, 'confirmed' => true])->assertOk();
        $id = $this->postJson('/api/v1/conversions', ['tool' => 'pipeline-test', 'target_format' => 'txt', 'file' => UploadedFile::fake()->createWithContent('history.txt', 'history fixture')])->assertStatus(202)->json('data.id');
        ConversionJob::find($id)->update(['created_at' => now()->subDays(35)]);
        $this->getJson('/api/v1/jobs')->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/subscriptions/cancel-trial', ['confirmed' => true])->assertOk();
        $this->getJson('/api/v1/jobs')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/jobs/'.$id)->assertNotFound();
    }

    public function test_priority_actually_defers_a_recent_standard_job_until_priority_work_is_claimed(): void
    {
        Storage::fake('conversions');
        $registry = new FakeEngineRegistry;
        $this->app->instance(EngineRegistry::class, $registry);
        $this->actingAs(User::factory()->create());
        $normal = $this->postJson('/api/v1/conversions', ['tool' => 'pipeline-test', 'target_format' => 'txt', 'file' => UploadedFile::fake()->createWithContent('normal.txt', 'normal fixture')])->assertStatus(202)->json('data.id');
        $this->actingAs(User::factory()->create());
        $quote = $this->quote();
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $quote, 'confirmed' => true])->assertOk();
        $priority = $this->postJson('/api/v1/conversions', ['tool' => 'pipeline-test', 'target_format' => 'txt', 'file' => UploadedFile::fake()->createWithContent('priority.txt', 'priority fixture')])->assertStatus(202)->json('data.id');
        $run = function ($id) use ($registry): void {
            $job = ConversionJob::findOrFail($id);
            (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), $registry);
        };
        $run($normal);
        $this->assertSame('queued', ConversionJob::find($normal)->status);
        $run($priority);
        $this->assertSame('completed', ConversionJob::find($priority)->status);
        $run($normal);
        $this->assertSame('completed', ConversionJob::find($normal)->status);
    }

    public function test_admin_can_change_trial_duration_without_extending_an_existing_grant(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create();
        $this->actingAs($user);
        $quote = $this->quote();
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $quote, 'confirmed' => true])->assertOk();
        $plan = Plan::where('slug', 'pro')->first();
        $values = $plan->only(['name', 'description', 'active', 'currency', 'monthly_price', 'yearly_price', 'billing_periods', 'features', 'allowed_tools', 'display_order', 'quotas', 'entitlements']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patchJson('/api/v1/admin/plans/'.$plan->id, [...$values, 'trial_days' => 7, 'trial_enabled' => true])->assertOk();
        $this->actingAs($user)->getJson('/api/v1/me/subscription')->assertJsonPath('data.trial_remaining_seconds', 15 * 86400);
        $this->actingAs(User::factory()->create());
        $quote = $this->quote();
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $quote, 'confirmed' => true])->assertOk();
        $this->getJson('/api/v1/me/subscription')->assertJsonPath('data.trial_remaining_seconds', 7 * 86400);
    }

    public function test_extended_media_input_is_refused_on_free_and_really_converted_during_pro_trial(): void
    {
        Storage::fake('conversions');
        config(['conversion.media.testing_ready' => true]);
        Storage::disk('conversions')->makeDirectory('fixtures');
        $path = Storage::disk('conversions')->path('fixtures/large.wav');
        $bytes = 26_000_000;
        $handle = fopen($path, 'wb');
        fwrite($handle, 'RIFF'.pack('V', $bytes + 36).'WAVEfmt '.pack('VvvVVvv', 16, 1, 2, 44100, 176400, 4, 16).'data'.pack('V', $bytes));
        $remaining = $bytes;
        while ($remaining > 0) {
            $chunk = min($remaining, 65536);
            fwrite($handle, str_repeat("\0", $chunk));
            $remaining -= $chunk;
        }
        fclose($handle);
        $file = new UploadedFile($path, 'large.wav', 'audio/wav', UPLOAD_ERR_OK, true);
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/conversions', ['tool' => 'convertir-audio', 'target_format' => 'mp3', 'file' => $file])->assertStatus(413);
        $quote = $this->quote();
        $this->postJson('/api/v1/subscriptions/trial', ['quote_id' => $quote, 'confirmed' => true])->assertOk();
        $id = $this->postJson('/api/v1/conversions', ['tool' => 'convertir-audio', 'target_format' => 'mp3', 'file' => $file])->assertStatus(202)->json('data.id');
        $job = ConversionJob::findOrFail($id);
        (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));
        $this->assertSame('completed', $job->fresh()->status, $job->fresh()->error_code ?? '');
    }
}
