<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\Subscriptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentTrialTest extends TestCase
{
    public function test_simultaneous_confirmations_are_idempotent_and_cannot_grant_two_trials(): void
    {
        foreach ([true, false] as $sameQuote) {
            $directory = storage_path('framework/testing/trials-'.bin2hex(random_bytes(8)));
            File::ensureDirectoryExists($directory);
            touch($directory.'/database.sqlite');
            $previous = config('database.connections.sqlite.database');
            $processes = [];
            try {
                config(['database.connections.sqlite.database' => $directory.'/database.sqlite']);
                DB::purge();
                Artisan::call('migrate', ['--force' => true]);
                $user = User::factory()->create();
                $first = app(Subscriptions::class)->quote($user, Plan::where('slug', 'pro')->first(), 'monthly', 'trial', 'card');
                $second = $sameQuote ? $first : app(Subscriptions::class)->quote($user, Plan::where('slug', 'business')->first(), 'yearly', 'trial', 'card');
                foreach ([$first, $second] as $index => $quote) {
                    $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent-trial.php'), $directory, (string) $index, (string) $user->id, $quote->id], base_path(), ['APP_ENV' => 'testing']);
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
                $this->assertSame(1, DB::table('trial_grants')->count());
                $this->assertSame(1, DB::table('subscriptions')->where('status', 'trialing')->count());
                if ($sameQuote) {
                    $this->assertSame($results[0], $results[1]);
                } else {
                    $this->assertContains('ineligible', $results);
                }
            } finally {
                foreach ($processes as $process) {
                    if ($process->isRunning()) {
                        $process->stop();
                    }
                }
                DB::purge();
                config(['database.connections.sqlite.database' => $previous]);
                File::deleteDirectory($directory);
            }
        }
    }
}
