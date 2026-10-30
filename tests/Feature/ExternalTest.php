<?php

namespace Tests\Feature;

use App\Services\External\UrlGuard;
use InvalidArgumentException;
use Tests\TestCase;

class ExternalTest extends TestCase
{
    public function test_module_never_enables_without_a_verified_adapter(): void
    {
        config(['external.enabled' => true, 'external.sources' => ['example.com'], 'external.user_agent' => '']);
        $this->getJson('/api/v1/social-downloads/sources')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.sources', []);
        $this->postJson('/api/v1/social-downloads', ['url' => 'http://127.0.0.1/private?secret=not-logged'])->assertStatus(503)->assertJsonPath('error.code', 'EXTERNAL_DISABLED');
    }

    public function test_url_guard_blocks_ambiguous_hosts_and_protocols_before_dns(): void
    {
        $guard = new UrlGuard;
        foreach (['http://example.com/a', 'file:///etc/passwd', 'https://user:pass@example.com/a', 'https://example.com:444/a', 'https://127.0.0.1/a', 'https://2130706433/a', 'https://[::1]/a', 'https://example.com./a', 'https://example.com.evil.test/a', 'https://example.com/%0dtest', 'https://example.com\\@localhost/a', 'https://example.com/#secret', 'https://%65xample.com/a'] as $url) {
            try {
                $guard->validate($url, ['example.com'], fn () => throw new \LogicException('DNS must not run'));
                $this->fail($url);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_dns_and_redirects_reject_private_mixed_and_rebound_answers(): void
    {
        $guard = new UrlGuard;
        foreach (['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.1', '169.254.169.254', '0.0.0.0', '100.64.0.1', '192.0.2.1', '224.0.0.1', '::1', 'fc00::1', 'fe80::1', '::ffff:8.8.8.8', '64:ff9b::a00:1', '2002:0808:0808::1', '2001:db8::1'] as $ip) {
            try {
                $guard->validate('https://example.com/video', ['example.com'], fn () => ['8.8.8.8', $ip]);
                $this->fail($ip);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $result = $guard->validate('https://example.com/video', ['example.com'], fn () => ['8.8.8.8', '2606:4700:4700::1111']);
        $this->assertCount(2, $result['addresses']);
        try {
            $guard->redirect('https://example.com/video', ['example.com'], 1, fn () => ['127.0.0.1']);
            $this->fail();
        } catch (InvalidArgumentException $error) {
            $this->assertSame('UNSAFE_DNS', $error->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $guard->redirect('https://example.com/video', ['example.com'], 4, fn () => ['8.8.8.8']);
    }
}
