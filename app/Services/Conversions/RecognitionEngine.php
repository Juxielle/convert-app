<?php

namespace App\Services\Conversions;

use App\Models\ConversionJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class RecognitionEngine implements BudgetedEngine
{
    public const TOOLS = ['ocr-image', 'transcrire-media'];

    public function __construct(public string $tool) {}

    public static function fingerprints(): array
    {
        return ['config/recognition-languages.json', 'bin/recognition-worker.py', 'bin/recognition_limits.py', 'bin/media-worker.py', 'bin/media_limits.py'];
    }

    public static function assets(string $tool): array
    {
        $common = ['python' => config('recognition.python')];

        if ($tool !== 'ocr-image') {
            return $common + ['whisper' => config('recognition.whisper'), 'model' => config('recognition.model'), 'ffmpeg' => config('conversion.media.ffmpeg'), 'ffprobe' => config('conversion.media.ffprobe')];
        }
        $assets = $common + ['tesseract' => config('recognition.tesseract')];
        foreach (RecognitionLanguages::catalog() as $language) {
            $assets[$language['ocr']] = config('recognition.tessdata').'/'.$language['ocr'].'.traineddata';
        }

        return $assets;
    }

    public static function available(string $tool): bool
    {
        if (! config('recognition.enabled') || ! function_exists('proc_open')) {
            return false;
        }
        foreach (self::assets($tool) as $path) {
            if (! is_file($path)) {
                return false;
            }
        }
        if (app()->environment('testing') && config('recognition.testing_ready')) {
            return true;
        }
        $marker = base_path('.tools/recognition-ready.json');
        if (! is_file($marker)) {
            return false;
        }
        $proof = json_decode(file_get_contents($marker), true);
        foreach (self::fingerprints() as $path) {
            if (($proof['hashes'][$path] ?? '') !== hash_file('sha256', base_path($path))) {
                return false;
            }
        }
        foreach (self::assets($tool) as $name => $path) {
            $asset = $proof['assets'][$name] ?? [];
            if (($asset['path'] ?? '') !== $path || ($asset['size'] ?? 0) !== filesize($path) || ($asset['mtime'] ?? 0) !== filemtime($path)) {
                return false;
            }
        }

        return in_array($tool, $proof['tools'] ?? [], true);
    }

    public function operationType(): string
    {
        return $this->tool === 'ocr-image' ? 'ocr' : 'transcription';
    }

    public function inputFormats(): array
    {
        return $this->tool === 'ocr-image' ? (new ImageEngine)->inputFormats() : (new MediaEngine('convertir-audio'))->inputFormats() + (new MediaEngine('convertir-video'))->inputFormats();
    }

    public function targetFormats(): array
    {
        return ['txt'];
    }

    public function optionRules(): array
    {
        $language = ['language' => ['sometimes', 'string', Rule::in(array_column(RecognitionLanguages::options($this->tool), 'code'))]];

        return $this->tool === 'ocr-image' ? $language + ['layout' => ['sometimes', 'string', 'in:auto,block,sparse']] : $language;
    }

    public function inspect(string $path): void
    {
        $max = config($this->tool === 'ocr-image' ? 'recognition.max_image_bytes' : 'recognition.max_media_bytes');
        if (filesize($path) < 1 || filesize($path) > $max) {
            throw new PipelineException('FILE_TOO_LARGE', 413);
        }
        if ($this->tool === 'ocr-image') {
            ImageCodec::header($path, config('recognition'));
        }
    }

    public function maxOutputBytes(string $path, array $options): int
    {
        return config('recognition.max_work_bytes');
    }

    public function convert(ConversionJob $job, string $input, string $output, callable $checkpoint): void
    {
        $process = new Process([config('recognition.python'), '-I', base_path('bin/recognition-worker.py')], dirname($input));
        $process->setInput(json_encode(['input' => $input, 'output' => $output, 'tool' => $this->tool, 'options' => $job->options_json,
            'timeout' => $job->timeout_seconds, 'limits' => array_replace(config('conversion.media'), collect(config('recognition'))->except(['python', 'tesseract', 'tessdata', 'whisper', 'model'])->all(), ['max_pixels' => config($this->tool === 'ocr-image' ? 'recognition.max_pixels' : 'conversion.media.max_pixels')]),
            'ffmpeg' => config('conversion.media.ffmpeg'), 'ffprobe' => config('conversion.media.ffprobe'), 'tesseract' => config('recognition.tesseract'),
            'tessdata' => config('recognition.tessdata'), 'whisper' => config('recognition.whisper'), 'model' => config('recognition.model'),
            'lease' => Storage::disk(config('conversion.disk'))->path('leases/'.$job->id.'.lock')], JSON_THROW_ON_ERROR));
        $process->setTimeout($job->timeout_seconds);
        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $checkpoint();
                $bytes = 0;
                clearstatcache();
                foreach (Storage::disk(config('conversion.disk'))->allFiles('jobs/'.$job->id) as $path) {
                    // The native process removes normalized/text scratch files as it finishes.
                    // Inventory must tolerate that deletion between listing and stat.
                    $size = @filesize(Storage::disk(config('conversion.disk'))->path($path));
                    if ($size !== false) {
                        $bytes += $size;
                    }
                }
                if ($bytes > $job->reserved_bytes) {
                    throw new PipelineException('OUTPUT_LIMIT_EXCEEDED');
                }
                usleep(100000);
            }
            $process->checkTimeout();
            $result = json_decode(trim($process->getOutput()), true);
            if (! $process->isSuccessful() || ($result['status'] ?? '') !== 'OK') {
                $code = $result['error'] ?? 'RECOGNITION_FAILED';
                if (! in_array($code, ['PROCESS_TIMEOUT', 'INVALID_IMAGE', 'IMAGE_TOO_COMPLEX', 'INVALID_OPTIONS', 'MEDIA_TOO_COMPLEX', 'MISSING_MEDIA_STREAM', 'NO_SPEECH', 'NO_TEXT_FOUND', 'OUTPUT_LIMIT_EXCEEDED', 'UNSUPPORTED_MEDIA_CODEC', 'PROTECTED_MEDIA', 'INVALID_MEDIA'], true)) {
                    $code = 'ENGINE_FAILED';
                }
                throw new PipelineException($code);
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
        if (! is_file($path) || filesize($path) < 1 || filesize($path) > config('recognition.max_text_bytes')) {
            throw new PipelineException('INVALID_OUTPUT');
        }
        $text = file_get_contents($path);
        if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0") || trim($text) === '') {
            throw new PipelineException('INVALID_OUTPUT');
        }
    }
}
