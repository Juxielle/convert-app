<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class VerifyBackground extends Command
{
    protected $signature = 'background:verify';

    protected $description = 'Verify local background removal before enabling the tool';

    public function handle(): int
    {
        $marker = base_path('.tools/background-ready.json');
        File::delete($marker);
        $model = config('conversion.background.model');
        if (! is_file($model) || hash_file('sha256', $model) !== '309c8469258dda742793dce0ebea8e6dd393174f89934733ecc8b14c76f4ddd8') {
            $this->error('Installer le modèle U²-Net vérifié. Voir docs/background-removal.md.');

            return self::FAILURE;
        }
        $test = new Process([PHP_BINARY, 'artisan', 'test', '--filter=BackgroundRemovalTest'], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array',
        ]);
        $test->setTimeout(180)->run(fn ($type, $buffer) => $this->output->write($buffer));
        if (! $test->isSuccessful()) {
            $this->error('Le détourage reste désactivé : vérification échouée.');

            return self::FAILURE;
        }
        File::put($marker, json_encode(['worker_hash' => hash_file('sha256', base_path('bin/background-worker.py')), 'verified_at' => now()->toIso8601String()], JSON_PRETTY_PRINT));
        $this->info('Détourage local vérifié et activé.');

        return self::SUCCESS;
    }
}
