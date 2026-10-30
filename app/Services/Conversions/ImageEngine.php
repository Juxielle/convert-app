<?php

namespace App\Services\Conversions;

use App\Models\ConversionJob;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class ImageEngine implements BudgetedEngine
{
    public function __construct(private string $operation = 'convert') {}

    public static function available(): bool
    {
        return config('conversion.images.enabled') && extension_loaded('gd') && function_exists('proc_open')
            && is_file(config('conversion.images.php_binary') ?: PHP_BINARY)
            && function_exists('imagecreatefromwebp') && function_exists('imagewebp')
            && (imagetypes() & IMG_JPG) && (imagetypes() & IMG_PNG) && (imagetypes() & IMG_WEBP);
    }

    public function operationType(): string
    {
        return 'image';
    }

    public function inputFormats(): array
    {
        return ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp']];
    }

    public function targetFormats(): array
    {
        return ['jpg', 'png', 'webp'];
    }

    public function optionRules(): array
    {
        $rules = ['quality' => ['sometimes', 'integer', 'between:1,100']];
        if ($this->operation !== 'compress') {
            $max = config('conversion.images.max_dimension');
            $rules += ['width' => ['sometimes', 'integer', 'min:1', 'max:'.$max], 'height' => ['sometimes', 'integer', 'min:1', 'max:'.$max]];
        }

        return $rules;
    }

    public function inspect(string $path): void
    {
        ImageCodec::header($path, config('conversion.images'));
    }

    public function maxOutputBytes(string $path, array $options): int
    {
        $info = ImageCodec::header($path, config('conversion.images'));
        $ratio = min(1, ($options['width'] ?? $info[0]) / $info[0], ($options['height'] ?? $info[1]) / $info[1]);

        return min(config('conversion.images.max_output_bytes'), max(65536, (int) ceil($info[0] * $info[1] * $ratio * $ratio * 5) + 65536));
    }

    public function convert(ConversionJob $job, string $input, string $output, callable $checkpoint): void
    {
        $process = new Process([config('conversion.images.php_binary') ?: PHP_BINARY,
            '-d', 'memory_limit=128M', '-d', 'allow_url_fopen=0', '-d', 'display_errors=0', '-d', 'log_errors=0',
            base_path('bin/image-worker.php')], base_path());
        $process->setInput(json_encode(['input' => $input, 'output' => $output, 'target' => $job->target_format,
            'options' => $job->options_json, 'limits' => config('conversion.images'), 'max_bytes' => $job->max_output_bytes,
            'lease' => Storage::disk(config('conversion.disk'))->path('leases/'.$job->id.'.lock')], JSON_THROW_ON_ERROR));
        $process->setTimeout(min(180, max(1, $job->timeout_seconds)));
        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $checkpoint();
                usleep(100000);
            }
            $process->checkTimeout();
            if (! $process->isSuccessful() || trim($process->getOutput()) !== 'OK') {
                $code = trim($process->getOutput());
                throw new PipelineException(in_array($code, ['INVALID_IMAGE', 'IMAGE_TOO_COMPLEX', 'ANIMATED_IMAGE_UNSUPPORTED'], true) ? $code : 'INVALID_OUTPUT');
            }
        } catch (ProcessTimedOutException) {
            throw new PipelineException('PROCESS_TIMEOUT');
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    public function validateOutput(string $path, string $target): void
    {
        $info = ImageCodec::header($path, config('conversion.images'));
        if ($info[2] !== ['jpg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP][$target]) {
            throw new PipelineException('INVALID_OUTPUT');
        }
    }
}
