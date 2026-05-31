<?php

namespace App\Providers;

use App\Services\AuditMailService;
use App\Services\TeamLeadershipService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TeamLeadershipService::class);
        $this->app->singleton(AuditMailService::class);
    }

    public function boot(): void
    {
        // Define API limiter
        RateLimiter::for('api', function (Request $request) {
            // Trusted Next.js build server — bypass per-IP limit so SSG
            // pre-rendering (~3,500 pages × 5 calls) doesn't trip the
            // 120 req/min ceiling. Token is server-side only; never sent
            // by browser clients. EC2 load is controlled separately via
            // NEXT_PRIVATE_WORKER_COUNT on the Vercel build side.
            $buildToken = config('app.build_token');
            if ($buildToken && $request->header('X-Build-Token') === $buildToken) {
                return Limit::none();
            }
            return Limit::perMinute(120)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        // Optional: auth limiter
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by(
                $request->input('email') . '|' . $request->ip()
            );
        });

        // Optional: chat limiter
        RateLimiter::for('chat', function (Request $request) {
            return Limit::perMinute(300)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        // Global outbound-mail audit. Every successful Mail::send
        // in the app fires Illuminate\Mail\Events\MessageSent, so
        // this listener captures all of them — Get In Touch,
        // Contact Us, OTP login, inquiry mailers, notifications,
        // anything that ships an email. Failure-side writes are
        // handled at each Mail::send call site via try/catch +
        // AuditMailService::recordFailure (Laravel doesn't expose
        // a MessageFailed event).
        Event::listen(MessageSent::class, function (MessageSent $event) {
            app(AuditMailService::class)->recordSent($event);
        });
    }
}