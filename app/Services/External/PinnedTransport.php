<?php

namespace App\Services\External;

use App\Services\Conversions\PipelineException;
use Illuminate\Support\Facades\Cache;

class PinnedTransport
{
    public function fetch(string $url, array $domains, int $maxBytes, callable $checkpoint, $stream = null): string
    {
        if (Cache::has('commons-remote-cooldown')) {
            throw new PipelineException('REMOTE_UNAVAILABLE');
        }
        $guard = app(UrlGuard::class);
        $started = microtime(true);
        $total = 0;
        for ($hop = 0; $hop <= config('external.max_redirects'); $hop++) {
            try {
                $target = $hop ? $guard->redirect($url, $domains, $hop) : $guard->validate($url, $domains);
            } catch (\InvalidArgumentException) {
                throw new PipelineException('UNSAFE_SOURCE');
            }
            $address = $target['addresses'][0];
            $pin = $target['host'].':443:'.(str_contains($address, ':') ? '['.$address.']' : $address);
            $curl = curl_init($url);
            $body = '';
            $location = null;
            $retryAfter = null;
            $lastCheck = 0.0;
            if (is_resource($stream)) {
                rewind($stream);
                ftruncate($stream, 0);
            }
            curl_setopt_array($curl, [
                CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_RESOLVE => [$pin], CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => max(1, (int) (config('external.timeout_seconds') - (microtime(true) - $started))),
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => config('external.user_agent'),
                CURLOPT_HTTPHEADER => ['Accept: application/json, video/webm;q=0.9', 'Accept-Encoding: identity'],
                CURLOPT_MAX_RECV_SPEED_LARGE => 1_000_000, CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 10,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => function () use ($checkpoint, $started, &$lastCheck): int {
                    if (microtime(true) - $lastCheck >= 0.1) {
                        $checkpoint();
                        $lastCheck = microtime(true);
                    }

                    return microtime(true) - $started >= config('external.timeout_seconds') ? 1 : 0;
                },
                CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$location, &$retryAfter): int {
                    if (str_starts_with(strtolower($line), 'location:')) {
                        $location = trim(substr($line, 9));
                    }
                    if (str_starts_with(strtolower($line), 'retry-after:')) {
                        $retryAfter = trim(substr($line, 12));
                    }

                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$total, $maxBytes, $stream): int {
                    $total += strlen($chunk);
                    if ($total > $maxBytes) {
                        return 0;
                    }
                    if (is_resource($stream)) {
                        return fwrite($stream, $chunk);
                    }
                    $body .= $chunk;

                    return strlen($chunk);
                },
            ]);
            try {
                $ok = curl_exec($curl);
                $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            } finally {
                curl_close($curl);
            }
            $checkpoint();
            if ($total > $maxBytes) {
                throw new PipelineException('OUTPUT_LIMIT_EXCEEDED');
            }
            if ($ok === false || microtime(true) - $started >= config('external.timeout_seconds')) {
                throw new PipelineException('REMOTE_UNAVAILABLE');
            }
            if (in_array($status, [301, 302, 303, 307, 308], true) && is_string($location)) {
                $url = $location;

                continue;
            }
            if ($status !== 200) {
                if (in_array($status, [429, 503], true)) {
                    $delay = is_string($retryAfter) && ctype_digit($retryAfter) ? (int) $retryAfter : max(60, (strtotime($retryAfter ?? '') ?: time()) - time());
                    Cache::put('commons-remote-cooldown', true, max(60, min(86400, $delay)));
                }
                throw new PipelineException('REMOTE_UNAVAILABLE');
            }

            return $body;
        }
        throw new PipelineException('UNSAFE_SOURCE');
    }
}
