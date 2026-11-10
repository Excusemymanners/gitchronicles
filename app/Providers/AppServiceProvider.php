<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * "repositories": at most 10 requests per minute for each user, or for each
     * IP address when the visitor is a guest (guests may submit the form too),
     * because every new repository and every „Reîncearcă” starts a clone job.
     * Adding and retrying share the same 10 requests (same limiter, same key).
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('repositories', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });
    }
}
