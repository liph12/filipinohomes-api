<?php

namespace App\Providers;

use App\Services\AuditMailService;
use App\Services\TeamLeadershipService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
                $request->input('email').'|'.$request->ip()
            );
        });

        // Optional: chat limiter
        RateLimiter::for('chat', function (Request $request) {
            return Limit::perMinute(300)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        // NATCON awardee photo upload. This is the only unauthenticated route in
        // the app that writes to production S3, so it gets its own tight limiter
        // rather than riding the 120/min `api` allowance.
        //
        // Two keys on purpose: the per-token limit stops one awardee re-uploading
        // in a loop, and the per-IP limit stops one actor cycling stolen or
        // guessed tokens. Neither alone is sufficient — a shared office NAT would
        // trip a per-IP-only limit for legitimate colleagues.
        /**
         * Reacting to an announcement. Anonymous, so there is no account to
         * limit — the two keys are the browser's visitor id and the IP, and
         * both are needed: the visitor id is clearable (one person could loop
         * it), and the IP is shared (one office is legitimately many people, so
         * it must be the looser of the two).
         *
         * Generous on purpose. Reading the feed and reacting to several posts is
         * normal behaviour; this exists to stop a script, not a keen reader.
         */
        RateLimiter::for('natcon-react', function (Request $request) {
            $visitor = (string) $request->input('visitor_id');

            return [
                Limit::perMinute(20)->by('natcon-react-vid:'.sha1($visitor ?: 'anon')),
                Limit::perMinute(60)->by('natcon-react-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('natcon-upload', function (Request $request) {
            $token = (string) $request->input('t');

            return [
                Limit::perHour(6)->by('natcon-tok:'.sha1($token ?: 'anon')),
                Limit::perHour(30)->by('natcon-ip:'.$request->ip()),
            ];
        });

        // Admin gallery uploads — deliberately NOT natcon-upload: that one is
        // sized for one awardee sending 1-3 headshots, and an admin loading an
        // event album pushes hundreds of files in one sitting. Behind
        // auth:sanctum + role:admin, so the keys are the user, not a token.
        RateLimiter::for('natcon-gallery-upload', function (Request $request) {
            return [
                Limit::perMinute(60)->by('natcon-gal:'.($request->user()?->id ?: $request->ip())),
            ];
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
