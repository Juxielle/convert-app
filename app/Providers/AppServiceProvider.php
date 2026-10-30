<?php

namespace App\Providers;

use App\Models\ConversionJob;
use App\Models\User;
use App\Policies\ConversionJobPolicy;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\SaspayGateway;
use App\Services\Conversions\EngineRegistry;
use App\Services\Conversions\Owner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BillingGateway::class, fn () => new SaspayGateway);
        $this->app->singleton(EngineRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Queue::looping(function ($event): void {
            if (str_contains((string) $event->queue, 'external-downloads')) {
                Cache::put('external-worker-heartbeat', now()->toIso8601String(), 120);
            }
            if (str_contains((string) $event->queue, 'conversions')) {
                Cache::put('conversion-worker-heartbeat', now()->toIso8601String(), 120);
            }
        });
        Gate::policy(ConversionJob::class, ConversionJobPolicy::class);
        Gate::define('admin', fn (User $user) => $user->role === 'admin');
        RateLimiter::for('account', fn (Request $request) => [
            Limit::perMinute(10)->by('auth-ip:'.$request->ip()),
            Limit::perMinute(5)->by('auth-identity:'.hash('sha256', is_string($request->input('email')) ? strtolower($request->input('email')) : '').'|'.$request->ip()),
        ]);
        foreach (['conversion-api' => 120, 'conversion-upload' => 15] as $name => $limit) {
            RateLimiter::for($name, fn (Request $request) => [
                Limit::perMinute($limit)->by('ip:'.$request->ip()),
                Limit::perMinute($limit)->by('owner:'.Owner::fromRequest($request)->key),
            ]);
        }
        RateLimiter::for('catalog', fn (Request $request) => Limit::perMinute(120)
            ->by($request->ip())
            ->response(fn (Request $request, array $headers) => response()->json([
                'error' => ['code' => 'RATE_LIMITED', 'message' => 'Trop de requêtes. Réessayez dans un instant.'],
            ], 429, $headers)));
    }
}
