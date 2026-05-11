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

        $secret = env('GUEST_API_SECRET', '');
        if (!$secret) {
            return false;
        }

        $expected = hash_hmac('sha256', $json, $secret);

        return hash_equals($expected, $sig);
    }
}
