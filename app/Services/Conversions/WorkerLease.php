<?php

namespace App\Services\Conversions;

use Illuminate\Support\Facades\Storage;

final class WorkerLease
{
    private function __construct(private mixed $handle) {}

    public static function acquire(string $id, bool $shared = false): ?self
    {
        $disk = Storage::disk(config('conversion.disk'));
        $disk->makeDirectory('leases');
        $handle = fopen($disk->path('leases/'.$id.'.lock'), 'c+');
        if (! is_resource($handle)) {
            throw new PipelineException('STORAGE_FAILED', 500);
        }
        if (! flock($handle, ($shared ? LOCK_SH : LOCK_EX) | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return new self($handle);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
