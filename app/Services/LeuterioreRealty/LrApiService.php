<?php

namespace App\Services\LeuterioreRealty;

use Illuminate\Support\Facades\Http;

class LrApiService
{
    private const BASE_URL = 'https://api.leuteriorealty.com/lr/v1/public/api/agent';

    public function fetchAgentByEmail(string $email): ?array
    {
        try {
            $response = Http::timeout(10)->get(self::BASE_URL . '/' . urlencode($email));

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function hasRequiredFireCertificates(array $lrData): bool
    {
        return ($lrData['fire_certificates'] ?? 0) >= 3;
    }

    public function parseName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName));

        if (count($parts) === 1) {
            return [
                'first_name' => $parts[0],
                'middle_name' => null,
                'last_name' => null,
            ];
        }

        if (count($parts) === 2) {
            return [
                'first_name' => $parts[0],
                'middle_name' => null,
                'last_name' => $parts[1],
            ];
        }

        return [
            'first_name' => $parts[0],
            'middle_name' => implode(' ', array_slice($parts, 1, -1)),
            'last_name' => end($parts),
        ];
    }
}
