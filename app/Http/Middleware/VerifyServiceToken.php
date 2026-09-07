<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for server-to-server endpoints consumed by our OWN backends
 * (natcon-api-v2 today), authenticated by a static shared secret.
 *
 * Deliberately NOT the VerifyFhAgentToken pattern: that token is minted by a
 * PUBLIC route (POST /api/fh-agent/token), so anyone can obtain one — fine for
 * a bot speed bump, not for endpoints that serve awardee PII. Here there is no
 * mint route at all; the caller holds the secret in ITS config and sends it
 * verbatim. Rotation is a coordinated two-.env change.
 *
 * 401 body is keyed "error", not "message" — the frontend axios interceptor
 * logs users out on message-keyed 401s, and while no browser should ever hit
 * this, the convention costs nothing to keep.
 */
class VerifyServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // config(), never env(): after `php artisan config:cache`, env()
        // returns null outside config files — the exact failure that once
        // 401'd every /listings request in production.
        $secret = (string) config('app.fh_service_token', '');
        $given  = (string) $request->header('X-FH-Service-Token', '');

        if ($secret === '' || $given === '' || ! hash_equals($secret, $given)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
