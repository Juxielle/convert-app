<?php

namespace App\Services\External;

use App\Models\ConversionJob;
use App\Services\Conversions\BudgetedEngine;
use App\Services\Conversions\PipelineException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class CommonsEngine implements BudgetedEngine
{
    public function operationType(): string
    {
        return 'social_download';
    }

    public function inputFormats(): array
    {
        return ['json' => ['application/json']];
    }

    public function targetFormats(): array
    {
        return ['webm'];
    }

    public function optionRules(): array
    {
        return [];
    }

    public function inspect(string $path): void
    {
        if (! is_file($path) || filesize($path) > 4096 || ! is_string(json_decode(file_get_contents($path), true)['title'] ?? null)) {
            throw new PipelineException('UNSAFE_SOURCE');
        }
    }

    public function maxOutputBytes(string $path, array $options): int
    {
        return config('external.max_bytes');
    }

    public function convert(ConversionJob $job, string $input, string $output, callable $checkpoint): void
    {
        $metadata = app(CommonsSource::class)->metadata(json_decode(file_get_contents($input), true)['title'], $checkpoint);
        if ($metadata['size'] > $job->max_output_bytes) {
            throw new PipelineException('FILE_TOO_LARGE');
        }
        $stream = fopen($output, 'x+b');
        try {
            app(PinnedTransport::class)->fetch($metadata['download_url'], ['upload.wikimedia.org'], $job->max_output_bytes, $checkpoint, $stream);
        } finally {
            fclose($stream);
        }
        if (filesize($output) !== $metadata['size'] || (new \finfo(FILEINFO_MIME_TYPE))->file($output) !== 'video/webm') {
            throw new PipelineException('SOURCE_INCOMPATIBLE');
        }
        $process = new Process([config('conversion.media.python'), '-I', base_path('bin/external-probe.py')]);
        $process->setInput(json_encode(['input' => $output, 'ffprobe' => config('conversion.media.ffprobe'), 'limits' => config('conversion.media'), 'timeout' => 10,
            'lease' => Storage::disk(config('conversion.disk'))->path('leases/'.$job->id.'.lock')]));
        $process->setTimeout(12)->start();
        try {
            while ($process->isRunning()) {
                $process->checkTimeout();
                $checkpoint();
                usleep(100000);
            }
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        if (! $process->isSuccessful() || ! str_contains($process->getOutput(), '"OK"')) {
            throw new PipelineException('SOURCE_INCOMPATIBLE');
        }
        $job->options_json = ['attribution' => $metadata['attribution']];
        if ($job->exists) {
            $job->save();
        }
    }

    public function validateOutput(string $path, string $target): void
    {
        if (! is_file($path) || filesize($path) < 1 || filesize($path) > config('external.max_bytes')) {
            throw new PipelineException('INVALID_OUTPUT');
        }
    }
}
