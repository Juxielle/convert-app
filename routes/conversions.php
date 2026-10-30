<?php

use App\Http\Controllers\ConversionController;
use App\Http\Controllers\ExternalController;
use Illuminate\Support\Facades\Route;

// Loaded from web.php: encrypted session cookies and CSRF protection are mandatory.
Route::prefix('api/v1')->middleware('throttle:conversion-api')->group(function (): void {
    Route::get('/social-downloads/sources', [ExternalController::class, 'sources']);
    Route::post('/social-downloads', [ExternalController::class, 'store'])->middleware('throttle:conversion-upload');
    Route::get('/me/usage', [ConversionController::class, 'usage']);
    Route::get('/jobs', [ConversionController::class, 'index']);
    Route::get('/jobs/{conversion}', [ConversionController::class, 'show'])->whereUuid('conversion');
    Route::get('/jobs/{conversion}/download', [ConversionController::class, 'download'])->whereUuid('conversion');
    Route::post('/jobs/{conversion}/cancel', [ConversionController::class, 'cancel'])->whereUuid('conversion');
    Route::delete('/jobs/{conversion}', [ConversionController::class, 'destroy'])->whereUuid('conversion');
    Route::post('/conversions', [ConversionController::class, 'store'])->middleware('throttle:conversion-upload');
});
