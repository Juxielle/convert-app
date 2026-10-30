<?php

namespace App\Services\Conversions;

use Illuminate\Support\Facades\DB;

class PipelineLock
{
    public function run(callable $callback, int $attempts = 5): mixed
    {
        return DB::transaction(function () use ($callback) {
            // The first statement acquires a write lock even on SQLite (no ineffective FOR UPDATE).
            $updated = DB::table('conversion_locks')->where('id', 1)->increment('version');
            if ($updated !== 1) {
                throw new PipelineException('PIPELINE_UNAVAILABLE', 503);
            }

            return $callback();
        }, $attempts);
    }
}
