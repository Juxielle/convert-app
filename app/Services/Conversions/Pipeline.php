<?php

namespace App\Services\Conversions;

use App\Jobs\ProcessConversion;
use App\Models\ConversionJob;
use App\Models\UsageEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Pipeline
{
    public function __construct(public PipelineLock $lock) {}

    public function admit(Owner $owner, UploadedFile $file, string $tool, string $target, array $options, Engine $engine, ?string $batchId = null): ConversionJob
    {
        $queue = Queue::connection('database');
        // Atomic queue publication relies on the EXACT same DB connection and an immediate insert.
        if (! $queue instanceof DatabaseQueue || $queue->getDatabase() !== DB::connection()
            || config('queue.connections.database.after_commit')) {
            throw new PipelineException('PIPELINE_UNAVAILABLE', 503);
        }
        $id = (string) Str::uuid();
        try {
            return $this->lock->run(function () use ($owner, $file, $tool, $target, $options, $engine, $id, $queue, $batchId) {
                $limits = $owner->limits();
                $owner->authorizeTool($tool);
                $external = $engine->operationType() === 'social_download';
                if ($external) {
                    $limits['hourly_jobs'] = min($limits['hourly_jobs'], config('external.hourly_jobs'));
                    $limits['daily_jobs'] = min($limits['daily_jobs'], config('external.daily_jobs'));
                }
                $size = filesize($file->getPathname());
                if ($size < 1 || $size > $limits['max_file_bytes']) {
                    throw new PipelineException($size < 1 ? 'INVALID_FILE' : 'FILE_TOO_LARGE', $size < 1 ? 422 : 413);
                }
                $usage = $this->usage($owner, $external);
                $maxOutput = $engine instanceof BudgetedEngine ? $engine->maxOutputBytes($file->getPathname(), $options) : $size * 2;
                if ($external) {
                    $maxOutput = min($maxOutput, $limits['max_file_bytes']);
                }
                $reserve = $size + $maxOutput;
                if ($usage['hourly_jobs'] >= $limits['hourly_jobs'] || $usage['daily_jobs'] >= $limits['daily_jobs']
                    || (isset($limits['monthly_jobs']) && $usage['monthly_jobs'] >= $limits['monthly_jobs'])
                    || $usage['active_jobs'] >= $limits['concurrent_jobs']) {
                    throw new PipelineException('QUOTA_EXCEEDED', 429);
                }
                if ($limits['storage_bytes'] < $usage['reserved_bytes'] + $reserve) {
                    throw new PipelineException('STORAGE_QUOTA_EXCEEDED', 429);
                }
                if (ConversionJob::whereIn('status', ConversionJob::ACTIVE)->count() >= config('conversion.max_active_jobs')
                    || ConversionJob::sum('reserved_bytes') + $this->orphanBytes() + $reserve > config('conversion.global_storage_bytes')) {
                    throw new PipelineException('SERVER_BUSY', 503);
                }
                $source = strtolower($file->getClientOriginalExtension());
                $path = 'jobs/'.$id.'/input.'.$source;
                $name = Str::limit(preg_replace('/[^a-zA-Z0-9._-]/', '_', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 120, '');
                if ($tool === 'fusionner-pdf') {
                    $name = 'fusion.pdf';
                }
                if ($external) {
                    $name = pathinfo($name, PATHINFO_FILENAME).'.'.$target;
                }
                $job = ConversionJob::create([
                    'id' => $id, 'batch_id' => $batchId, 'user_id' => $owner->userId, 'owner_key' => $owner->key,
                    'session_token_hash' => $owner->sessionHash, 'tool' => $tool, 'operation_type' => $engine->operationType(),
                    'source_format' => $source, 'target_format' => $target, 'original_name' => $name,
                    'status' => 'uploaded', 'input_path' => $path, 'input_size' => $size,
                    'reserved_bytes' => $reserve, 'max_output_bytes' => $maxOutput,
                    'timeout_seconds' => max(1, min(180, $limits['timeout_seconds'], $external ? config('external.timeout_seconds') : 180)), 'options_json' => $options,
                    'expires_at' => now()->addHours($limits['retention_hours']),
                    'history_expires_at' => now()->addHours($limits['history_hours'] ?? 24),
                    'priority' => $owner->entitlements()['priority_processing'] ?? false,
                ]);
                UsageEvent::create([
                    'conversion_job_id' => $id, 'user_id' => $owner->userId, 'owner_key' => $owner->key,
                    'operation_type' => $job->operation_type, 'bytes' => $size, 'status' => 'queued',
                ]);
                $stored = Storage::disk(config('conversion.disk'))->putFileAs('jobs/'.$id, $file, 'input.'.$source);
                if ($stored !== $path) {
                    throw new PipelineException('STORAGE_FAILED', 500);
                }
                $job->update(['status' => 'queued']);
                // This row and the quota/job rows become visible together at transaction commit.
                $queueId = $queue->push(new ProcessConversion($id, $job->expires_at->timestamp), '', $external ? 'external-downloads' : 'conversions');
                if (! $queueId) {
                    throw new PipelineException('PIPELINE_UNAVAILABLE', 503);
                }
                $job->update(['queue_id' => $queueId]);

                return $job;
            });
        } catch (\Throwable $error) {
            // Transaction rollback removes quota/job/queue rows; reconcile also removes crash orphans.
            try {
                Storage::disk(config('conversion.disk'))->deleteDirectory('jobs/'.$id);
            } catch (\Throwable) {
                // Orphans stay under the dedicated private disk and are reaped by reconcile.
            }
            throw $error;
        }
    }

    public function usage(Owner $owner, bool $external = false): array
    {
        $events = UsageEvent::where('owner_key', $owner->key)->where('billable', true);
        $events->where('operation_type', $external ? '=' : '!=', 'social_download');

        return [
            'hourly_jobs' => (clone $events)->where('created_at', '>=', now()->subHour())->count(),
            'daily_jobs' => (clone $events)->where('created_at', '>=', now()->subDay())->count(),
            'monthly_jobs' => (clone $events)->where('created_at', '>=', now()->subDays(config('billing.monthly_window_days')))->count(),
            'active_jobs' => ConversionJob::where('owner_key', $owner->key)->whereIn('status', ConversionJob::ACTIVE)->count(),
            'reserved_bytes' => (int) ConversionJob::where('owner_key', $owner->key)->sum('reserved_bytes'),
        ];
    }

    public function admitBatch(Owner $owner, array $files, string $tool, string $target, array $options, Engine $engine): array
    {
        $created = [];
        $batchId = (string) Str::uuid();
        try {
            return $this->lock->run(function () use ($owner, $files, $tool, $target, $options, $engine, $batchId, &$created) {
                if (count($files) > 1 && ! ($owner->entitlements()['batch_processing'] ?? false)) {
                    throw new PipelineException('BATCH_NOT_INCLUDED', 403);
                }
                foreach ($files as $file) {
                    $created[] = $this->admit($owner, $file, $tool, $target, $options, $engine, $batchId);
                }

                return $created;
            }, 1);
        } catch (\Throwable $error) {
            foreach ($created as $job) {
                try {
                    Storage::disk(config('conversion.disk'))->deleteDirectory('jobs/'.$job->id);
                } catch (\Throwable) {
                }
            }
            throw $error;
        }
    }

    public function admitMerge(Owner $owner, array $files, Engine $engine): ConversionJob
    {
        if (array_sum(array_map(fn ($file) => filesize($file->getPathname()), $files)) > config('conversion.documents.max_input_bytes')) {
            throw new PipelineException('FILE_TOO_LARGE', 413);
        }
        $disk = Storage::disk(config('conversion.disk'));
        $disk->makeDirectory('staging');
        $stagingId = (string) Str::uuid();
        $lease = WorkerLease::acquire($stagingId, true);
        if (! $lease) {
            throw new PipelineException('STORAGE_FAILED', 500);
        }
        $staging = 'staging/'.$stagingId.'.zip';
        $path = $disk->path($staging);
        try {
            $zip = new \ZipArchive;
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new PipelineException('STORAGE_FAILED', 500);
            }
            foreach ($files as $index => $file) {
                $name = sprintf('%03d.pdf', $index);
                $zip->addFile($file->getPathname(), $name);
                $zip->setCompressionName($name, \ZipArchive::CM_STORE);
            }
            $zip->close();

            return $this->admit($owner, new UploadedFile($path, 'documents.zip', 'application/zip', null, true), 'fusionner-pdf', 'pdf', [], $engine);
        } finally {
            try {
                $disk->delete($staging);
            } finally {
                $lease->release();
            }
        }
    }

    private function orphanBytes(): int
    {
        $disk = Storage::disk(config('conversion.disk'));
        $known = array_fill_keys(ConversionJob::pluck('id')->all(), true);
        $bytes = 0;
        foreach ($disk->allFiles('jobs') as $path) {
            $id = explode('/', $path)[1] ?? '';
            if (! isset($known[$id])) {
                $bytes += $disk->size($path);
            }
        }
        foreach ($disk->files('staging') as $path) {
            $bytes += $disk->size($path);
        }

        return $bytes;
    }

    public function cancel(ConversionJob $job, bool $delete = false): ConversionJob
    {
        return $this->lock->run(function () use ($job, $delete) {
            $job->refresh();
            if ($delete) {
                $job->delete_requested_at = now();
            }
            if ($job->status === 'processing') {
                $job->cancel_requested_at = now();
                $job->save();

                return $job;
            }
            if (in_array($job->status, ['uploaded', 'queued'], true)) {
                $this->removePendingQueueRow($job);
                $job->status = 'cancelled';
                $job->completed_at = now();
                UsageEvent::where('conversion_job_id', $job->id)->update(['status' => 'cancelled']);
            }
            if ($delete || $job->status === 'cancelled') {
                $this->purgeFiles($job);
            }
            $job->save();
            if ($delete && $job->reserved_bytes === 0) {
                $job->delete();
            }

            return $job;
        });
    }

    /** Caller must hold the pipeline lock and must never purge a live processing job. */
    public function purgeFiles(ConversionJob $job): void
    {
        try {
            if (Storage::disk(config('conversion.disk'))->deleteDirectory('jobs/'.$job->id)) {
                $job->reserved_bytes = 0;
                $job->output_path = null;
            }
        } catch (\Throwable) {
            // Keep the reservation until cleanup succeeds. Never silently overcommit disk capacity.
        }
    }

    public function finish(ConversionJob $job, string $status, ?string $code = null, bool $refund = false): void
    {
        if (in_array($job->status, ['uploaded', 'queued'], true)) {
            $this->removePendingQueueRow($job);
        }
        $job->status = $status;
        $job->error_code = $code;
        $job->completed_at = now();
        if ($status === 'completed') {
            $disk = Storage::disk(config('conversion.disk'));
            $actual = 0;
            foreach ($disk->allFiles('jobs/'.$job->id) as $path) {
                $actual += $disk->size($path);
            }
            if ($actual > $job->reserved_bytes) {
                throw new PipelineException('OUTPUT_LIMIT_EXCEEDED');
            }
            // Native writers have stopped: retain the real private bytes, including any leftover work files.
            $job->reserved_bytes = $actual;
        }
        if ($status !== 'completed') {
            $this->purgeFiles($job);
        }
        $job->save();
        $update = ['status' => $status];
        if ($refund) {
            $update['billable'] = false;
        }
        UsageEvent::where('conversion_job_id', $job->id)->update($update);
        if ($job->delete_requested_at && $job->reserved_bytes === 0) {
            $job->delete();
        }
    }

    public function removePendingQueueRow(ConversionJob $job): void
    {
        if ($job->queue_id) {
            DB::table(config('queue.connections.database.table'))->where('id', $job->queue_id)->whereNull('reserved_at')->delete();
        }
    }
}
