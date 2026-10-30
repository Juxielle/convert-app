<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentAdmissionTest extends TestCase
{
    public static function scenarios(): array
    {
        return ['owner slot' => ['owner', 'QUOTA_EXCEEDED'], 'global slot' => ['global', 'SERVER_BUSY'], 'global storage' => ['storage', 'SERVER_BUSY']];
    }

    #[DataProvider('scenarios')]
    public function test_two_independent_processes_cannot_overreserve_capacity(string $scenario, string $error): void
    {
        $directory = storage_path('framework/testing/concurrency-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory);
        touch($directory.'/database.sqlite');
        $previous = config('database.connections.sqlite.database');
        $processes = [];
        try {
            config(['database.connections.sqlite.database' => $directory.'/database.sqlite']);
            DB::purge();
            Artisan::call('migrate', ['--force' => true]);
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent-admission.php'), $directory, (string) $i, $scenario], base_path(), ['APP_ENV' => 'testing']);
                $process->setTimeout(20)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while ((! file_exists($directory.'/ready-0') || ! file_exists($directory.'/ready-1')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($directory.'/ready-0');
            $this->assertFileExists($directory.'/ready-1');
            file_put_contents($directory.'/go', 'go');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = trim($process->getOutput());
            }
            sort($results);
            $this->assertSame([$error, 'admitted'], $results);
            $this->assertSame(1, DB::table('conversion_jobs')->count());
            $this->assertSame(1, DB::table('usage_events')->count());
            $this->assertSame(1, DB::table('jobs')->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            DB::purge();
            config(['database.connections.sqlite.database' => $previous]);
            // Only the unique test directory created above is removed.
            File::deleteDirectory($directory);
        }
    }
}
