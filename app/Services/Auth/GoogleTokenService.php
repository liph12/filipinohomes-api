<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;

class GoogleTokenService
{
    public function verify(string $accessToken): ?array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get('https://www.googleapis.com/oauth2/v3/userinfo');

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            if (empty($data['sub']) || empty($data['email'])) {
                return null;
            }

            return [
                'google_id' => $data['sub'],
                'email' => $data['email'],
                'name' => $data['name'] ?? '',
                'avatar' => $data['picture'] ?? null,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}
