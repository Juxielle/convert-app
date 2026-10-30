<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Conversions\Owner;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_seo_is_private_by_default_and_canonical_does_not_trust_host(): void
    {
        $this->withoutVite();
        config(['app.url' => 'https://convert.example', 'seo.indexing' => false]);
        $this->get('/outils/convertir-image?tracking=secret', ['Host' => 'attacker.example'])->assertOk()->assertSee('<link rel="canonical" href="https://convert.example/outils/convertir-image">', false)->assertSee('noindex, nofollow');
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('<url>', false);
        $this->get('/outils/inconnu')->assertNotFound();
        config(['seo.indexing' => true]);
        $this->get('/sitemap.xml')->assertOk()->assertSee('https://convert.example/outils/convertir-image')->assertDontSee('telechargement-autorise')->assertDontSee('/profil')->assertDontSee('/administration');
        $this->get('/outils/convertir-image')->assertSee('index, follow')->assertSee('application/ld+json', false)->assertSee('WebApplication');
        foreach (['/profil', '/administration', '/connexion', '/reinitialiser-mot-de-passe?token=secret&email=test@example.test'] as $path) {
            $this->get($path)->assertOk()->assertSee('noindex, nofollow')->assertDontSee('secret');
        }
    }

    public function test_real_csrf_protects_accounts_settings_and_external_routes(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        foreach (['/api/v1/auth/register', '/api/v1/auth/login', '/api/v1/auth/forgot-password', '/api/v1/auth/reset-password', '/api/v1/social-downloads', '/api/v1/subscriptions/checkout', '/api/v1/subscriptions/quote', '/api/v1/subscriptions/trial', '/api/v1/subscriptions/cancel-trial'] as $path) {
            $this->postJson($path, [])->assertStatus(419);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patchJson('/api/v1/admin/settings', [])->assertStatus(419);
        $this->postJson('/api/v1/admin/plans', [])->assertStatus(419);
        $this->patchJson('/api/v1/admin/plans/1', [])->assertStatus(419);
    }

    public function test_production_security_headers_and_session_cache_control(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $response = $this->getJson('https://localhost/api/v1/auth/session')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString("object-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
    }

    public function test_public_plans_do_not_offer_premium_or_allow_quota_escalation(): void
    {
        $this->getJson('/api/v1/plans')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.id', 'free')->assertJsonPath('data.1.available', true)->assertJsonPath('data.2.available', true);
        $user = User::factory()->create(['plan' => 'unknown']);
        $this->actingAs($user);
        $this->assertSame(config('conversion.limits.registered'), Owner::fromRequest(request())->limits());
        $this->patchJson('/api/v1/me/profile', ['name' => 'Any', 'email' => $user->email, 'current_password' => 'password', 'plan' => 'premium'])->assertUnprocessable();
    }

    public function test_preflight_reports_local_limitations_without_exposing_secrets(): void
    {
        $this->artisan('app:production-check', ['--json' => true])->assertFailed();
    }
}
