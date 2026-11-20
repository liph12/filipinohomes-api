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
 * Automatic switching, both ways — the web (which holds the Google key) checks billing:
 *  - Google selected, billing off → POST /maps/fallback: SAVED as OpenStreetMap, with the time.
 *  - On OpenStreetMap BECAUSE of that fallback, billing on again → POST /maps/restore: saved
 *    back as Google, with the time. An OpenStreetMap an admin picked by hand is never touched.
 * Any manual choice in System Settings clears both notes. Automatic switching can be turned
 * off (`auto_switch`, on by default): then the provider only changes when an admin picks one.
 *
 *  - GET  /maps/config          public (guest token): the provider, and whether it's an automatic fallback
 *  - POST /maps/fallback        server-to-server (X-Build-Token): Google billing is off → OSM
 *  - POST /maps/restore         server-to-server (X-Build-Token): billing is on again → back to Google
 *  - GET|PUT /admin/map-settings  System Settings
 */
class MapSettingsController extends Controller
{
    public const KEY = 'maps.nearby_provider';

    public const FALLBACK_AT_KEY = 'maps.auto_fallback_at';

    public const RESTORED_AT_KEY = 'maps.auto_restored_at';

    public const AUTO_SWITCH_KEY = 'maps.auto_switch';

    public const PROVIDERS = ['google', 'osm'];

    public static function nearbyProvider(): string
    {
        $value = Setting::get(self::KEY, 'google');

        return in_array($value, self::PROVIDERS, true) ? $value : 'google';
    }

    /** Switch between Google and OpenStreetMap by itself as billing goes off / on? On by default. */
    public static function autoSwitch(): bool
    {
        return (string) Setting::get(self::AUTO_SWITCH_KEY, '1') !== '0';
    }

    /** When Google was last switched off automatically (ISO 8601), or null. */
    public static function autoFallbackAt(): ?string
    {
        return self::stamp(self::FALLBACK_AT_KEY);
    }

    /** When Google was last switched back on automatically (ISO 8601), or null. */
    public static function autoRestoredAt(): ?string
    {
        return self::stamp(self::RESTORED_AT_KEY);
    }

    public function config(): JsonResponse
    {
        $provider = self::nearbyProvider();

        return response()->json([
            'nearby_provider' => $provider,
            // Off: the web never switches the provider by itself.
            'auto_switch' => self::autoSwitch(),
            // OpenStreetMap only because Google billing was off — the web switches it back once billing is on.
            'auto_fallback' => $provider === 'osm' && self::autoFallbackAt() !== null,
        ]);
    }

    public function fallback(Request $request): JsonResponse
    {
        if (! $this->fromWeb($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Only Google → OpenStreetMap, and only while automatic switching is on. Already on
        // OpenStreetMap: nothing to do (and the original fallback time is kept).
        if (self::autoSwitch() && self::nearbyProvider() === 'google') {
            Setting::set(self::KEY, 'osm');
            Setting::set(self::FALLBACK_AT_KEY, now()->toIso8601String());
            Setting::set(self::RESTORED_AT_KEY, '');
        }

        return response()->json($this->state());
    }

    public function restore(Request $request): JsonResponse
    {
        if (! $this->fromWeb($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Only undoes an automatic fallback — an OpenStreetMap an admin chose stays — and only
        // while automatic switching is on.
        if (self::autoSwitch() && self::nearbyProvider() === 'osm' && self::autoFallbackAt() !== null) {
            Setting::set(self::KEY, 'google');
            Setting::set(self::FALLBACK_AT_KEY, '');
            Setting::set(self::RESTORED_AT_KEY, now()->toIso8601String());
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
            'nearby_provider' => 'required_without:auto_switch|in:'.implode(',', self::PROVIDERS),
            'auto_switch' => 'sometimes|boolean',
        ]);

        if (array_key_exists('auto_switch', $data)) {
            Setting::set(self::AUTO_SWITCH_KEY, $data['auto_switch'] ? '1' : '0');
        }

        if (isset($data['nearby_provider'])) {
            Setting::set(self::KEY, $data['nearby_provider']);
            // A manual choice replaces any automatic one.
            Setting::set(self::FALLBACK_AT_KEY, '');
            Setting::set(self::RESTORED_AT_KEY, '');
        }

        return response()->json($this->state());
    }

    private function state(): array
    {
        return [
            'nearby_provider' => self::nearbyProvider(),
            'auto_switch' => self::autoSwitch(),
            'auto_fallback_at' => self::autoFallbackAt(),
            'auto_restored_at' => self::autoRestoredAt(),
        ];
    }

    /** Server-to-server calls from the web carry the build token. */
    private function fromWeb(Request $request): bool
    {
        $token = (string) config('app.build_token', '');
        $given = (string) $request->header('X-Build-Token', '');

        return $token !== '' && $given !== '' && hash_equals($token, $given);
    }

    private static function stamp(string $key): ?string
    {
        $value = Setting::get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
