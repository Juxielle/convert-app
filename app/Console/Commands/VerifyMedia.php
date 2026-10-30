<?php

namespace App\Console\Commands;

use App\Services\Conversions\MediaEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class VerifyMedia extends Command
{
    protected $signature = 'media:verify {--full : Exécuter aussi toute la suite PHP}';

    protected $description = 'Vérifie réellement les conversions et la concurrence avant leur activation.';

    public function handle(): int
    {
        $marker = base_path('.tools/media-ready.json');
        File::delete($marker);
        foreach (['python', 'ffmpeg', 'ffprobe'] as $name) {
            if (! is_file(config('conversion.media.'.$name))) {
                $this->error('Configurer Python, FFmpeg et ffprobe. Voir docs/media.md.');

                return self::FAILURE;
            }
        }
        $arguments = [PHP_BINARY, 'artisan', 'test', '--compact'];
        if (! $this->option('full')) {
            $arguments[] = '--filter=Media';
        }
        $process = new Process($arguments, base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
            'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array',
        ]);
        $process->setTimeout(600)->run(fn ($type, $buffer) => $this->output->write($buffer));
        if (! $process->isSuccessful()) {
            $this->error('Échec : les outils audio et vidéo restent désactivés.');

            return self::FAILURE;
        }
        $proof = [
            'tools' => MediaEngine::TOOLS,
            'worker_hash' => hash_file('sha256', base_path('bin/media-worker.py')),
            'limits_hash' => hash_file('sha256', base_path('bin/media_limits.py')),
            'python' => config('conversion.media.python'), 'verified_at' => now()->toIso8601String(),
        ];
        foreach (['ffmpeg', 'ffprobe'] as $name) {
            $path = config('conversion.media.'.$name);
            $version = new Process([$path, '-version']);
            $version->setTimeout(10)->mustRun();
            $proof[$name] = ['path' => $path, 'size' => filesize($path), 'mtime' => filemtime($path),
                'sha256' => hash_file('sha256', $path), 'version' => strtok($version->getOutput(), "\r\n")];
        }
        File::ensureDirectoryExists(dirname($marker));
        File::put($marker, json_encode($proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Les quatre outils audio et vidéo ont passé leurs tests et sont activés.');

        return self::SUCCESS;
    }
}
