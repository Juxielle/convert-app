<?php

namespace Tests\Feature;

use App\Jobs\ProcessConversion;
use App\Models\ConversionJob;
use App\Models\ToolSetting;
use App\Services\Conversions\EngineRegistry;
use App\Services\Conversions\Pipeline;
use App\Services\External\CommonsSource;
use App\Services\External\PinnedTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CommonsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['external.testing_ready' => true, 'external.enabled' => true, 'external.user_agent' => 'OfflineTests/1.0']);
        Storage::fake('conversions');
        $this->withSession(['conversion_owner' => str_repeat('d', 64)]);
        if (! is_file(base_path('.cache/media-fixtures/sample.webm'))) {
            (new Process([config('conversion.media.python'), base_path('tests/Support/media-fixtures.py'), config('conversion.media.ffmpeg'), base_path('.cache/media-fixtures')]))->setTimeout(120)->mustRun();
        }
        $this->fakeTransport();
    }

    private function fakeTransport(string $license = 'https://creativecommons.org/publicdomain/zero/1.0/'): void
    {
        $this->app->instance(PinnedTransport::class, new class($license) extends PinnedTransport
        {
            public function __construct(private string $license) {}

            public function fetch(string $url, array $domains, int $maxBytes, callable $checkpoint, $stream = null): string
            {
                $checkpoint();
                $bytes = file_get_contents(base_path('.cache/media-fixtures/sample.webm'));
                if (str_contains($url, '/w/api.php?')) {
                    return json_encode(['query' => ['pages' => [['imageinfo' => [['mime' => 'video/webm', 'size' => strlen($bytes), 'url' => 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Sample.webm', 'extmetadata' => ['LicenseUrl' => ['value' => $this->license], 'Artist' => ['value' => '<b>Test creator</b>']]]]]]]]);
                }
                fwrite($stream, $bytes);
                if (config('external.testing_cancel')) {
                    $job = ConversionJob::where('status', 'processing')->firstOrFail();
                    app(Pipeline::class)->cancel($job);
                    $checkpoint();
                }

                return '';
            }
        });
    }

    public function test_commons_is_a_separate_queued_download_with_private_result_and_plain_attribution(): void
    {
        $response = $this->postJson('/api/v1/social-downloads', ['url' => 'https://commons.wikimedia.org/wiki/File:Sample.webm', 'rights_confirmed' => true])->assertStatus(202);
        $id = $response->json('data.id');
        $job = ConversionJob::findOrFail($id);
        $this->assertSame('social_download', $job->operation_type);
        $this->getJson('/api/v1/me/usage')->assertJsonPath('data.used.daily_jobs', 0)->assertJsonPath('data.external_used.daily_jobs', 1);
        (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));
        $this->getJson('/api/v1/jobs/'.$id)->assertJsonPath('data.status', 'completed')->assertJsonPath('data.attribution.artist', 'Test creator')->assertJsonPath('data.attribution.license', 'CC0 1.0');
        $this->get('/api/v1/jobs/'.$id.'/download')->assertOk();
        $this->withSession(['conversion_owner' => str_repeat('b', 64)])->get('/api/v1/jobs/'.$id.'/download')->assertNotFound();
    }

    public function test_license_refusal_does_not_publish_a_partial_file_and_external_quota_is_separate(): void
    {
        $this->fakeTransport('https://creativecommons.org/licenses/by/4.0/');
        $id = $this->postJson('/api/v1/social-downloads', ['url' => 'https://commons.wikimedia.org/wiki/File:Sample.webm', 'rights_confirmed' => true])->assertStatus(202)->json('data.id');
        $job = ConversionJob::findOrFail($id);
        (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));
        $this->assertSame('LICENSE_NOT_ALLOWED', $job->fresh()->error_code);
        $this->assertSame(0, $job->fresh()->reserved_bytes);
        config(['external.hourly_jobs' => 1]);
        $this->postJson('/api/v1/social-downloads', ['url' => 'https://commons.wikimedia.org/wiki/File:Sample.webm', 'rights_confirmed' => true])->assertStatus(429);
        $this->getJson('/api/v1/me/usage')->assertJsonPath('data.used.daily_jobs', 0);
    }

    public function test_untrusted_urls_and_raw_manifest_uploads_are_rejected(): void
    {
        foreach (['http://commons.wikimedia.org/wiki/File:A.webm', 'https://commons.wikimedia.org.evil.test/wiki/File:A.webm', 'https://commons.wikimedia.org/wiki/File:A.webm?token=secret', 'https://commons.wikimedia.org/wiki/File:%2Fetc.webm', 'https://commons.wikimedia.org/wiki/File:A.webm#fragment'] as $url) {
            $this->postJson('/api/v1/social-downloads', ['url' => $url, 'rights_confirmed' => true])->assertStatus(422);
        }
        $this->postJson('/api/v1/conversions', ['tool' => 'telechargement-autorise', 'target_format' => 'webm'])->assertStatus(422);
        $this->assertSame('File:A name.webm', app(CommonsSource::class)->title('https://commons.wikimedia.org/wiki/File:A_name.webm'));
        $this->assertDatabaseCount('conversion_jobs', 0);
    }

    public function test_cancellation_during_fetch_removes_the_partial_private_result(): void
    {
        config(['external.testing_cancel' => true]);
        $id = $this->postJson('/api/v1/social-downloads', ['url' => 'https://commons.wikimedia.org/wiki/File:Sample.webm', 'rights_confirmed' => true])->assertStatus(202)->json('data.id');
        $job = ConversionJob::findOrFail($id);
        (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));
        $this->assertSame('cancelled', $job->fresh()->status);
        $this->assertSame(0, $job->fresh()->reserved_bytes);
        Storage::disk('conversions')->assertMissing('jobs/'.$id.'/output.webm');
        $this->get('/api/v1/jobs/'.$id.'/download')->assertNotFound();
    }

    public function test_administrator_kill_switch_does_not_bypass_source_verification(): void
    {
        ToolSetting::create(['key' => 'tools.telechargement-autorise.enabled', 'value' => false]);
        $this->getJson('/api/v1/social-downloads/sources')->assertJsonPath('data.enabled', false);
        $this->postJson('/api/v1/social-downloads', ['url' => 'https://commons.wikimedia.org/wiki/File:Sample.webm', 'rights_confirmed' => true])->assertStatus(503);
        $this->assertDatabaseCount('conversion_jobs', 0);
    }

    public function test_external_original_size_respects_the_role_file_cap(): void
    {
        config(['conversion.limits.guest.max_file_bytes' => 1000]);
        $id = $this->postJson('/api/v1/social-downloads', ['url' => 'https://commons.wikimedia.org/wiki/File:Sample.webm', 'rights_confirmed' => true])->assertStatus(202)->json('data.id');
        $job = ConversionJob::findOrFail($id);
        $this->assertSame(1000, $job->max_output_bytes);
        (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));
        $this->assertSame('FILE_TOO_LARGE', $job->fresh()->error_code);
        $this->assertSame(0, $job->fresh()->reserved_bytes);
    }
}
