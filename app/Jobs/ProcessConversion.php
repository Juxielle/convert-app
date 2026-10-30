<?php

namespace App\Jobs;

use App\Models\ConversionJob;
use App\Services\Conversions\EngineRegistry;
use App\Services\Conversions\Pipeline;
use App\Services\Conversions\PipelineException;
use App\Services\Conversions\WorkerLease;
use App\Services\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessConversion implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240;

    public int $tries = 0; // Capacity releases are bounded by the absolute retryUntil deadline.

    public bool $failOnTimeout = true;

    public function __construct(public string $conversionId, public int $expiresTimestamp) {}

    public function retryUntil(): \DateTimeInterface
    {
        return Carbon::createFromTimestamp($this->expiresTimestamp);
    }

    public function handle(Pipeline $pipeline, EngineRegistry $engines): void
    {
        $claim = $pipeline->lock->run(function () use ($pipeline) {
            $job = ConversionJob::find($this->conversionId);
            if (! $job || $job->status !== 'queued') {
                return null;
            }
            if ($job->expires_at->isPast()) {
                $pipeline->finish($job, 'expired');

                return null;
            }
            if (! $job->priority && $job->created_at->gt(now()->subMinute()) && ConversionJob::where('status', 'queued')->where('priority', true)->where('expires_at', '>', now())->exists()) {
                return false; // Prioritize paid/trial work; older standard jobs cannot starve indefinitely.
            }
            if (ConversionJob::where('status', 'processing')->count() >= config('conversion.max_running_jobs')) {
                return false;
            }
            if (in_array($job->operation_type, ['ocr', 'transcription'], true) && ConversionJob::where('status', 'processing')->whereIn('operation_type', ['ocr', 'transcription'])->count() >= config('recognition.max_running')) {
                return false;
            }
            if ($job->operation_type === 'social_download' && ConversionJob::where('status', 'processing')->where('operation_type', 'social_download')->exists()) {
                return false;
            }
            if (in_array($job->operation_type, ['audio', 'video'], true) && ConversionJob::where('status', 'processing')->whereIn('operation_type', ['audio', 'video'])->count() >= config('conversion.media.max_running')) {
                return false;
            }
            $job->update(['status' => 'processing', 'started_at' => now(), 'heartbeat_at' => now()]);

            return $job;
        });
        if ($claim === false) {
            $this->release(5);

            return;
        }
        if (! $claim) {
            return;
        }
        $job = $claim;
        $started = hrtime(true);
        $lastProgress = null;
        $checkpoint = function (?int $progress = null) use ($job, $started, &$lastProgress): void {
            $job->refresh();
            if ($job->operation_type === 'social_download' && ! app(Settings::class)->toolEnabled('telechargement-autorise')) {
                throw new PipelineException('TOOL_UNAVAILABLE');
            }
            if ($job->status !== 'processing' || $job->cancel_requested_at || $job->delete_requested_at) {
                throw new PipelineException('CANCELLED');
            }
            if ((hrtime(true) - $started) / 1e9 >= $job->timeout_seconds || $job->expires_at->isPast()) {
                throw new PipelineException('PROCESS_TIMEOUT');
            }
            if ($progress !== null) {
                $lastProgress = max($lastProgress ?? 0, max(0, min(99, $progress)));
            }
            $job->update(['heartbeat_at' => now(), 'progress' => $lastProgress]);
        };

        $lease = null;
        try {
            $lease = WorkerLease::acquire($job->id, true);
            if (! $lease) {
                return;
            }
            $engine = $engines->get($job->tool);
            $disk = Storage::disk(config('conversion.disk'));
            $input = $disk->path($job->input_path);
            $outputPath = 'jobs/'.$job->id.'/output.'.$job->target_format;
            $output = $disk->path($outputPath);
            $checkpoint();
            $engine->inspect($input);
            $checkpoint();
            $engine->convert($job, $input, $output, $checkpoint);
            $checkpoint();
            clearstatcache(true, $output);
            if (! is_file($output) || is_link($output) || filesize($output) < 1 || filesize($output) > $job->max_output_bytes) {
                throw new PipelineException('INVALID_OUTPUT');
            }
            $engine->validateOutput($output, $job->target_format);
            $checkpoint();
            $pipeline->lock->run(function () use ($pipeline, $job, $outputPath, $output) {
                $job->refresh();
                if ($job->status !== 'processing') {
                    return;
                }
                if ($job->cancel_requested_at || $job->delete_requested_at) {
                    $pipeline->finish($job, 'cancelled');
                } elseif ($job->expires_at->isPast()) {
                    $pipeline->finish($job, 'expired');
                } else {
                    $job->output_path = $outputPath;
                    $job->output_size = filesize($output);
                    $job->progress = 100;
                    $pipeline->finish($job, 'completed');
                }
            });
        } catch (\Throwable $error) {
            $code = $error instanceof PipelineException ? $error->errorCode : 'ENGINE_FAILED';
            $pipeline->lock->run(function () use ($pipeline, $job, $code) {
                $fresh = ConversionJob::find($job->id);
                if (! $fresh || $fresh->status !== 'processing') {
                    return;
                }
                $cancelled = $fresh->cancel_requested_at || $fresh->delete_requested_at || $code === 'CANCELLED';
                $pipeline->finish($fresh, $cancelled ? 'cancelled' : 'failed', $cancelled ? null : $code,
                    in_array($code, ['ENGINE_FAILED', 'TOOL_UNAVAILABLE', 'INVALID_OUTPUT', 'STORAGE_FAILED'], true));
            });
            Log::warning('conversion.finished_without_result', ['job_id' => $this->conversionId, 'code' => $code, 'exception_type' => $error::class]);
        } finally {
            $lease?->release();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $pipeline = app(Pipeline::class);
        $pipeline->lock->run(function () use ($pipeline) {
            $job = ConversionJob::find($this->conversionId);
            if ($job && in_array($job->status, ConversionJob::ACTIVE, true)) {
                $lease = $job->status === 'processing' ? WorkerLease::acquire($job->id) : null;
                if ($job->status === 'processing' && ! $lease) {
                    return; // A live process still owns the files; reconcile after its termination.
                }
                try {
                    $pipeline->finish($job, $job->cancel_requested_at ? 'cancelled' : 'failed', 'WORKER_FAILED', true);
                } finally {
                    $lease?->release();
                }
            }
        });
    }
}
