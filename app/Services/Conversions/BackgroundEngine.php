<?php

namespace App\Services\Conversions;

use App\Models\ConversionJob;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class BackgroundEngine extends ImageEngine
{
    public static function available(): bool
    {
        $model = config('conversion.background.model');

        return parent::available() && config('conversion.background.enabled')
            && is_file(config('conversion.background.python')) && is_file($model)
            && hash_file('sha256', $model) === '309c8469258dda742793dce0ebea8e6dd393174f89934733ecc8b14c76f4ddd8'
            && ((app()->environment('testing') && config('conversion.background.testing_ready'))
                || (is_file(base_path('.tools/background-ready.json'))
                    && (json_decode(file_get_contents(base_path('.tools/background-ready.json')), true)['worker_hash'] ?? '') === hash_file('sha256', base_path('bin/background-worker.py'))));
    }

    public function targetFormats(): array
    {
        return ['png'];
    }

    public function optionRules(): array
    {
        return [];
    }

    public function convert(ConversionJob $job, string $input, string $output, callable $checkpoint): void
    {
        $process = new Process([config('conversion.background.python'), '-I', base_path('bin/background-worker.py')], base_path());
        $process->setInput(json_encode([
            'input' => $input, 'output' => $output, 'model' => config('conversion.background.model'),
            'timeout' => $job->timeout_seconds, 'max_bytes' => $job->max_output_bytes,
            'max_pixels' => config('conversion.images.max_pixels'), 'max_dimension' => config('conversion.images.max_dimension'),
            'lease' => Storage::disk(config('conversion.disk'))->path('leases/'.$job->id.'.lock'),
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout($job->timeout_seconds);
        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $checkpoint();
                usleep(100000);
            }
            $process->checkTimeout();
            if (! $process->isSuccessful() || trim($process->getOutput()) !== 'OK') {
                throw new PipelineException('ENGINE_FAILED');
            }
        } catch (ProcessTimedOutException) {
            throw new PipelineException('PROCESS_TIMEOUT');
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }
}
