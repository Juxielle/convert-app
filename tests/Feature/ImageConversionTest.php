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
use Tests\TestCase;

class ImageConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Engine-focused fixtures keep capabilities enabled; billing tests cover tier restrictions.
        Plan::where('slug', 'free')->update(['entitlements' => ['priority_processing' => false, 'advanced_conversions' => true, 'batch_processing' => true, 'priority_support' => false]]);
        Storage::fake('conversions');
        $this->withSession(['conversion_owner' => str_repeat('c', 64)]);
    }

    private function image(string $format = 'png', int $width = 80, int $height = 40): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 20, 80, 120, 90));
        ob_start();
        match ($format) {
            'jpg' => imagejpeg($image), 'png' => imagepng($image), 'webp' => imagewebp($image)
        };
        $bytes = ob_get_clean();

        return UploadedFile::fake()->createWithContent('photo.'.$format, $bytes);
    }

    public static function pairs(): array
    {
        $pairs = [];
        foreach (['jpg', 'png', 'webp'] as $from) {
            foreach (['jpg', 'png', 'webp'] as $to) {
                $pairs[$from.' to '.$to] = [$from, $to];
            }
        }

        return $pairs;
    }

    #[DataProvider('pairs')]
    public function test_real_conversion_pairs_are_openable_and_keep_dimensions(string $from, string $to): void
    {
        $id = $this->postJson('/api/v1/conversions', ['tool' => 'convertir-image', 'target_format' => $to, 'file' => $this->image($from)])
            ->assertStatus(202)->json('data.id');
        $job = ConversionJob::findOrFail($id);
        (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));
        $job->refresh();
        $this->assertSame('completed', $job->status, $job->error_code ?? '');
        $result = $this->get('/api/v1/jobs/'.$id.'/download')->assertOk()->streamedContent();
        $decoded = imagecreatefromstring($result);
        $this->assertSame(80, imagesx($decoded));
        $this->assertSame(40, imagesy($decoded));
        $this->assertSame(['jpg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP][$to], getimagesizefromstring($result)[2]);
        if ($from !== 'jpg' && $to !== 'jpg') {
            $this->assertGreaterThan(50, imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha']);
        }
        $this->assertSame(strlen($result), $job->output_size);
    }

    public function test_resize_uses_a_bounding_box_preserves_ratio_and_does_not_upscale(): void
    {
        foreach ([['width' => 20, 'height' => 20], ['width' => 500]] as $options) {
            $id = $this->postJson('/api/v1/conversions', ['tool' => 'redimensionner-image', 'target_format' => 'png', 'options' => $options, 'file' => $this->image()])->assertStatus(202)->json('data.id');
            $job = ConversionJob::findOrFail($id);
            (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));
            $this->assertSame('completed', $job->fresh()->status, $job->fresh()->error_code ?? '');
            $info = getimagesize(Storage::disk('conversions')->path($job->fresh()->output_path));
            $this->assertSame(isset($options['height']) ? [20, 10] : [80, 40], array_slice($info, 0, 2));
        }
    }

    public function test_image_limits_malformed_content_and_options_are_enforced(): void
    {
        config(['conversion.images.max_pixels' => 100]);
        $this->postJson('/api/v1/conversions', ['tool' => 'convertir-image', 'target_format' => 'png', 'file' => $this->image()])->assertStatus(422)->assertJsonPath('error.code', 'IMAGE_TOO_COMPLEX');
        config(['conversion.images.max_pixels' => 6_000_000]);
        $this->postJson('/api/v1/conversions', ['tool' => 'convertir-image', 'target_format' => 'png', 'file' => UploadedFile::fake()->createWithContent('bad.png', 'not an image')])->assertStatus(422);
        foreach ([['quality' => 101], ['width' => -1], ['command' => 'unsafe']] as $options) {
            $this->postJson('/api/v1/conversions', ['tool' => 'convertir-image', 'target_format' => 'png', 'options' => $options, 'file' => $this->image()])->assertStatus(422);
        }
        $this->assertDatabaseCount('conversion_jobs', 0);
    }

    public function test_batches_are_atomic_and_each_file_counts_toward_concurrency(): void
    {
        config(['conversion.limits.guest.concurrent_jobs' => 2]);
        $response = $this->postJson('/api/v1/conversions', ['tool' => 'convertir-image', 'target_format' => 'webp', 'files' => [$this->image(), $this->image('jpg')]])->assertStatus(202)->assertJsonCount(2, 'data');
        $this->assertNotNull($response->json('data.0.batch_id'));
        $this->assertSame($response->json('data.0.batch_id'), $response->json('data.1.batch_id'));
        $this->getJson('/api/v1/me/usage')->assertJsonPath('data.used.active_jobs', 2)->assertJsonPath('data.used.daily_jobs', 2);
        $this->postJson('/api/v1/conversions', ['tool' => 'convertir-image', 'target_format' => 'png', 'file' => $this->image()])->assertStatus(429);
        foreach ($response->json('data') as $item) {
            $job = ConversionJob::findOrFail($item['id']);
            (new ProcessConversion($job->id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));
            $this->assertSame('completed', $job->fresh()->status);
        }
    }

    public function test_rejected_batch_leaves_no_admissions_files_or_usage(): void
    {
        config(['conversion.limits.guest.hourly_jobs' => 1]);
        $this->postJson('/api/v1/conversions', ['tool' => 'convertir-image', 'target_format' => 'png', 'files' => [$this->image(), $this->image()]])->assertStatus(429);
        $this->assertDatabaseCount('conversion_jobs', 0);
        $this->assertDatabaseCount('usage_events', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame([], Storage::disk('conversions')->allFiles('jobs'));
    }

    public function test_oversized_headers_animation_and_batch_limit_are_rejected_before_queue(): void
    {
        $fixture = $this->image();
        $bytes = file_get_contents($fixture->getPathname());
        $huge = substr_replace($bytes, pack('N', 100000), 16, 4);
        $animated = substr($bytes, 0, 33).pack('N', 8).'acTL'.pack('NN', 2, 0).pack('N', crc32('acTL'.pack('NN', 2, 0))).substr($bytes, 33);
        foreach ([$huge => 'IMAGE_TOO_COMPLEX', $animated => 'ANIMATED_IMAGE_UNSUPPORTED'] as $content => $code) {
            $this->postJson('/api/v1/conversions', ['tool' => 'convertir-image', 'target_format' => 'png', 'file' => UploadedFile::fake()->createWithContent('bad.png', $content)])->assertStatus(422)->assertJsonPath('error.code', $code);
        }
        config(['conversion.images.max_batch_files' => 1]);
        $this->postJson('/api/v1/conversions', ['tool' => 'convertir-image', 'target_format' => 'png', 'files' => [$this->image(), $this->image()]])->assertStatus(422);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_output_byte_limit_fails_safely_and_real_worker_can_process_an_image(): void
    {
        config(['conversion.images.max_output_bytes' => 1]);
        $id = $this->postJson('/api/v1/conversions', ['tool' => 'convertir-image', 'target_format' => 'png', 'file' => $this->image()])->assertStatus(202)->json('data.id');
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'conversions', '--once' => true])->assertSuccessful();
        $this->assertSame('failed', ConversionJob::find($id)->status);
        $this->assertSame(0, ConversionJob::find($id)->reserved_bytes);
        config(['conversion.images.max_output_bytes' => 32_000_000]);
        $id = $this->postJson('/api/v1/conversions', ['tool' => 'convertir-image', 'target_format' => 'webp', 'file' => $this->image()])->assertStatus(202)->json('data.id');
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'conversions', '--once' => true])->assertSuccessful();
        $this->assertSame('completed', ConversionJob::find($id)->status);
    }

    public function test_quality_changes_real_jpeg_size_and_compression_reports_actual_reduction(): void
    {
        $image = imagecreatetruecolor(256, 128);
        for ($y = 0; $y < 128; $y++) {
            for ($x = 0; $x < 256; $x++) {
                imagesetpixel($image, $x, $y, imagecolorallocate($image, ($x * 53 + $y * 17) % 256, ($y * 39 + $x * 7) % 256, ($x * 19 + $y * 11) % 256));
            }
        }
        ob_start();
        imagejpeg($image, null, 100);
        $bytes = ob_get_clean();
        $sizes = [];
        foreach ([20, 90] as $quality) {
            $id = $this->postJson('/api/v1/conversions', ['tool' => 'compresser-image', 'target_format' => 'jpg', 'options' => ['quality' => $quality], 'file' => UploadedFile::fake()->createWithContent('noise.jpg', $bytes)])->assertStatus(202)->json('data.id');
            $job = ConversionJob::findOrFail($id);
            (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));
            $job->refresh();
            $this->assertSame('completed', $job->status);
            $sizes[] = $job->output_size;
            $this->getJson('/api/v1/jobs/'.$id)->assertJsonPath('data.reduction_percent', round((1 - $job->output_size / strlen($bytes)) * 100, 1));
        }
        $this->assertLessThan($sizes[1], $sizes[0]);
        $this->assertLessThan(strlen($bytes), $sizes[0]);
    }
}
