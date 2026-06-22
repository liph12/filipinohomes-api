<?php

namespace App\Services\Office;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleGeocodingService
{
    /**
     * Placeholder or unusable address fragments that should not be geocoded.
     *
     * @var string[]
     */
    private array $unusableAddressPatterns = [
        'no official office address yet',
        'not available',
        'n/a',
        'tbd',
        'to be announced',
        'coming soon',
    ];

    public function hasApiKey(): bool
    {
        return trim((string) config('services.google_maps.geocoding_key', '')) !== '';
    }

    public function hasUsableAddress(?string $address): bool
    {
        $normalized = strtolower(trim((string) $address));

        if ($normalized === '' || mb_strlen($normalized) < 8) {
            return false;
        }

        foreach ($this->unusableAddressPatterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $address): ?array
    {
        $apiKey = trim((string) config('services.google_maps.geocoding_key', ''));

        if ($apiKey === '') {
            throw new RuntimeException('Missing GOOGLE_MAPS_API_KEY configuration in filipinohomes-api/.env.');
        }

        $response = Http::timeout(15)
            ->retry(2, 500)
            ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $this->normalizeAddress($address),
                'region' => 'ph',
                'key' => $apiKey,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google Geocoding request failed with status '.$response->status().'.');
        }

        $payload = $response->json();
        $status = (string) ($payload['status'] ?? 'UNKNOWN_ERROR');

        if ($status === 'ZERO_RESULTS') {
            return null;
        }

        if ($status !== 'OK') {
            throw new RuntimeException('Google Geocoding returned status '.$status.'.');
        }

        $location = $payload['results'][0]['geometry']['location'] ?? null;

        if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
            return null;
        }

        return [
            'lat' => (float) $location['lat'],
            'lng' => (float) $location['lng'],
        ];
    }

    /**
     * Reverse-geocode a lat/lng to its Philippine admin components.
     *
     * @return array{locality: ?string, sublocality: ?string, province: ?string, formatted: ?string}|null
     */
    public function reverseGeocode(float $lat, float $lng): ?array
    {
        $apiKey = trim((string) config('services.google_maps.geocoding_key', ''));

        if ($apiKey === '') {
            throw new RuntimeException('Missing GOOGLE_MAPS_API_KEY configuration in filipinohomes-api/.env.');
        }

        $response = Http::timeout(15)
            ->retry(2, 500)
            ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'latlng' => $lat.','.$lng,
                'region' => 'ph',
                'key' => $apiKey,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google reverse geocoding failed with status '.$response->status().'.');
        }

        $payload = $response->json();
        $status = (string) ($payload['status'] ?? 'UNKNOWN_ERROR');

        if ($status === 'ZERO_RESULTS') {
            return null;
        }
        if ($status !== 'OK') {
            throw new RuntimeException('Google reverse geocoding returned status '.$status.'.');
        }

        // Pull the first component of each type across all results (the first
        // result is the most specific). PH mapping: locality = city/municipality,
        // sublocality_level_1 = barangay, administrative_area_level_2 = province.
        $find = function (string $type) use ($payload): ?string {
            foreach (($payload['results'] ?? []) as $result) {
                foreach (($result['address_components'] ?? []) as $c) {
                    if (in_array($type, $c['types'] ?? [], true)) {
                        return $c['long_name'] ?? null;
                    }
                }
            }
            return null;
        };

        return [
            'locality'    => $find('locality'),
            'sublocality' => $find('sublocality_level_1') ?? $find('sublocality'),
            'province'    => $find('administrative_area_level_2'),
            'formatted'   => $payload['results'][0]['formatted_address'] ?? null,
        ];
    }

    private function normalizeAddress(string $address): string
    {
        $normalized = trim($address);

        if ($normalized === '') {
            return $normalized;
        }

        if (! preg_match('/philippines|cebu|davao|manila|quezon city|taguig|makati|butuan|bacolod|iloilo|cagayan de oro|ormoc|tacloban|bais|palawan|bohol|cavite|pampanga/i', $normalized)) {
            $normalized .= ', Philippines';
        }

        return $normalized;
    }
}
