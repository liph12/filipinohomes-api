<?php

namespace App\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $store = Cache::store('ratelimit');
    
        RateLimiter::for('api', function (Request $request) use ($store) {
            return Limit::perMinute(120)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => response()->json(['message' => 'Too many requests.'], 429));
        });
    
        RateLimiter::for('auth', function (Request $request) use ($store) {
            return Limit::perMinute(5)
                ->by($request->input('email') . '|' . $request->ip());
        });
    
        RateLimiter::for('chat', function (Request $request) use ($store) {
            return Limit::perMinute(300)
                ->by($request->user()?->id ?: $request->ip());
        });
    
        app()->bind('cache.rateLimiting', fn() => $store);
    }
}