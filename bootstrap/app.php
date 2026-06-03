<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Traffic arrives through Cloudflare, so REMOTE_ADDR is a Cloudflare
        // edge IP and the real visitor is in X-Forwarded-For. Trust ONLY
        // Cloudflare's published ranges (never '*', which would let any client
        // spoof their IP) so $request->ip() — used for login logs and session
        // geolocation — resolves to the actual visitor.
        $middleware->trustProxies(at: [
            // Cloudflare IPv4 — https://www.cloudflare.com/ips-v4
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            // Cloudflare IPv6 — https://www.cloudflare.com/ips-v6
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
            // Local reverse proxy, if any (loopback can't be spoofed externally)
            '127.0.0.1',
            '::1',
            // Only trust the client-IP header — leaves scheme/host/port
            // detection exactly as it is today, so the sole production change
            // is that $request->ip() resolves to the real visitor.
        ], headers: SymfonyRequest::HEADER_X_FORWARDED_FOR);

        $middleware->alias([
            'strip.tags'         => \App\Http\Middleware\StripHtmlTags::class,
            'verify.guest.token' => \App\Http\Middleware\VerifyGuestToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->wantsJson()
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Unauthorized'], 401);
        });
    })->create();