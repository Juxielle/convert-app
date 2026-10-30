<?php

namespace App\Services\External;

use InvalidArgumentException;

final class UrlGuard
{
    /** Returns the exact DNS answers a future transport MUST pin with CURLOPT_RESOLVE.
     * Never pass an approved URL to an independently resolving HTTP client.
     * The resolver is injectable for offline DNS/rebinding tests.
     */
    public function validate(string $url, array $domains, ?callable $resolver = null): array
    {
        if (strlen($url) > 2048 || preg_match('/[^\x21-\x7e]|\\\\|%0[ad]|%25/i', $url)) {
            throw new InvalidArgumentException('UNSAFE_URL');
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new InvalidArgumentException('UNSAFE_URL');
        }
        $host = strtolower($parts['host'] ?? '');
        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)
            || ! in_array($host, $domains, true) || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('SOURCE_NOT_ALLOWED');
        }
        $resolver ??= static function (string $name): array {
            $records = dns_get_record($name, DNS_A | DNS_AAAA);

            return array_values(array_filter(array_map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records ?: [])));
        };
        $addresses = $resolver($host);
        if (! is_array($addresses) || count($addresses) < 1 || count($addresses) > 16) {
            throw new InvalidArgumentException('UNSAFE_DNS');
        }
        foreach ($addresses as $address) {
            if (! is_string($address) || ! $this->publicAddress($address)) {
                throw new InvalidArgumentException('UNSAFE_DNS');
            }
        }

        return ['url' => $url, 'host' => $host, 'port' => 443, 'addresses' => array_values(array_unique($addresses))];
    }

    public function redirect(string $url, array $domains, int $count, ?callable $resolver = null): array
    {
        if ($count < 1 || $count > config('external.max_redirects')) {
            throw new InvalidArgumentException('REDIRECT_LIMIT');
        }

        // Deliberately require a complete HTTPS URL; no ambiguous relative resolution.
        return $this->validate($url, $domains, $resolver);
    }

    private function publicAddress(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (str_contains($address, ':')) {
            $packed = inet_pton($address);

            // Only native global unicast 2000::/3; reject mapped, NAT64, Teredo, 6to4 and documentation.
            return $packed !== false && (ord($packed[0]) & 0xE0) === 0x20
                && ! str_starts_with(strtolower($address), '2001:') && ! str_starts_with(strtolower($address), '2002:');
        }
        $long = ip2long($address);
        foreach (['100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] as $cidr) {
            [$network, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);
            if (($long & $mask) === (ip2long($network) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
