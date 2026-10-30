<?php

use App\Services\Conversions\Owner;
use App\Services\Conversions\Pipeline;
use App\Services\Conversions\PipelineException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeEngine;

// Invoked only by the concurrency test, against its dedicated temporary SQLite file.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$directory = $argv[1];
$participant = $argv[2];
$scenario = $argv[3] ?? 'owner';
config([
    'database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/database.sqlite',
    'filesystems.disks.conversions.root' => $directory.'/files', 'queue.connections.database.connection' => null,
    'conversion.limits.guest.concurrent_jobs' => 1,
    'conversion.max_active_jobs' => $scenario === 'global' ? 1 : 20,
    'conversion.global_storage_bytes' => $scenario === 'storage' ? 200 : 2_000_000_000,
]);
DB::purge();
file_put_contents($directory.'/ready-'.$participant, 'ready');
$deadline = microtime(true) + 10;
while (! file_exists($directory.'/go')) {
    if (microtime(true) > $deadline) {
        exit(2);
    }
    usleep(10000);
}
try {
    $file = UploadedFile::fake()->createWithContent('test.txt', 'Harmless text for concurrent admission.');
    app(Pipeline::class)->admit(
        new Owner($scenario === 'owner' ? str_repeat('a', 64) : hash('sha256', 'participant:'.$participant), null, str_repeat('b', 64)),
        $file, 'pipeline-test', 'txt', [], new FakeEngine,
    );
    echo 'admitted';
} catch (PipelineException $exception) {
    echo $exception->errorCode;
}
