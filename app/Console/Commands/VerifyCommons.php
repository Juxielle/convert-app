<?php

namespace App\Console\Commands;

use App\Models\ConversionJob;
use App\Services\Conversions\PipelineException;
use App\Services\Conversions\WorkerLease;
use App\Services\External\CommonsEngine;
use App\Services\External\CommonsSource;
use App\Services\External\SourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class VerifyCommons extends Command
{
    protected $signature = 'external:verify {--url=https://commons.wikimedia.org/wiki/File:Frounhofer02.webm : Vidéo CC0 de contrôle}';

    protected $description = 'Teste les protections puis un téléchargement Commons réel avant de créer le marqueur privé.';

    public function handle(): int
    {
        $marker = base_path('.tools/commons-ready.json');
        File::delete($marker);
        if (! function_exists('curl_init') || ! preg_match('/^Convert\/1\.0 \((?:mailto:|https:\/\/)[^\r\n]{5,180}\)$/D', config('external.user_agent'))) {
            $this->error('Configurer EXTERNAL_USER_AGENT avec le contact public de l’exploitant. Voir docs/external.md.');

            return self::FAILURE;
        }
        $tests = new Process([PHP_BINARY, 'artisan', 'test', '--filter=CommonsTest|ExternalTest', '--compact'], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array']);
        $tests->setTimeout(120)->run(fn ($type, $buffer) => $this->output->write($buffer));
        if (! $tests->isSuccessful()) {
            return self::FAILURE;
        }
        $id = (string) Str::uuid();
        $directory = base_path('.cache/commons-verification-'.$id);
        $lease = null;
        try {
            File::ensureDirectoryExists($directory);
            $title = app(CommonsSource::class)->title($this->option('url'));
            File::put($directory.'/input.json', json_encode(['title' => $title]));
            $job = new ConversionJob(['id' => $id, 'target_format' => 'webm', 'timeout_seconds' => 45, 'max_output_bytes' => config('external.max_bytes')]);
            $lease = WorkerLease::acquire($id, true);
            if (! $lease) {
                throw new PipelineException('PIPELINE_UNAVAILABLE');
            }
            $started = microtime(true);
            $checkpoint = function () use ($started): void {
                if (microtime(true) - $started > 45) {
                    throw new PipelineException('PROCESS_TIMEOUT');
                }
            };
            $engine = new CommonsEngine;
            $engine->inspect($directory.'/input.json');
            $engine->convert($job, $directory.'/input.json', $directory.'/output.webm', $checkpoint);
            $engine->validateOutput($directory.'/output.webm', 'webm');
            $hashes = [];
            foreach (SourceRegistry::files() as $path) {
                $hashes[$path] = hash_file('sha256', base_path($path));
            }
            File::ensureDirectoryExists(dirname($marker));
            File::put($marker, json_encode(['hashes' => $hashes, 'verified_at' => now()->toIso8601String(), 'fixture_title' => $title,
                'python' => config('conversion.media.python'),
                'fixture_bytes' => filesize($directory.'/output.webm'), 'user_agent_hash' => hash('sha256', config('external.user_agent')),
                'ffprobe' => ['path' => config('conversion.media.ffprobe'), 'size' => filesize(config('conversion.media.ffprobe')), 'mtime' => filemtime(config('conversion.media.ffprobe'))]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('Commons CC0 vérifié : '.filesize($directory.'/output.webm').' octets téléchargés, licence et média contrôlés. Activation distincte par EXTERNAL_DOWNLOADS_ENABLED.');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('Vérification refusée : '.($error instanceof PipelineException ? $error->errorCode : 'SOURCE_INCOMPATIBLE').'. Le module reste indisponible.');

            return self::FAILURE;
        } finally {
            $lease?->release();
            // Unique verifier-owned cache directory under the project, never a user job directory.
            File::deleteDirectory($directory);
        }
    }
}
