<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Conversions\EngineRegistry;
use App\Services\Conversions\PipelineException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEngineRegistry;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_metadata_access_never_grants_access_to_another_users_file(): void
    {
        Storage::fake('conversions');
        $this->app->instance(EngineRegistry::class, new FakeEngineRegistry);
        $this->actingAs(User::factory()->create());
        $id = $this->postJson('/api/v1/conversions', ['tool' => 'pipeline-test', 'target_format' => 'txt', 'file' => UploadedFile::fake()->createWithContent('private.txt', 'Private fixture text')])->assertStatus(202)->json('data.id');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/admin/jobs')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonMissingPath('data.0.input_path')->assertJsonMissingPath('data.0.original_name')->assertJsonMissingPath('data.0.owner_key');
        $this->getJson('/api/v1/jobs/'.$id)->assertNotFound();
        $this->getJson('/api/v1/jobs/'.$id.'/download')->assertNotFound();
        $this->deleteJson('/api/v1/jobs/'.$id)->assertNotFound();
    }

    public function test_admin_endpoints_reject_visitors_and_ordinary_users(): void
    {
        foreach (['overview', 'jobs', 'settings', 'audit'] as $path) {
            $this->getJson('/api/v1/admin/'.$path)->assertUnauthorized();
        }
        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/overview')->assertForbidden();
        $this->patchJson('/api/v1/admin/settings', ['limits' => []])->assertForbidden();
    }

    public function test_settings_are_bounded_audited_and_used_by_real_admission(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patchJson('/api/v1/admin/settings', ['limits' => ['guest' => ['hourly_jobs' => 3]], 'tools' => ['convertir-image' => false]])->assertOk()->assertJsonPath('data.limits.guest.hourly_jobs', 3);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->getJson('/api/v1/limits')->assertJsonPath('data.plans.guest.hourly_jobs', 3);
        $this->getJson('/api/v1/tools/convertir-image')->assertJsonPath('data.enabled', false);
        $this->patchJson('/api/v1/admin/settings', ['limits' => ['registered' => ['timeout_seconds' => 181]]])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/settings', ['tools' => ['unknown-tool' => true]])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/settings', ['limits' => ['guest' => ['command' => 'anything']]])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/settings', ['limits' => ['guest' => ['hourly_jobs' => 99, 'daily_jobs' => 1]], 'tools' => ['compresser-image' => false]])->assertUnprocessable();
        $this->getJson('/api/v1/tools/compresser-image')->assertJsonPath('data.enabled', true);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->getJson('/api/v1/admin/overview')->assertOk()->assertJsonPath('data.worker_last_seen', null);
        $this->getJson('/api/v1/admin/audit')->assertOk()->assertJsonCount(2, 'data');
        $this->expectException(PipelineException::class);
        app(EngineRegistry::class)->get('convertir-image');
    }

    public function test_first_admin_requires_the_trusted_cli_and_is_audited(): void
    {
        $user = User::factory()->create();
        $this->artisan('accounts:admin', ['email' => $user->email])->assertSuccessful();
        $this->assertSame('admin', $user->fresh()->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.cli_updated']);
        $this->artisan('accounts:admin', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertSame('user', $user->fresh()->role);
    }
}
