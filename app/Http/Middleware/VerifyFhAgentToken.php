<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the SEPARATE token used by the external /fh-agent endpoint.
 *
 * Mirrors VerifyGuestToken but signs/validates against its own secret
 * (config app.fh_agent_api_secret) and reads a distinct header
 * (X-FH-Agent-Token). This keeps partner access to /fh-agent independent
 * from the site-wide guest token — neither token is valid for the other's
 * routes.
 */
class VerifyFhAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-FH-Agent-Token');

        if (!$token || !$this->isValid($token)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }

    private function isValid(string $token): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$encodedPayload, $sig] = $parts;

        $json = base64_decode($encodedPayload, true);
        if ($json === false) {
            return false;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload) || empty($payload['exp'])) {
            return false;
        }

        if ($payload['exp'] < time()) {
            return false;
        }

        // config() so the value survives `php artisan config:cache`.
        $secret = config('app.fh_agent_api_secret', '');
        if (!$secret) {
            return false;
        }

        $expected = hash_hmac('sha256', $json, $secret);

        return hash_equals($expected, $sig);
    }
}
