<?php

namespace App\Services\External;

use App\Services\Settings;

final class SourceRegistry
{
    public function approved(): array
    {
        if (app()->environment('testing') && config('external.testing_ready')) {
            return [['id' => 'commons', 'name' => 'Wikimedia Commons', 'formats' => ['webm'], 'licenses' => ['CC0 1.0']]];
        }
        $file = base_path('.tools/commons-ready.json');
        if (! is_file($file) || ! function_exists('curl_init') || ! is_file(config('conversion.media.python')) || ! is_file(config('conversion.media.ffprobe')) || trim(config('external.user_agent')) === '') {
            return [];
        }
        $proof = json_decode(file_get_contents($file), true);
        $binary = config('conversion.media.ffprobe');
        if (($proof['python'] ?? '') !== config('conversion.media.python') || ($proof['user_agent_hash'] ?? '') !== hash('sha256', config('external.user_agent')) || ($proof['ffprobe']['path'] ?? '') !== $binary
            || ($proof['ffprobe']['size'] ?? 0) !== filesize($binary) || ($proof['ffprobe']['mtime'] ?? 0) !== filemtime($binary)) {
            return [];
        }
        foreach (self::files() as $path) {
            if (($proof['hashes'][$path] ?? '') !== hash_file('sha256', base_path($path))) {
                return [];
            }
        }

        return [['id' => 'commons', 'name' => 'Wikimedia Commons', 'formats' => ['webm'], 'licenses' => ['CC0 1.0']]];
    }

    public static function files(): array
    {
        return ['app/Services/External/CommonsEngine.php', 'app/Services/External/CommonsSource.php', 'app/Services/External/PinnedTransport.php', 'app/Services/External/UrlGuard.php', 'bin/external-probe.py', 'bin/media-worker.py', 'bin/media_limits.py'];
    }

    public function enabled(): bool
    {
        return config('external.enabled') && app(Settings::class)->toolEnabled('telechargement-autorise') && count($this->approved()) > 0;
    }
}
