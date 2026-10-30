<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminPlanController;
use App\Http\Controllers\SubscriptionController;
use App\Services\PlanCatalog;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {
    Route::middleware(['auth', 'can:admin', 'throttle:conversion-api'])->prefix('admin')->group(function (): void {
        Route::get('/overview', [AdminController::class, 'overview']);
        Route::get('/jobs', [AdminController::class, 'jobs']);
        Route::get('/settings', [AdminController::class, 'settings']);
        Route::patch('/settings', [AdminController::class, 'update']);
        Route::get('/audit', [AdminController::class, 'audit']);
        Route::get('/plans', [AdminPlanController::class, 'index']);
        Route::post('/plans', [AdminPlanController::class, 'store']);
        Route::patch('/plans/{plan}', [AdminPlanController::class, 'update']);
        Route::get('/subscriptions', [AdminPlanController::class, 'subscriptions']);
    });
    Route::get('/plans', fn () => response()->json(['data' => app(PlanCatalog::class)->publicPlans()]))->middleware('throttle:catalog');
    Route::get('/auth/session', [AccountController::class, 'session']);
    Route::get('/payment-methods', [SubscriptionController::class, 'paymentMethods'])->middleware('throttle:catalog');
    Route::get('/me/subscription', [SubscriptionController::class, 'show'])->middleware('throttle:conversion-api');
    Route::post('/auth/register', [AccountController::class, 'register'])->middleware('throttle:account')->name('account.register');
    Route::post('/auth/login', [AccountController::class, 'login'])->middleware('throttle:account')->name('account.login');
    Route::post('/auth/forgot-password', [AccountController::class, 'forgot'])->middleware('throttle:account')->name('account.forgot');
    Route::post('/auth/reset-password', [AccountController::class, 'reset'])->middleware('throttle:account')->name('account.reset');
    Route::middleware(['auth', 'throttle:conversion-api'])->group(function (): void {
        Route::post('/subscriptions/checkout', [SubscriptionController::class, 'checkout']);
        Route::get('/subscriptions/{subscription}/payment-status', [SubscriptionController::class, 'paymentStatus'])->whereNumber('subscription');
        Route::post('/subscriptions/quote', [SubscriptionController::class, 'quote'])->middleware('throttle:account');
        Route::post('/subscriptions/trial', [SubscriptionController::class, 'trial'])->middleware('throttle:account');
        Route::patch('/subscriptions/period', [SubscriptionController::class, 'period']);
        Route::post('/subscriptions/cancel-trial', [SubscriptionController::class, 'cancelTrial']);
        Route::post('/auth/logout', [AccountController::class, 'logout']);
        Route::patch('/me/profile', [AccountController::class, 'profile'])->name('account.profile');
        Route::patch('/me/password', [AccountController::class, 'password'])->name('account.password');
    });
});
