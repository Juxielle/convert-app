<?php

use App\Models\User;
use App\Services\Subscriptions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['billing.trial_requires_payment' => false]);
[$script, $directory, $participant, $userId, $intentId] = $argv;
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/database.sqlite']);
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
    $subscription = app(Subscriptions::class)->confirm(User::findOrFail($userId), $intentId, 'trial');
    echo 'trialing:'.$subscription->id;
} catch (ValidationException) {
    echo 'ineligible';
}
