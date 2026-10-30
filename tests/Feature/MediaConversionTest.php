<?php

namespace Tests\Feature;

use App\Jobs\ProcessConversion;
use App\Models\ConversionJob;
use App\Services\Conversions\EngineRegistry;
use App\Services\Conversions\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MediaConversionTest extends TestCase
{
    use RefreshDatabase;

    private static bool $fixturesReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['conversion.media.testing_ready' => true, 'conversion.limits.guest.hourly_jobs' => 30, 'conversion.limits.guest.daily_jobs' => 50]);
        Storage::fake('conversions');
        $this->withSession(['conversion_owner' => str_repeat('e', 64)]);
        if (! self::$fixturesReady) {
            (new Process([config('conversion.media.python'), base_path('tests/Support/media-fixtures.py'), config('conversion.media.ffmpeg'), base_path('.cache/media-fixtures')]))->setTimeout(120)->mustRun();
            self::$fixturesReady = true;
        }
    }

    private function file(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('.cache/media-fixtures/'.$name)));
    }

    private function convert(string $tool, string $name, string $target, array $options = []): ConversionJob
    {
        $id = $this->postJson('/api/v1/conversions', ['tool' => $tool, 'target_format' => $target, 'options' => $options, 'file' => $this->file($name)])->assertStatus(202)->json('data.id');
        $job = ConversionJob::findOrFail($id);
        (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));

        return $job->fresh();
    }

    private function probe(ConversionJob $job): array
    {
        $process = new Process([config('conversion.media.ffprobe'), '-v', 'error', '-show_format', '-show_streams', '-of', 'json', Storage::disk('conversions')->path($job->output_path)]);
        $process->mustRun();

        return json_decode($process->getOutput(), true);
    }

    public static function audioPairs(): array
    {
        $cases = [];
        foreach (['mp3', 'wav', 'aac', 'm4a', 'flac', 'ogg', 'opus'] as $format) {
            $cases['wav to '.$format] = ['sample.wav', $format];
            if ($format !== 'wav') {
                $cases[$format.' to wav'] = ['sample.'.$format, 'wav'];
            }
        }

        return $cases;
    }

    #[DataProvider('audioPairs')]
    public function test_real_audio_conversion_is_openable_and_preserves_duration(string $source, string $target): void
    {
        $job = $this->convert('convertir-audio', $source, $target);
        $this->assertSame('completed', $job->status, $job->error_code ?? '');
        $info = $this->probe($job);
        $this->assertCount(1, $info['streams']);
        $this->assertSame('audio', $info['streams'][0]['codec_type']);
        $this->assertEqualsWithDelta(2, (float) $info['format']['duration'], 0.15);
        $this->get('/api/v1/jobs/'.$job->id.'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($job->input_size + $job->output_size, $job->reserved_bytes);
    }

    public static function videoPairs(): array
    {
        $cases = [];
        foreach (['mp4', 'mov', 'mkv', 'avi', 'webm'] as $format) {
            $cases['mp4 to '.$format] = ['sample.mp4', $format];
            if ($format !== 'mp4') {
                $cases[$format.' to mp4'] = ['sample.'.$format, 'mp4'];
            }
        }

        return $cases;
    }

    #[DataProvider('videoPairs')]
    public function test_real_video_conversion_has_the_expected_streams(string $source, string $target): void
    {
        $job = $this->convert('convertir-video', $source, $target);
        $this->assertSame('completed', $job->status, $job->error_code ?? '');
        $info = $this->probe($job);
        $this->assertSame('video', $info['streams'][0]['codec_type']);
        $this->assertLessThanOrEqual(1280, $info['streams'][0]['width']);
        $this->assertLessThanOrEqual(720, $info['streams'][0]['height']);
        $this->assertEqualsWithDelta(2, (float) $info['format']['duration'], 0.2);
        $decode = new Process([config('conversion.media.ffmpeg'), '-v', 'error', '-i', Storage::disk('conversions')->path($job->output_path), '-f', 'null', '-']);
        $decode->setTimeout(10)->mustRun();
        $this->assertTrue($decode->isSuccessful());
    }

    public function test_trim_extraction_and_compression_profiles_work_on_real_files(): void
    {
        $job = $this->convert('extraire-audio', 'sample.mp4', 'wav', ['start' => 0.5, 'duration' => 0.5, 'channels' => 1, 'sample_rate' => 22050]);
        $this->assertSame('completed', $job->status, $job->error_code ?? '');
        $info = $this->probe($job);
        $this->assertSame(1, $info['streams'][0]['channels']);
        $this->assertSame('22050', $info['streams'][0]['sample_rate']);
        $this->assertEqualsWithDelta(0.5, (float) $info['format']['duration'], 0.05);
        foreach (['light', 'balanced', 'quality'] as $profile) {
            $job = $this->convert('compresser-video', 'sample.mp4', 'mp4', ['profile' => $profile, 'resolution' => 360, 'fps' => 15]);
            $this->assertSame('completed', $job->status, $job->error_code ?? '');
            $this->assertLessThan($job->input_size, $job->output_size);
        }
    }

    public function test_limits_invalid_files_missing_audio_and_output_cap_never_publish_results(): void
    {
        $bad = $this->convert('extraire-audio', 'silent.mp4', 'mp3');
        $this->assertSame('MISSING_MEDIA_STREAM', $bad->error_code);
        $bad = $this->convert('convertir-audio', 'high-rate.wav', 'wav');
        $this->assertSame('MEDIA_TOO_COMPLEX', $bad->error_code);
        config(['conversion.media.video_duration' => 1]);
        $bad = $this->convert('convertir-video', 'sample.mp4', 'mp4');
        $this->assertSame('MEDIA_TOO_COMPLEX', $bad->error_code);
        config(['conversion.media.video_duration' => 120, 'conversion.media.max_output_bytes' => 1000]);
        $bad = $this->convert('convertir-audio', 'sample.wav', 'wav');
        $this->assertSame('failed', $bad->status);
        $this->assertSame(0, $bad->reserved_bytes);
    }

    public function test_arbitrary_arguments_and_media_batches_are_refused(): void
    {
        $this->postJson('/api/v1/conversions', ['tool' => 'convertir-audio', 'target_format' => 'mp3', 'options' => ['command' => 'anything'], 'file' => $this->file('sample.wav')])->assertStatus(422);
        $this->postJson('/api/v1/conversions', ['tool' => 'convertir-video', 'target_format' => 'mp4', 'options' => ['profile' => 'anything'], 'file' => $this->file('sample.mp4')])->assertStatus(422);
        $this->postJson('/api/v1/conversions', ['tool' => 'convertir-audio', 'target_format' => 'mp3', 'files' => [$this->file('sample.wav')]])->assertStatus(422);
        $this->assertDatabaseCount('conversion_jobs', 0);
    }

    public function test_codec_inventory_matches_all_encoders_we_advertise(): void
    {
        $process = new Process([config('conversion.media.ffmpeg'), '-hide_banner', '-encoders']);
        $process->mustRun();
        foreach (['libmp3lame', 'pcm_s16le', 'aac', 'flac', 'libvorbis', 'libopus', 'libx264', 'mpeg4', 'libvpx-vp9'] as $codec) {
            $this->assertStringContainsString($codec, $process->getOutput());
        }
    }
}
