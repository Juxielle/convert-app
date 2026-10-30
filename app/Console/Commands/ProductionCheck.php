<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountMail;
use App\Services\Conversions\DocumentEngine;
use App\Services\Conversions\ImageEngine;
use App\Services\Conversions\MediaEngine;
use App\Services\Conversions\RecognitionEngine;
use App\Services\External\SourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductionCheck extends Command
{
    protected $signature = 'app:production-check {--json : Résultat structuré sans valeurs sensibles}';

    protected $description = 'Contrôle la préparation de production sans modifier la configuration ni les données.';

    public function handle(): int
    {
        $checks = [
            'production_environment' => app()->environment('production'), 'debug_disabled' => config('app.debug') === false,
            'https_canonical' => str_starts_with(config('app.url'), 'https://'), 'secure_session_cookie' => config('session.secure') === true,
            'http_only_cookie' => config('session.http_only') === true, 'same_site_cookie' => in_array(config('session.same_site'), ['lax', 'strict'], true),
            'database_sessions' => config('session.driver') === 'database', 'durable_cache' => config('cache.default') === 'database',
            'mysql_selected' => in_array(config('database.default'), ['mysql', 'mariadb'], true),
            'database_queue' => config('queue.default') === 'database' && ! config('queue.connections.database.after_commit'),
            'queue_retry_after' => config('queue.connections.database.retry_after') > config('conversion.worker_timeout'),
            'mail_transport' => AccountMail::available(), 'frontend_build' => is_file(public_path('build/manifest.json')) && ! is_file(public_path('hot')),
            'image_engine' => ImageEngine::available(), 'documents_verified' => DocumentEngine::available('office-vers-pdf'), 'media_verified' => MediaEngine::available('convertir-video'),
            'external_gated' => ! config('external.enabled') || app(SourceRegistry::class)->enabled(),
            'ocr_verified' => ! config('recognition.enabled') || RecognitionEngine::available('ocr-image'),
            'speech_verified' => ! config('recognition.enabled') || RecognitionEngine::available('transcrire-media'),
            'linux_non_root' => PHP_OS_FAMILY === 'Linux' && function_exists('posix_geteuid') && posix_geteuid() !== 0,
            'conversion_budget_free' => (@disk_free_space(storage_path('app')) ?: 0) >= config('conversion.global_storage_bytes'),
        ];
        try {
            DB::connection()->getPdo();
            $checks['database_connected'] = true;
            $checks['account_migration'] = Schema::hasTable('tool_settings') && Schema::hasTable('audit_logs') && Schema::hasColumn('users', 'role');
            $checks['admin_exists'] = $checks['account_migration'] && User::where('role', 'admin')->exists();
            $checks['worker_recently_seen'] = Cache::has('conversion-worker-heartbeat');
        } catch (\Throwable) {
            $checks['database_connected'] = false;
        }
        $manual = ['HTTPS réel et cookies depuis un navigateur', 'MySQL : tests de concurrence sur une base de test séparée',
            'CPU/RAM/disque du VPS partagé, limites systemd et réseau', 'Cron et expiration après redémarrage',
            'Restauration de sauvegarde et retour arrière', 'Email réel, mentions légales et identité de l’exploitant'];
        $ok = ! in_array(false, $checks, true);
        if ($this->option('json')) {
            $this->line(json_encode(['automated_checks_passed' => $ok, 'checks' => $checks, 'manual_checks_required' => $manual], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Contrôle', 'Résultat'], collect($checks)->map(fn ($pass, $name) => [$name, $pass ? 'OK' : 'À corriger'])->all());
            foreach ($manual as $item) {
                $this->warn('Validation manuelle : '.$item);
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
