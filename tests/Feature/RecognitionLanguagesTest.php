<?php

namespace Tests\Feature;

use App\Jobs\ProcessConversion;
use App\Models\ConversionJob;
use App\Models\Plan;
use App\Services\Conversions\EngineRegistry;
use App\Services\Conversions\Pipeline;
use App\Services\Conversions\RecognitionLanguages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RecognitionLanguagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Engine-focused fixtures keep capabilities enabled; billing tests cover tier restrictions.
        Plan::where('slug', 'free')->update(['entitlements' => ['priority_processing' => false, 'advanced_conversions' => true, 'batch_processing' => true, 'priority_support' => false]]);
        config(['recognition.testing_ready' => true]);
        Storage::fake('conversions');
        $this->withSession(['conversion_owner' => str_repeat('c', 64)]);
    }

    public static function ocrSamples(): array
    {
        $samples = json_decode(file_get_contents(__DIR__.'/../Fixtures/recognition/languages/samples.json'), true);
        $result = [];
        foreach ($samples as $sample) {
            $result[$sample['code']] = [$sample['code'], $sample['expected']];
        }

        return $result;
    }

    #[DataProvider('ocrSamples')]
    public function test_native_ocr_reads_a_word_in_each_added_script(string $code, string $expected): void
    {
        $file = UploadedFile::fake()->createWithContent('sample.png', file_get_contents(base_path('tests/Fixtures/recognition/languages/'.$code.'.png')));
        $id = $this->postJson('/api/v1/conversions', ['tool' => 'ocr-image', 'file' => $file, 'target_format' => 'txt', 'options' => ['language' => $code, 'layout' => 'block']])->assertStatus(202)->json('data.id');
        $job = ConversionJob::findOrFail($id);
        (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));
        $job->refresh();
        $this->assertSame('completed', $job->status, $job->error_code ?? '');
        $this->assertStringContainsString($expected, Storage::disk('conversions')->get($job->output_path));
    }

    public function test_catalog_and_api_share_one_closed_language_list(): void
    {
        foreach (['ocr-image', 'transcrire-media'] as $tool) {
            $this->getJson('/api/v1/tools/'.$tool)->assertOk()->assertJsonPath('data.recognition_languages', RecognitionLanguages::options($tool));
            $this->postJson('/api/v1/conversions', ['tool' => $tool, 'target_format' => 'txt', 'options' => ['language' => '../../eng'], 'file' => UploadedFile::fake()->createWithContent('test.png', 'not an image')])->assertUnprocessable();
        }
        $this->assertDatabaseCount('conversion_jobs', 0);
    }

    public function test_whisper_accepts_all_twenty_published_language_codes(): void
    {
        $codes = array_column(array_filter(RecognitionLanguages::catalog(), fn ($item) => $item['speech'] !== null), 'speech');
        $this->assertCount(20, $codes);
        foreach ($codes as $code) {
            // One millisecond of a public fixture tests the native language contract, not linguistic accuracy.
            $process = new Process([config('recognition.whisper'), '-m', config('recognition.model'), '-f', base_path('tests/Fixtures/recognition/english.wav'), '-l', $code, '-d', '1', '-t', '1', '-p', '1', '-bo', '1', '-bs', '1', '-nf', '-ng', '-np']);
            $process->setTimeout(20)->run();
            $this->assertTrue($process->isSuccessful(), 'Unsupported native language code: '.$code);
        }
    }
}
