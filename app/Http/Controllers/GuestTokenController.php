<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class GuestTokenController extends Controller
{
    public function issue(): JsonResponse
    {
        // Read via config() so the value survives `php artisan config:cache`.
        // env() returns null outside config files once the cache is built.
        $secret = config('app.guest_api_secret', '');

        if (!$secret) {
            return response()->json(['error' => 'Not configured'], 500);
        }

        $now = time();
        $payloadJson = json_encode(['iat' => $now, 'exp' => $now + 3600]);
        $encodedPayload = base64_encode($payloadJson);
        $sig = hash_hmac('sha256', $payloadJson, $secret);
        $token = $encodedPayload . '.' . $sig;

        return response()->json([
            'token'      => $token,
            'expires_in' => 3600,
        ]);
    }
}
