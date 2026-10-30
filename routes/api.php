<?php

use App\Http\Controllers\ToolController;
use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::post('/billing/saspay/webhook', [SubscriptionController::class, 'paymentWebhook'])->middleware('throttle:catalog');

Route::middleware('throttle:catalog')->group(function (): void {
    Route::get('/tools', [ToolController::class, 'index']);
    Route::get('/tools/{slug}', [ToolController::class, 'show']);
    Route::get('/limits', [ToolController::class, 'limits']);
});
