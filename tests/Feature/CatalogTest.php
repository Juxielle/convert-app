<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    public function test_catalog_announces_only_planned_capabilities(): void
    {
        $response = $this->getJson('/api/v1/tools')->assertOk()->assertJsonCount(count(config('conversion.tools')), 'data');
        foreach ($response->json('data') as $tool) {
            if ($tool['slug'] === 'ocr-image' && $tool['enabled']) {
                $this->assertSame(['txt'], $tool['target_formats']);
            } elseif ($tool['slug'] === 'ocr-image') {
                $this->assertFalse($tool['enabled']);
            } elseif ($tool['slug'] === 'supprimer-fond') {
                $this->assertSame($tool['enabled'] ? ['png'] : [], $tool['target_formats']);
            } elseif ($tool['category'] === 'images') {
                $this->assertTrue($tool['enabled']);
                $this->assertSame(['jpg', 'png', 'webp'], $tool['target_formats']);
            } elseif (in_array($tool['category'], ['documents', 'audio', 'video'], true) && $tool['enabled']) {
                $this->assertNotEmpty($tool['source_formats']);
                $this->assertNotEmpty($tool['target_formats']);
            } else {
                $this->assertFalse($tool['enabled']);
                $this->assertSame([], $tool['target_formats']);
            }
        }
        $this->getJson('/api/v1/tools/telechargement-autorise')->assertJsonPath('data.status', 'disabled');
    }

    public function test_tool_detail_and_missing_resource_have_consistent_responses(): void
    {
        $this->getJson('/api/v1/tools/convertir-image')->assertOk()->assertJsonPath('data.slug', 'convertir-image');
        $this->get('/api/v1/tools/absent')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->get('/api/unknown')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->get('/api/v1/jobs/private')->assertNotFound()->assertJsonMissingPath('exception');
    }

    public function test_limits_are_configuration_driven_and_not_fake_usage(): void
    {
        config(['conversion.limits.guest.daily_jobs' => 7]);
        $this->getJson('/api/v1/limits')->assertOk()
            ->assertJsonPath('data.enforced', true)
            ->assertJsonPath('data.plans.guest.daily_jobs', 7)
            ->assertJsonPath('data.plans.guest.max_file_bytes', 25_000_000)
            ->assertJsonMissingPath('data.used');
    }

    public function test_conversion_and_social_uploads_are_not_implemented(): void
    {
        $this->postJson('/api/v1/conversions')->assertStatus(422)->assertJsonPath('error.code', 'TOOL_UNAVAILABLE');
        $this->postJson('/api/v1/social-downloads')->assertStatus(503)->assertJsonPath('error.code', 'EXTERNAL_DISABLED');
        $this->postJson('/api/v1/tools')->assertStatus(405)->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
    }

    public function test_internal_errors_never_expose_private_details_even_in_debug(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/v1/test-error', fn () => throw new \RuntimeException('sensitive/private/path'));
        $this->get('/api/v1/test-error')->assertStatus(500)
            ->assertExactJson(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Une erreur est survenue. Veuillez réessayer.']]);
    }

    public function test_catalog_is_rate_limited_with_retry_header(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.21'])->getJson('/api/v1/tools')->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.21'])->getJson('/api/v1/tools')
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_pages_load_in_french_without_exposing_storage(): void
    {
        $this->withoutVite();
        foreach (['/', '/outils', '/outils/convertir-image', '/faq', '/confidentialite', '/conditions', '/mentions-legales'] as $path) {
            $this->get($path)->assertOk()->assertSee('lang="fr"', false)->assertSee('id="root"', false);
        }
        $this->get('/storage/private/result.pdf')->assertNotFound();
    }
}
