<?php

namespace Tests\Feature;

use App\Jobs\ProcessConversion;
use App\Models\ConversionJob;
use App\Models\Plan;
use App\Services\Conversions\EngineRegistry;
use App\Services\Conversions\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackgroundRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Engine-focused fixtures keep capabilities enabled; billing tests cover tier restrictions.
        Plan::where('slug', 'free')->update(['entitlements' => ['priority_processing' => false, 'advanced_conversions' => true, 'batch_processing' => true, 'priority_support' => false]]);
        config(['conversion.background.testing_ready' => true]);
        Storage::fake('conversions');
        $this->withSession(['conversion_owner' => str_repeat('f', 64)]);
    }

    private function image(): UploadedFile
    {
        $image = imagecreatetruecolor(160, 160);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledellipse($image, 80, 80, 85, 110, imagecolorallocate($image, 200, 20, 30));
        ob_start();
        imagepng($image);

        return UploadedFile::fake()->createWithContent('subject.png', ob_get_clean());
    }

    public function test_real_model_produces_a_private_transparent_png_and_retains_subject(): void
    {
        $this->getJson('/api/v1/tools/supprimer-fond')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.target_formats', ['png']);
        $id = $this->postJson('/api/v1/conversions', ['tool' => 'supprimer-fond', 'target_format' => 'png', 'file' => $this->image()])->assertStatus(202)->json('data.id');
        $job = ConversionJob::findOrFail($id);
        (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));
        $job->refresh();
        $this->assertSame('completed', $job->status, $job->error_code ?? '');
        $result = $this->get('/api/v1/jobs/'.$id.'/download')->assertOk()->streamedContent();
        $image = imagecreatefromstring($result);
        $this->assertSame(160, imagesx($image));
        $this->assertSame(160, imagesy($image));
        $this->assertGreaterThan(100, imagecolorsforindex($image, imagecolorat($image, 0, 0))['alpha']);
        $this->assertLessThan(20, imagecolorsforindex($image, imagecolorat($image, 80, 80))['alpha']);
        $this->withSession(['conversion_owner' => str_repeat('b', 64)])->get('/api/v1/jobs/'.$id.'/download')->assertNotFound();
    }

    public function test_rejects_formats_without_transparency_and_unknown_options(): void
    {
        $this->postJson('/api/v1/conversions', ['tool' => 'supprimer-fond', 'target_format' => 'jpg', 'file' => $this->image()])->assertStatus(422);
        $this->postJson('/api/v1/conversions', ['tool' => 'supprimer-fond', 'target_format' => 'png', 'options' => ['model' => 'http://example.test/model'], 'file' => $this->image()])->assertStatus(422);
        $this->assertDatabaseCount('conversion_jobs', 0);
    }

    public function test_disabled_engine_is_not_admitted(): void
    {
        config(['conversion.background.enabled' => false]);
        $this->getJson('/api/v1/tools/supprimer-fond')->assertOk()->assertJsonPath('data.enabled', false);
        $this->postJson('/api/v1/conversions', ['tool' => 'supprimer-fond', 'target_format' => 'png', 'file' => $this->image()])->assertStatus(422);
    }
}
