<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyGuestToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Guest-Token');

        if (!$token || !$this->isValid($token)) {
            // Body shape is load-bearing: the frontend axios interceptor uses
            // the "error" KEY here to tell a guest-token 401 apart from an
            // auth 401 (which is keyed "message") — only the latter may log
            // the user out. Don't change this key without updating
            // src/lib/axios.ts in the frontend repo.
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

        // Read via config() so the value survives `php artisan config:cache`.
        // env() returns null outside config files once the cache is built —
        // that caused every /listings request to 401 in production.
        $secret = config('app.guest_api_secret', '');
        if (!$secret) {
            return false;
        }

        $expected = hash_hmac('sha256', $json, $secret);

        return hash_equals($expected, $sig);
    }
}
