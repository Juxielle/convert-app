<?php

// Real HTTP test entrypoint: no production data and no CSRF bypass.
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['filesystems.disks.conversions.root' => getenv('HTTP_TEST_STORAGE')]);
$app->handleRequest(Request::capture());
