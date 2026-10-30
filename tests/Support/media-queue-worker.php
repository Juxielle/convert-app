<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$directory = $argv[1];
config([
    'database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/database.sqlite',
    'filesystems.disks.conversions.root' => $directory.'/files', 'queue.connections.database.connection' => null,
    'conversion.media.testing_ready' => true, 'conversion.media.testing_realtime' => true,
    'conversion.max_running_jobs' => 2, 'conversion.media.max_running' => 1,
]);
DB::purge();
Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'conversions', '--max-time' => 12, '--sleep' => 1, '--timeout' => 30]);
