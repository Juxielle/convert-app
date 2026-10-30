<?php

use App\Services\Conversions\ImageCodec;
use App\Services\Conversions\PipelineException;

// Only invoked by ImageEngine; no HTTP entry point and no client-provided command arguments.
require dirname(__DIR__).'/vendor/autoload.php';
try {
    $task = json_decode(stream_get_contents(STDIN, 16384), true, 32, JSON_THROW_ON_ERROR);
    // Keep files leased if Laravel crashes while native GD is still running.
    $lease = fopen($task['lease'], 'c+');
    if (! $lease || ! flock($lease, LOCK_SH | LOCK_NB)) {
        throw new PipelineException('INVALID_OUTPUT');
    }
    ImageCodec::run($task);
    echo 'OK';
} catch (Throwable $error) {
    $code = $error instanceof PipelineException ? $error->errorCode : 'INVALID_IMAGE';
    echo $code;
    exit(1);
} finally {
    if (isset($lease) && is_resource($lease)) {
        flock($lease, LOCK_UN);
        fclose($lease);
    }
}
