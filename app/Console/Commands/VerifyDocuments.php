<?php

namespace App\Console\Commands;

use App\Services\Conversions\DocumentEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class VerifyDocuments extends Command
{
    protected $signature = 'documents:verify {--full : Exécuter aussi toute la suite PHP}';

    protected $description = 'Teste réellement les moteurs documents avant de les autoriser dans le catalogue.';

    public function handle(): int
    {
        $marker = base_path('.tools/documents-ready.json');
        File::delete($marker);
        if (! is_file(config('conversion.documents.python')) || ! is_file(config('conversion.documents.libreoffice'))) {
            $this->error('Configurer Python et LibreOffice avant la vérification. Voir docs/documents.md.');

            return self::FAILURE;
        }
        $arguments = $this->option('full') ? [PHP_BINARY, 'artisan', 'test', '--compact'] : [PHP_BINARY, 'artisan', 'test', '--filter=DocumentConversionTest', '--compact'];
        $process = new Process($arguments, base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
            'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array',
        ]);
        $process->setTimeout(600);
        $process->run(function ($type, $buffer): void {
            $this->output->write($buffer);
        });
        if (! $process->isSuccessful()) {
            $this->error('Vérification échouée : les outils documents restent désactivés.');

            return self::FAILURE;
        }
        File::ensureDirectoryExists(dirname($marker));
        File::put($marker, json_encode([
            'tools' => DocumentEngine::TOOLS,
            'worker_hash' => hash_file('sha256', base_path('bin/document-worker.py')),
            'requirements_hash' => hash_file('sha256', base_path('requirements-documents.lock.txt')),
            'python' => config('conversion.documents.python'),
            'office' => config('conversion.documents.libreoffice'),
            'verified_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Les huit outils documents ont passé leurs tests et sont activés.');

        return self::SUCCESS;
    }
}
