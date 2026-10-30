<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1].'/database.sqlite',
    'filesystems.disks.conversions.root' => $argv[1].'/files', 'queue.connections.database.connection' => null]);
DB::purge();
Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'conversions', '--once' => true, '--timeout' => 20]);
