<?php

namespace Tests\Feature;

use App\Models\ConversionJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MediaLoadTest extends TestCase
{
    public static function scenarios(): array
    {
        return ['nominal' => ['completed'], 'cancel' => ['cancelled'], 'timeout' => ['failed']];
    }

    #[DataProvider('scenarios')]
    public function test_real_workers_keep_http_responsive_and_respect_limits(string $expected): void
    {
        $directory = storage_path('framework/testing/media-load-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory);
        touch($directory.'/database.sqlite');
        $previous = config('database.connections.sqlite.database');
        $workers = [];
        try {
            config([
                'database.connections.sqlite.database' => $directory.'/database.sqlite',
                'filesystems.disks.conversions.root' => $directory.'/files',
                'conversion.media.testing_ready' => true, 'conversion.max_running_jobs' => 2,
                'conversion.media.max_running' => 1, 'conversion.limits.guest.concurrent_jobs' => 2,
                'conversion.limits.guest.storage_bytes' => 200_000_000,
                'conversion.limits.guest.timeout_seconds' => $expected === 'failed' ? 1 : 20,
            ]);
            DB::purge();
            Artisan::call('migrate', ['--force' => true]);
            (new Process([config('conversion.media.python'), base_path('tests/Support/media-fixtures.py'), config('conversion.media.ffmpeg'), $directory.'/fixtures']))->setTimeout(120)->mustRun();
            $this->withSession(['conversion_owner' => str_repeat('c', 64)]);
            $ids = [];
            for ($i = 0; $i < 2; $i++) {
                $file = UploadedFile::fake()->createWithContent('sample.mp4', file_get_contents($directory.'/fixtures/sample.mp4'));
                $ids[] = $this->postJson('/api/v1/conversions', ['tool' => 'convertir-video', 'target_format' => 'mp4', 'file' => $file])->assertStatus(202)->json('data.id');
            }
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/media-queue-worker.php'), $directory], base_path(), ['APP_ENV' => 'testing']);
                $worker->setTimeout(30)->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 20;
            $observed = false;
            $measured = false;
            $cancelled = false;
            while (microtime(true) < $deadline) {
                $jobs = ConversionJob::all();
                $this->assertLessThanOrEqual(1, $jobs->where('status', 'processing')->count());
                if ($jobs->where('status', 'processing')->count() === 1) {
                    $active = $jobs->firstWhere('status', 'processing');
                    $measured = $measured || ($active->progress > 0 && $active->progress < 100);
                    $started = microtime(true);
                    $this->getJson('/api/v1/jobs/'.$active->id)->assertOk();
                    $this->assertLessThan(1, microtime(true) - $started, 'Le suivi HTTP doit rester disponible.');
                    if (! $observed) {
                        $this->assertSame(1, $jobs->where('status', 'queued')->count());
                    }
                    if ($expected === 'cancelled' && $measured && ! $cancelled) {
                        foreach ($ids as $id) {
                            $this->postJson('/api/v1/jobs/'.$id.'/cancel')->assertSuccessful();
                        }
                        $cancelled = true;
                    }
                    $observed = true;
                }
                if ($jobs->every(fn ($job) => ! in_array($job->status, ConversionJob::ACTIVE, true))) {
                    break;
                }
                usleep(100000);
            }
            $this->assertTrue($observed, 'Un traitement réel séparé doit avoir été observé.');
            if ($expected !== 'failed') {
                $this->assertTrue($measured, 'Une progression native réelle doit avoir été observée.');
            }
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }
            foreach (ConversionJob::all() as $job) {
                $this->assertSame($expected, $job->status, $job->error_code ?? '');
                if ($expected === 'completed') {
                    $this->get('/api/v1/jobs/'.$job->id.'/download')->assertOk();
                } else {
                    $this->assertSame(0, $job->reserved_bytes);
                    $this->get('/api/v1/jobs/'.$job->id.'/download')->assertNotFound();
                    if ($expected === 'failed') {
                        $this->assertSame('PROCESS_TIMEOUT', $job->error_code);
                    }
                }
            }
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::purge();
            config(['database.connections.sqlite.database' => $previous]);
            // Only this test's unique directory within storage/framework/testing is removed.
            File::deleteDirectory($directory);
        }
    }
}
