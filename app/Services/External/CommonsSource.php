<?php

namespace App\Services\External;

use App\Services\Conversions\PipelineException;

class CommonsSource
{
    public function title(string $url): string
    {
        if (strlen($url) > 1200 || preg_match('/[\x00-\x20\\\\]/', $url) || ! preg_match('~^https://commons\.wikimedia\.org/wiki/([^?#]+)$~D', $url, $matches)) {
            throw new PipelineException('UNSAFE_SOURCE');
        }
        $title = rawurldecode($matches[1]);
        if (strlen($title) > 240 || ! preg_match('/^File:[^\x00-\x1f<>|{}\[\]\\\\\/]+\.webm$/uiD', $title)) {
            throw new PipelineException('UNSAFE_SOURCE');
        }

        return str_replace('_', ' ', $title);
    }

    public function metadata(string $title, callable $checkpoint): array
    {
        $url = 'https://commons.wikimedia.org/w/api.php?'.http_build_query(['action' => 'query', 'format' => 'json', 'formatversion' => 2, 'maxlag' => 5,
            'prop' => 'imageinfo', 'titles' => $title, 'iiprop' => 'url|size|mime|extmetadata']);
        $data = json_decode(app(PinnedTransport::class)->fetch($url, ['commons.wikimedia.org'], 1_000_000, $checkpoint), true);
        $info = $data['query']['pages'][0]['imageinfo'][0] ?? null;
        if (! is_array($info) || ($info['mime'] ?? '') !== 'video/webm' || ($info['size'] ?? 0) < 1 || $info['size'] > config('external.max_bytes')) {
            throw new PipelineException('SOURCE_INCOMPATIBLE');
        }
        $meta = $info['extmetadata'] ?? [];
        $license = rtrim($meta['LicenseUrl']['value'] ?? '', '/');
        // First version intentionally supports only CC0: no hidden attribution/share-alike workflow.
        if (! preg_match('~^https?://creativecommons\.org/publicdomain/zero/1\.0(?:/deed\.[a-z-]+)?$~D', $license)) {
            throw new PipelineException('LICENSE_NOT_ALLOWED');
        }
        // imageinfo adds public utm_* tracking parameters; the static original needs none.
        $download = explode('?', $info['url'] ?? '', 2)[0];
        if (! str_starts_with($download, 'https://upload.wikimedia.org/wikipedia/commons/')) {
            throw new PipelineException('UNSAFE_SOURCE');
        }
        $plain = fn (string $value) => mb_substr(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 500);

        return ['download_url' => $download, 'size' => (int) $info['size'], 'attribution' => [
            'source' => 'Wikimedia Commons', 'title' => $title, 'url' => 'https://commons.wikimedia.org/wiki/'.rawurlencode(str_replace(' ', '_', $title)),
            'artist' => $plain((string) ($meta['Artist']['value'] ?? 'Non précisé')), 'license' => 'CC0 1.0', 'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/',
        ]];
    }
}
