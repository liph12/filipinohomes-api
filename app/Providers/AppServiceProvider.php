<?php

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // // General API
        // RateLimiter::for('api', function (Request $request) {
        //     return Limit::perMinute(120)->by(
        //         $request->user()?->id ?: $request->ip()
        //     );
        // });

        // // Auth (strict)
        // RateLimiter::for('auth', function (Request $request) {
        //     return Limit::perMinute(5)->by(
        //         $request->input('email') . '|' . $request->ip()
        //     );
        // });

        // // Chat (bursty)
        // RateLimiter::for('chat', function (Request $request) {
        //     return Limit::perMinute(300)->by(
        //         $request->user()?->id ?: $request->ip()
        //     );
        // });
    }
}