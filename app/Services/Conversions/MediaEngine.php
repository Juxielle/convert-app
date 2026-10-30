<?php

namespace App\Services\Conversions;

use App\Models\ConversionJob;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class MediaEngine implements BudgetedEngine
{
    public const TOOLS = ['convertir-audio', 'extraire-audio', 'convertir-video', 'compresser-video'];

    public function __construct(public string $tool) {}

    public static function available(string $tool): bool
    {
        if (! config('conversion.media.enabled') || ! function_exists('proc_open') || ! is_file(config('conversion.media.python'))
            || ! is_file(config('conversion.media.ffmpeg')) || ! is_file(config('conversion.media.ffprobe'))) {
            return false;
        }
        if (app()->environment('testing') && config('conversion.media.testing_ready')) {
            return true;
        }
        $path = base_path('.tools/media-ready.json');
        if (! is_file($path)) {
            return false;
        }
        $proof = json_decode(file_get_contents($path), true);
        if (! is_array($proof) || ! in_array($tool, $proof['tools'] ?? [], true)) {
            return false;
        }
        foreach (['ffmpeg', 'ffprobe'] as $name) {
            $binary = config('conversion.media.'.$name);
            if (($proof[$name]['path'] ?? '') !== $binary || ($proof[$name]['size'] ?? 0) !== filesize($binary) || ($proof[$name]['mtime'] ?? 0) !== filemtime($binary)) {
                return false;
            }
        }

        return ($proof['worker_hash'] ?? '') === hash_file('sha256', base_path('bin/media-worker.py'))
            && ($proof['limits_hash'] ?? '') === hash_file('sha256', base_path('bin/media_limits.py'))
            && ($proof['python'] ?? '') === config('conversion.media.python');
    }

    public function operationType(): string
    {
        return in_array($this->tool, ['convertir-audio', 'extraire-audio'], true) ? 'audio' : 'video';
    }

    public function inputFormats(): array
    {
        if ($this->tool === 'convertir-audio') {
            return ['mp3' => ['audio/mpeg', 'audio/mp3'], 'wav' => ['audio/x-wav', 'audio/wav'], 'aac' => ['audio/aac', 'audio/x-hx-aac-adts'], 'm4a' => ['audio/mp4', 'video/mp4', 'audio/x-m4a'], 'flac' => ['audio/flac', 'audio/x-flac'], 'ogg' => ['audio/ogg', 'application/ogg'], 'opus' => ['audio/ogg', 'application/ogg']];
        }

        return ['mp4' => ['video/mp4', 'application/mp4'], 'mov' => ['video/quicktime'], 'mkv' => ['video/x-matroska', 'application/x-matroska'], 'avi' => ['video/x-msvideo', 'video/avi'], 'webm' => ['video/webm']];
    }

    public function targetFormats(): array
    {
        return $this->operationType() === 'audio' ? ['mp3', 'wav', 'aac', 'm4a', 'flac', 'ogg', 'opus'] : ['mp4', 'mov', 'mkv', 'avi', 'webm'];
    }

    public function optionRules(): array
    {
        $rules = ['start' => ['sometimes', 'numeric', 'min:0', 'max:300'], 'duration' => ['sometimes', 'numeric', 'min:0.1', 'max:300']];

        return $this->operationType() === 'audio'
            ? [...$rules, 'bitrate' => ['sometimes', 'integer', 'in:64,128,192,256,320'], 'sample_rate' => ['sometimes', 'integer', 'in:22050,44100,48000'], 'channels' => ['sometimes', 'integer', 'in:1,2']]
            : [...$rules, 'profile' => ['sometimes', 'string', 'in:light,balanced,quality'], 'resolution' => ['sometimes', 'integer', 'in:360,480,720'], 'fps' => ['sometimes', 'integer', 'in:15,24,30']];
    }

    public function inspect(string $path): void
    {
        $maximum = config('conversion.media.'.($this->tool === 'convertir-audio' ? 'max_audio_bytes' : 'max_video_bytes'));
        if (filesize($path) < 1 || filesize($path) > $maximum) {
            throw new PipelineException('FILE_TOO_LARGE', 413);
        }
        // Full inspection belongs to the isolated worker; never run ffprobe in an HTTP request.
    }

    public function maxOutputBytes(string $path, array $options): int
    {
        return config('conversion.media.max_output_bytes');
    }

    public function convert(ConversionJob $job, string $input, string $output, callable $checkpoint): void
    {
        $process = new Process([config('conversion.media.python'), '-I', base_path('bin/media-worker.py')], dirname($input));
        $process->setInput(json_encode(['input' => $input, 'output' => $output, 'tool' => $this->tool, 'target' => $job->target_format,
            'options' => $job->options_json, 'limits' => collect(config('conversion.media'))->except(['python', 'ffmpeg', 'ffprobe'])->all(),
            'ffmpeg' => config('conversion.media.ffmpeg'), 'ffprobe' => config('conversion.media.ffprobe'),
            'timeout' => $job->timeout_seconds, 'max_bytes' => $job->max_output_bytes,
            'realtime' => app()->environment('testing') && config('conversion.media.testing_realtime', false),
            'lease' => Storage::disk(config('conversion.disk'))->path('leases/'.$job->id.'.lock')], JSON_THROW_ON_ERROR));
        $process->setTimeout($job->timeout_seconds);
        $pending = '';
        $result = null;
        $consume = function () use ($process, &$pending, &$result, $checkpoint): void {
            $pending .= $process->getIncrementalOutput();
            if (strlen($pending) > 65536) {
                throw new PipelineException('INVALID_MEDIA');
            }
            while (($end = strpos($pending, "\n")) !== false) {
                $line = substr($pending, 0, $end);
                $pending = substr($pending, $end + 1);
                $event = json_decode(trim($line), true);
                if (! is_array($event)) {
                    continue;
                }
                if (isset($event['progress']) && is_int($event['progress'])) {
                    $checkpoint($event['progress']);
                }
                if (isset($event['status']) || isset($event['error'])) {
                    $result = $event;
                }
            }
        };
        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $checkpoint();
                $consume();
                clearstatcache(true, $output);
                if (is_file($output) && filesize($output) > $job->max_output_bytes) {
                    throw new PipelineException('OUTPUT_LIMIT_EXCEEDED');
                }
                usleep(100000);
            }
            $process->checkTimeout();
            $consume();
            if (! $process->isSuccessful() || ($result['status'] ?? null) !== 'OK') {
                $code = $result['error'] ?? 'INVALID_MEDIA';
                if (in_array($code, ['WORKER_LIMITS_FAILED', 'WORKER_LEASE_FAILED', 'PRIVILEGED_WORKER'], true)) {
                    throw new PipelineException('ENGINE_FAILED');
                }
                throw new PipelineException(in_array($code, ['INVALID_MEDIA', 'MEDIA_TOO_COMPLEX', 'UNSUPPORTED_MEDIA_CODEC', 'PROTECTED_MEDIA', 'MISSING_MEDIA_STREAM', 'INVALID_OPTIONS', 'OUTPUT_LIMIT_EXCEEDED', 'INVALID_OUTPUT', 'PROCESS_TIMEOUT'], true) ? $code : 'INVALID_MEDIA');
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
        if (! is_file($path) || filesize($path) < 1 || filesize($path) > config('conversion.media.max_output_bytes')) {
            throw new PipelineException('INVALID_OUTPUT');
        }
        // The isolated worker already checks duration, stream counts, output codecs and dimensions with ffprobe.
    }
}
