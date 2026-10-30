<?php

namespace Tests\Feature;

use App\Jobs\ProcessConversion;
use App\Models\ConversionJob;
use App\Models\Plan;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Conversions\EngineRegistry;
use App\Services\Conversions\Pipeline;
use App\Services\Conversions\WorkerLease;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeEngine;
use Tests\Support\FakeEngineRegistry;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Engine-focused fixtures keep capabilities enabled; billing tests cover tier restrictions.
        Plan::where('slug', 'free')->update(['entitlements' => ['priority_processing' => false, 'advanced_conversions' => true, 'batch_processing' => true, 'priority_support' => false]]);
        Storage::fake('conversions');
        $this->app->instance(EngineRegistry::class, new FakeEngineRegistry);
        $this->withSession(['conversion_owner' => str_repeat('a', 64)]);
    }

    private function upload(string $content = 'A harmless text document for testing the queue.', string $name = 'test.txt')
    {
        return $this->postJson('/api/v1/conversions', [
            'tool' => 'pipeline-test', 'target_format' => 'txt',
            'file' => UploadedFile::fake()->createWithContent($name, $content),
        ]);
    }

    private function runJob(string $id): void
    {
        $job = ConversionJob::findOrFail($id);
        (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));
    }

    public function test_admission_is_private_queued_and_sanitized_with_real_database_queue_row(): void
    {
        $response = $this->upload(name: '../private.txt')->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $id = $response->json('data.id');
        $job = ConversionJob::findOrFail($id);
        Storage::disk('conversions')->assertExists($job->input_path);
        $this->assertStringNotContainsString('private', $job->input_path);
        $response->assertJsonMissingPath('data.input_path')->assertJsonMissingPath('data.session_token_hash');
        $this->assertNotSame(str_repeat('a', 64), $job->session_token_hash);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('conversions', DB::table('jobs')->first()->queue);
        $this->assertDatabaseCount('usage_events', 1);
        $this->getJson('/api/v1/me/usage')->assertJsonPath('data.used.active_jobs', 1)->assertJsonPath('data.used.daily_jobs', 1);
    }

    public function test_pipeline_completes_downloads_and_duplicate_delivery_is_idempotent(): void
    {
        $id = $this->upload()->assertStatus(202)->json('data.id');
        $this->runJob($id);
        $this->runJob($id);
        $this->getJson('/api/v1/jobs/'.$id)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.progress', 100);
        $download = $this->get('/api/v1/jobs/'.$id.'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertSame('A harmless text document for testing the queue.', $download->streamedContent());
        $this->assertDatabaseCount('usage_events', 1);
    }

    public function test_other_guest_and_user_cannot_access_cancel_delete_or_download_a_job(): void
    {
        $id = $this->upload()->json('data.id');
        $this->withSession(['conversion_owner' => str_repeat('b', 64)]);
        $this->getJson('/api/v1/jobs/'.$id)->assertNotFound();
        $this->postJson('/api/v1/jobs/'.$id.'/cancel')->assertNotFound();
        $this->deleteJson('/api/v1/jobs/'.$id)->assertNotFound();
        $this->getJson('/api/v1/jobs/'.$id.'/download')->assertNotFound();
        $this->getJson('/api/v1/jobs')->assertJsonCount(0, 'data');
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/jobs/'.$id)->assertNotFound();
    }

    public function test_registered_accounts_are_isolated_and_have_their_own_limits(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $id = $this->upload()->assertStatus(202)->json('data.id');
        $this->getJson('/api/v1/me/usage')->assertJsonPath('data.limits.max_file_bytes', 25_000_000);
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/jobs/'.$id)->assertNotFound();
        $this->deleteJson('/api/v1/jobs/'.$id)->assertNotFound();
        $this->actingAs($owner);
        $this->getJson('/api/v1/jobs/'.$id)->assertOk();
    }

    public function test_size_empty_mime_extension_and_arbitrary_options_are_rejected(): void
    {
        config(['conversion.limits.guest.max_file_bytes' => 10]);
        $this->upload()->assertStatus(413)->assertJsonPath('error.code', 'FILE_TOO_LARGE');
        config(['conversion.limits.guest.max_file_bytes' => 25_000_000]);
        $this->upload('')->assertStatus(422);
        $this->upload('<?php echo "unsafe";')->assertStatus(422);
        $this->upload(name: 'test.php')->assertStatus(422);
        $this->postJson('/api/v1/conversions', ['tool' => 'pipeline-test', 'target_format' => '../../php', 'file' => UploadedFile::fake()->createWithContent('test.txt', 'Harmless text')])->assertStatus(422);
        $this->postJson('/api/v1/conversions', ['tool' => 'pipeline-test', 'target_format' => 'txt', 'options' => ['command' => 'arbitrary'], 'file' => UploadedFile::fake()->createWithContent('test.txt', 'Harmless text')])->assertStatus(422);
        $this->assertDatabaseCount('conversion_jobs', 0);
        $this->assertSame([], Storage::disk('conversions')->allFiles('jobs'));
    }

    public function test_owner_global_and_storage_quotas_reserve_before_queue_and_deletion_does_not_reset_usage(): void
    {
        $id = $this->upload()->json('data.id');
        $this->upload()->assertStatus(429);
        $this->deleteJson('/api/v1/jobs/'.$id)->assertOk();
        config(['conversion.limits.guest.daily_jobs' => 1]);
        $this->upload()->assertStatus(429);
        config(['conversion.limits.guest.daily_jobs' => 10, 'conversion.max_active_jobs' => 0]);
        $this->upload()->assertStatus(503)->assertJsonPath('error.code', 'SERVER_BUSY');
        config(['conversion.max_active_jobs' => 20, 'conversion.limits.guest.storage_bytes' => 1]);
        $this->upload()->assertStatus(429)->assertJsonPath('error.code', 'STORAGE_QUOTA_EXCEEDED');
        $this->assertDatabaseCount('conversion_jobs', 0);
    }

    public function test_queue_failure_rolls_back_job_usage_reservation_and_files(): void
    {
        DB::statement('DROP TABLE jobs');
        $this->upload()->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');
        $this->assertDatabaseCount('conversion_jobs', 0);
        $this->assertDatabaseCount('usage_events', 0);
        $this->assertSame([], Storage::disk('conversions')->allFiles('jobs'));
    }

    public function test_worker_failure_refunds_once_and_removes_private_files(): void
    {
        $this->app->instance(EngineRegistry::class, new FakeEngineRegistry(new FakeEngine(fn () => throw new \RuntimeException('secret details'))));
        $id = $this->upload()->json('data.id');
        $this->runJob($id);
        $this->runJob($id);
        $this->getJson('/api/v1/jobs/'.$id)->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'ENGINE_FAILED');
        $this->getJson('/api/v1/me/usage')->assertJsonPath('data.used.daily_jobs', 0)->assertJsonPath('data.used.reserved_bytes', 0);
        $this->assertFalse(UsageEvent::first()->billable);
        $this->assertSame([], Storage::disk('conversions')->allFiles('jobs'));
    }

    public function test_cancel_queued_and_during_processing_never_publishes_result(): void
    {
        $id = $this->upload()->json('data.id');
        $this->postJson('/api/v1/jobs/'.$id.'/cancel')->assertStatus(202)->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseCount('jobs', 0);
        $this->runJob($id);
        $this->assertSame('cancelled', ConversionJob::find($id)->status);
        $this->app->instance(EngineRegistry::class, new FakeEngineRegistry(new FakeEngine(function ($job, $input, $output, $checkpoint) {
            app(Pipeline::class)->cancel($job);
            file_put_contents($output, 'This result must not be published.');
            $checkpoint();
        })));
        $second = $this->upload()->json('data.id');
        $this->runJob($second);
        $this->getJson('/api/v1/jobs/'.$second)->assertJsonPath('data.status', 'cancelled');
        $this->assertSame([], Storage::disk('conversions')->allFiles('jobs'));
    }

    public function test_expired_download_is_revoked_before_cleanup_and_reconcile_keeps_live_workers(): void
    {
        $id = $this->upload()->json('data.id');
        $this->runJob($id);
        ConversionJob::find($id)->update(['expires_at' => now()->subSecond()]);
        $this->getJson('/api/v1/jobs/'.$id.'/download')->assertNotFound();
        $live = $this->upload()->json('data.id');
        ConversionJob::find($live)->update(['status' => 'processing', 'heartbeat_at' => now(), 'expires_at' => now()->subSecond()]);
        $this->artisan('conversions:reconcile')->assertSuccessful();
        $this->assertSame('expired', ConversionJob::find($id)->status);
        $this->assertSame('processing', ConversionJob::find($live)->status);
        $this->assertGreaterThan(0, ConversionJob::find($live)->reserved_bytes);
        ConversionJob::find($live)->update(['heartbeat_at' => now()->subSeconds(301)]);
        $this->artisan('conversions:reconcile')->assertSuccessful();
        $this->assertSame('WORKER_LOST', ConversionJob::find($live)->error_code);
        $this->assertSame([], Storage::disk('conversions')->allFiles('jobs'));
    }

    public function test_production_registry_never_exposes_test_engine(): void
    {
        $this->app->instance(EngineRegistry::class, new EngineRegistry);
        $this->upload()->assertStatus(422)->assertJsonPath('error.code', 'TOOL_UNAVAILABLE');
        $this->assertDatabaseCount('conversion_jobs', 0);
    }

    public function test_a_real_database_worker_processes_the_queued_payload(): void
    {
        $id = $this->upload()->assertStatus(202)->json('data.id');
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'conversions', '--once' => true, '--tries' => 1])->assertSuccessful();
        $this->assertSame('completed', ConversionJob::find($id)->status);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_delete_during_processing_is_deferred_and_worker_cleans_before_removing_metadata(): void
    {
        $this->app->instance(EngineRegistry::class, new FakeEngineRegistry(new FakeEngine(function ($job, $input, $output, $checkpoint) {
            $result = app(Pipeline::class)->cancel($job, true);
            $this->assertTrue($result->exists);
            Storage::disk('conversions')->assertExists($job->input_path);
            $checkpoint();
        })));
        $id = $this->upload()->json('data.id');
        $this->runJob($id);
        $this->assertNull(ConversionJob::find($id));
        $this->assertDatabaseCount('usage_events', 1);
        $this->assertSame([], Storage::disk('conversions')->allFiles('jobs'));
    }

    public function test_invalid_inputs_and_outputs_fail_without_retries_and_internal_output_failures_refund(): void
    {
        $id = $this->upload('MALFORMED input')->json('data.id');
        $this->runJob($id);
        $this->assertSame('INVALID_FILE', ConversionJob::find($id)->error_code);
        $this->assertTrue(UsageEvent::where('conversion_job_id', $id)->first()->billable);
        $this->app->instance(EngineRegistry::class, new FakeEngineRegistry(new FakeEngine(function ($job, $input, $output) {
            file_put_contents($output, str_repeat('a', $job->max_output_bytes + 1));
        })));
        $id = $this->upload()->json('data.id');
        $this->runJob($id);
        $this->assertSame('INVALID_OUTPUT', ConversionJob::find($id)->error_code);
        $this->assertFalse(UsageEvent::where('conversion_job_id', $id)->first()->billable);
    }

    public function test_processing_limit_does_not_claim_another_job_and_timeout_is_enforced(): void
    {
        $first = $this->upload()->json('data.id');
        ConversionJob::find($first)->update(['status' => 'processing', 'heartbeat_at' => now()]);
        $this->withSession(['conversion_owner' => str_repeat('b', 64)]);
        $second = $this->upload()->json('data.id');
        $this->runJob($second);
        $this->assertSame('queued', ConversionJob::find($second)->status);
        app(Pipeline::class)->lock->run(fn () => app(Pipeline::class)->finish(ConversionJob::find($first), 'cancelled'));
        ConversionJob::find($second)->update(['timeout_seconds' => 0]);
        $this->runJob($second);
        $this->assertSame('PROCESS_TIMEOUT', ConversionJob::find($second)->error_code);
        $this->assertSame(0, ConversionJob::find($second)->reserved_bytes);
    }

    public function test_real_csrf_middleware_rejects_missing_token(): void
    {
        $this->app->bind(PreventRequestForgery::class,
            fn ($app) => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            });
        $this->postJson('/api/v1/conversions', [])->assertStatus(419)->assertJsonPath('error.code', 'SESSION_EXPIRED');
        $this->assertDatabaseCount('conversion_jobs', 0);
    }

    public function test_cleanup_reaps_only_old_orphans_and_counts_their_storage_before_cleanup(): void
    {
        $id = (string) Str::uuid();
        $directory = 'jobs/'.$id;
        $disk = Storage::disk('conversions');
        $disk->put($directory.'/input.txt', str_repeat('x', 500));
        config(['conversion.global_storage_bytes' => 600]);
        $this->upload()->assertStatus(503);
        $this->artisan('conversions:reconcile')->assertSuccessful();
        $disk->assertExists($directory.'/input.txt');
        touch($disk->path($directory), now()->subMinutes(11)->timestamp);
        $this->artisan('conversions:reconcile')->assertSuccessful();
        $disk->assertMissing($directory.'/input.txt');
        $this->upload()->assertStatus(202);
    }

    public function test_hourly_window_and_abandoned_queue_refund_and_failed_hook(): void
    {
        config(['conversion.limits.guest.hourly_jobs' => 1]);
        $id = $this->upload()->json('data.id');
        $this->postJson('/api/v1/jobs/'.$id.'/cancel')->assertStatus(202);
        $this->upload()->assertStatus(429);
        UsageEvent::query()->update(['created_at' => now()->subMinutes(61)]);
        $id = $this->upload()->assertStatus(202)->json('data.id');
        ConversionJob::find($id)->update(['created_at' => now()->subMinutes(31)]);
        $this->artisan('conversions:reconcile')->assertSuccessful();
        $this->assertSame('QUEUE_ABANDONED', ConversionJob::find($id)->error_code);
        $this->assertFalse(UsageEvent::where('conversion_job_id', $id)->first()->billable);
        $id = $this->upload()->assertStatus(202)->json('data.id');
        $queued = ConversionJob::find($id);
        (new ProcessConversion($id, $queued->expires_at->timestamp))->failed(new \RuntimeException('private message'));
        $this->assertSame('WORKER_FAILED', ConversionJob::find($id)->error_code);
        $this->assertSame(0, ConversionJob::find($id)->reserved_bytes);
    }

    public function test_a_stale_heartbeat_with_a_live_process_lease_is_never_purged(): void
    {
        $id = $this->upload()->json('data.id');
        $job = ConversionJob::find($id);
        $job->update(['status' => 'processing', 'heartbeat_at' => now()->subMinutes(10)]);
        $lease = WorkerLease::acquire($id);
        try {
            $this->artisan('conversions:reconcile')->assertSuccessful();
            $this->assertSame('processing', $job->fresh()->status);
            Storage::disk('conversions')->assertExists($job->input_path);
        } finally {
            $lease->release();
        }
        $this->artisan('conversions:reconcile')->assertSuccessful();
        $this->assertSame('failed', $job->fresh()->status);
        Storage::disk('conversions')->assertMissing($job->input_path);
    }

    public function test_session_endpoints_are_not_cors_readable_or_cacheable_by_another_origin(): void
    {
        $this->withHeaders(['Origin' => 'https://untrusted.example'])->getJson('/api/v1/me/usage')
            ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin')->assertHeaderMissing('Access-Control-Allow-Credentials')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_cleanup_preserves_live_private_staging_then_reaps_an_abandoned_file(): void
    {
        $id = (string) Str::uuid();
        $disk = Storage::disk('conversions');
        $path = 'staging/'.$id.'.zip';
        $disk->put($path, 'synthetic staging');
        touch($disk->path($path), now()->subMinutes(11)->timestamp);
        $lease = WorkerLease::acquire($id, true);
        try {
            $this->artisan('conversions:reconcile')->assertSuccessful();
            $disk->assertExists($path);
        } finally {
            $lease->release();
        }
        $this->artisan('conversions:reconcile')->assertSuccessful();
        $disk->assertMissing($path);
    }
}
