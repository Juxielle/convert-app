<?php

namespace App\Console\Commands;

use App\Models\ConversionJob;
use App\Models\UsageEvent;
use App\Services\Conversions\Pipeline;
use App\Services\Conversions\WorkerLease;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReconcileConversions extends Command
{
    protected $signature = 'conversions:reconcile';

    protected $description = 'Expire les résultats, récupère les réservations et nettoie les tâches abandonnées.';

    public function handle(Pipeline $pipeline): int
    {
        ConversionJob::query()->select('id')->chunkById(100, function ($jobs) use ($pipeline): void {
            foreach ($jobs as $item) {
                $pipeline->lock->run(function () use ($item, $pipeline): void {
                    $job = ConversionJob::find($item->id);
                    if (! $job) {
                        return;
                    }
                    if ($job->status === 'processing') {
                        // Never purge a live worker, even if retention has elapsed.
                        if ($job->heartbeat_at && $job->heartbeat_at->gt(now()->subSeconds(config('conversion.stale_seconds')))) {
                            return;
                        }
                        $lease = WorkerLease::acquire($job->id);
                        if (! $lease) {
                            return;
                        }
                        try {
                            $pipeline->finish($job, $job->cancel_requested_at ? 'cancelled' : 'failed', 'WORKER_LOST', true);
                        } finally {
                            $lease->release();
                        }
                    } elseif (in_array($job->status, ['uploaded', 'queued'], true)
                        && $job->created_at->lte(now()->subSeconds(config('conversion.max_queue_seconds')))) {
                        $pipeline->finish($job, 'failed', 'QUEUE_ABANDONED', true);
                    } elseif ($job->expires_at->isPast()) {
                        $pipeline->removePendingQueueRow($job);
                        $job->status = 'expired';
                        $pipeline->purgeFiles($job);
                        $job->save();
                        UsageEvent::where('conversion_job_id', $job->id)->update(['status' => 'expired']);
                    } elseif (! in_array($job->status, ConversionJob::ACTIVE, true) && ($job->delete_requested_at || $job->status !== 'completed')) {
                        $pipeline->purgeFiles($job);
                        $job->save();
                    }
                    if ($job->exists && $job->reserved_bytes === 0 && ($job->delete_requested_at || ($job->history_expires_at ? ! $job->history_expires_at->isFuture() : $job->created_at->lt(now()->subDay())))) {
                        $job->delete();
                    }
                });
            }
        });

        $disk = Storage::disk(config('conversion.disk'));
        foreach ($disk->files('staging') as $path) {
            $id = pathinfo($path, PATHINFO_FILENAME);
            if (! Str::isUuid($id)) {
                continue;
            }
            $pipeline->lock->run(function () use ($disk, $path, $id): void {
                if ($disk->lastModified($path) < now()->subMinutes(10)->timestamp) {
                    $lease = WorkerLease::acquire($id);
                    if ($lease) {
                        try {
                            $disk->delete($path);
                        } finally {
                            $lease->release();
                        }
                    }
                }
            });
        }
        foreach ($disk->directories('jobs') as $directory) {
            $id = basename($directory);
            if (! Str::isUuid($id)) {
                continue;
            }
            $pipeline->lock->run(function () use ($id, $directory, $disk): void {
                // Shared lock prevents racing an uncommitted admission or publication.
                if (! ConversionJob::whereKey($id)->exists() && filemtime($disk->path($directory)) < now()->subMinutes(10)->timestamp) {
                    $disk->deleteDirectory($directory);
                }
            });
        }
        foreach ($disk->files('leases') as $path) {
            $id = pathinfo($path, PATHINFO_FILENAME);
            if (! Str::isUuid($id)) {
                continue;
            }
            $pipeline->lock->run(function () use ($id, $path, $disk): void {
                if (! ConversionJob::whereKey($id)->exists() && $disk->lastModified($path) < now()->subDay()->timestamp) {
                    $lease = WorkerLease::acquire($id);
                    if ($lease) {
                        $lease->release();
                        $disk->delete($path);
                    }
                }
            });
        }
        UsageEvent::where('created_at', '<', now()->subDays(31))->delete();
        $this->info('Réconciliation des conversions terminée.');

        return self::SUCCESS;
    }
}
