<?php

use App\Models\AuditLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('conversions:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('subscriptions:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();
Schedule::command('model:prune', ['--model' => [AuditLog::class]])->daily();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
