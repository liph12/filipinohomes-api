<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Which map service the listing wizard uses (pin map, address lookup, nearby-facilities scan):
 * Google Maps, or OpenStreetMap — a free stand-in while Google Maps billing is off. Stored in
 * the settings KV so it switches without a deploy.
 *
 * Automatic fallback: when Google is selected but Google reports billing is off, the web
 * (which holds the Google key) calls POST /maps/fallback and the setting is SAVED as
 * OpenStreetMap, with the time it happened. It never switches back by itself — once billing
 * is on again an admin switches back in System Settings (which clears the fallback note).
 *
 *  - GET  /maps/config          public (guest token): the current provider
 *  - POST /maps/fallback        server-to-server (X-Build-Token): Google billing is off → OSM
 *  - GET|PUT /admin/map-settings  System Settings
 */
class MapSettingsController extends Controller
{
    public const KEY = 'maps.nearby_provider';

    public const FALLBACK_AT_KEY = 'maps.auto_fallback_at';

    public const PROVIDERS = ['google', 'osm'];

    public static function nearbyProvider(): string
    {
        $value = Setting::get(self::KEY, 'google');

        return in_array($value, self::PROVIDERS, true) ? $value : 'google';
    }

    /** When Google was last switched off automatically (ISO 8601), or null. */
    public static function autoFallbackAt(): ?string
    {
        $value = Setting::get(self::FALLBACK_AT_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function config(): JsonResponse
    {
        return response()->json(['nearby_provider' => self::nearbyProvider()]);
    }

    public function fallback(Request $request): JsonResponse
    {
        $token = (string) config('app.build_token', '');
        $given = (string) $request->header('X-Build-Token', '');
        if ($token === '' || $given === '' || ! hash_equals($token, $given)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Only Google → OpenStreetMap. Already on OpenStreetMap: nothing to do (and the
        // original fallback time is kept).
        if (self::nearbyProvider() === 'google') {
            Setting::set(self::KEY, 'osm');
            Setting::set(self::FALLBACK_AT_KEY, now()->toIso8601String());
        }

        return response()->json($this->state());
    }

    public function show(): JsonResponse
    {
        return response()->json($this->state());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nearby_provider' => 'required|in:'.implode(',', self::PROVIDERS),
        ]);

        Setting::set(self::KEY, $data['nearby_provider']);
        // A manual choice replaces any automatic one.
        Setting::set(self::FALLBACK_AT_KEY, '');

        return response()->json($this->state());
    }

    private function state(): array
    {
        return ['nearby_provider' => self::nearbyProvider(), 'auto_fallback_at' => self::autoFallbackAt()];
    }
}
