<?php

namespace App\Console\Commands;

use App\Services\Conversions\RecognitionEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class VerifyRecognition extends Command
{
    protected $signature = 'recognition:verify';

    protected $description = 'Vérifie l’OCR et les transcriptions natives avant activation.';

    public function handle(): int
    {
        $marker = base_path('.tools/recognition-ready.json');
        File::delete($marker);
        $assets = [];
        foreach (RecognitionEngine::TOOLS as $tool) {
            foreach (RecognitionEngine::assets($tool) as $name => $path) {
                if (! is_file($path)) {
                    $this->error('Moteur ou modèle absent. Voir docs/recognition.md.');

                    return self::FAILURE;
                }
                $assets[$name] = ['path' => $path, 'size' => filesize($path), 'mtime' => filemtime($path), 'sha256' => hash_file('sha256', $path)];
            }
        }
        $process = new Process([PHP_BINARY, 'artisan', 'test', '--filter=Recognition', '--compact'], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array']);
        $process->setTimeout(600)->run(fn ($type, $buffer) => $this->output->write($buffer));
        if (! $process->isSuccessful()) {
            $this->error('Vérification échouée : les outils restent désactivés.');

            return self::FAILURE;
        }
        $hashes = [];
        foreach (RecognitionEngine::fingerprints() as $path) {
            $hashes[$path] = hash_file('sha256', base_path($path));
        }
        File::ensureDirectoryExists(dirname($marker));
        File::put($marker, json_encode(['tools' => RecognitionEngine::TOOLS, 'hashes' => $hashes, 'assets' => $assets, 'verified_at' => now()->toIso8601String()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('OCR et transcription audio/vidéo vérifiés et activés.');

        return self::SUCCESS;
    }
}
