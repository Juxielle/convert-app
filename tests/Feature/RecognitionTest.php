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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RecognitionTest extends TestCase
{
    use RefreshDatabase;

    private static bool $ready = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Engine-focused fixtures keep capabilities enabled; billing tests cover tier restrictions.
        Plan::where('slug', 'free')->update(['entitlements' => ['priority_processing' => false, 'advanced_conversions' => true, 'batch_processing' => true, 'priority_support' => false]]);
        config(['recognition.testing_ready' => true, 'conversion.limits.guest.hourly_jobs' => 30, 'conversion.limits.guest.daily_jobs' => 50]);
        Storage::fake('conversions');
        $this->withSession(['conversion_owner' => str_repeat('f', 64)]);
        if (! self::$ready) {
            (new Process([config('recognition.python'), base_path('tests/Support/recognition-fixtures.py'), base_path('.cache/recognition-fixtures'), config('conversion.media.ffmpeg'), base_path('tests/Fixtures/recognition')]))->setTimeout(120)->mustRun();
            self::$ready = true;
        }
    }

    private function upload(string $tool, string $path, array $options = []): ConversionJob
    {
        $file = UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path));
        $id = $this->postJson('/api/v1/conversions', ['tool' => $tool, 'file' => $file, 'target_format' => 'txt', 'options' => $options])->assertStatus(202)->json('data.id');
        $job = ConversionJob::findOrFail($id);
        (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));

        return $job->fresh();
    }

    public static function images(): array
    {
        return [['png'], ['jpg'], ['webp']];
    }

    #[DataProvider('images')]
    public function test_real_ocr_reads_printed_french_accents_and_english(string $extension): void
    {
        $job = $this->upload('ocr-image', base_path('.cache/recognition-fixtures/text.'.$extension));
        $this->assertSame('completed', $job->status, $job->error_code ?? '');
        $text = Storage::disk('conversions')->get($job->output_path);
        $this->assertStringContainsString('Bonjour le monde', $text);
        $this->assertStringContainsString('résumé', $text);
        $this->assertStringContainsString('Private text', $text);
        $this->get('/api/v1/jobs/'.$job->id.'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($job->input_size + $job->output_size, $job->reserved_bytes);
    }

    public static function media(): array
    {
        return array_map(fn ($format) => [$format], ['wav', 'mp3', 'flac', 'ogg', 'opus', 'm4a', 'aac', 'mp4', 'mov', 'mkv', 'avi', 'webm']);
    }

    #[DataProvider('media')]
    public function test_real_speech_is_extracted_from_audio_and_video(string $extension): void
    {
        $path = $extension === 'wav' ? base_path('tests/Fixtures/recognition/english.wav') : base_path('.cache/recognition-fixtures/speech.'.$extension);
        $job = $this->upload('transcrire-media', $path, ['language' => 'en']);
        $this->assertSame('completed', $job->status, $job->error_code ?? '');
        $this->assertStringContainsString('ask not', strtolower(Storage::disk('conversions')->get($job->output_path)));
        $this->withSession(['conversion_owner' => str_repeat('b', 64)])->get('/api/v1/jobs/'.$job->id.'/download')->assertNotFound();
    }

    public function test_french_speech_is_transcribed_without_translation(): void
    {
        $job = $this->upload('transcrire-media', base_path('tests/Fixtures/recognition/french.wav'), ['language' => 'fr']);
        $this->assertSame('completed', $job->status, $job->error_code ?? '');
        $text = mb_strtolower(Storage::disk('conversions')->get($job->output_path));
        $this->assertStringContainsString('bonjour', $text);
        $this->assertStringContainsString('transcription', $text);
    }

    public function test_automatic_language_detection_recognizes_french_speech(): void
    {
        $job = $this->upload('transcrire-media', base_path('tests/Fixtures/recognition/french.wav'));
        $this->assertSame('completed', $job->status, $job->error_code ?? '');
        $this->assertStringContainsString('bonjour', mb_strtolower(Storage::disk('conversions')->get($job->output_path)));
    }

    public function test_empty_results_silence_and_excessive_duration_fail_cleanly(): void
    {
        $blank = $this->upload('ocr-image', base_path('.cache/recognition-fixtures/blank.png'));
        $this->assertSame('NO_TEXT_FOUND', $blank->error_code);
        $silent = $this->upload('transcrire-media', base_path('.cache/recognition-fixtures/silence.wav'));
        $this->assertSame('NO_SPEECH', $silent->error_code);
        config(['recognition.max_duration' => 1]);
        $long = $this->upload('transcrire-media', base_path('tests/Fixtures/recognition/english.wav'));
        $this->assertSame('MEDIA_TOO_COMPLEX', $long->error_code);
        $this->assertSame(0, $long->reserved_bytes);
    }

    public function test_batches_mime_spoofing_and_arbitrary_engine_options_are_refused(): void
    {
        $file = UploadedFile::fake()->createWithContent('text.png', file_get_contents(base_path('.cache/recognition-fixtures/text.png')));
        $this->postJson('/api/v1/conversions', ['tool' => 'ocr-image', 'target_format' => 'txt', 'files' => [$file]])->assertUnprocessable();
        $this->postJson('/api/v1/conversions', ['tool' => 'ocr-image', 'target_format' => 'txt', 'file' => $file, 'options' => ['command' => 'anything']])->assertUnprocessable();
        $this->postJson('/api/v1/conversions', ['tool' => 'transcrire-media', 'target_format' => 'txt', 'file' => UploadedFile::fake()->createWithContent('test.mp3', 'not audio')])->assertUnprocessable();
        $this->assertDatabaseCount('conversion_jobs', 0);
    }

    public function test_native_transcription_deadline_never_publishes_a_partial_text(): void
    {
        config(['conversion.limits.guest.timeout_seconds' => 1]);
        $job = $this->upload('transcrire-media', base_path('tests/Fixtures/recognition/english.wav'), ['language' => 'en']);
        $this->assertSame('failed', $job->status);
        $this->assertSame('PROCESS_TIMEOUT', $job->error_code);
        $this->assertSame(0, $job->reserved_bytes);
        $this->get('/api/v1/jobs/'.$job->id.'/download')->assertNotFound();
    }
}
