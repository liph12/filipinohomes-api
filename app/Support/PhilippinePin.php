<?php

namespace App\Support;

/**
 * A listing's map pin must be inside the Philippines.
 *
 * The country as a box (Batanes to Tawi-Tawi, Palawan to Davao Oriental, with
 * a little sea around it), not its border, so a corner of Sabah passes too.
 * The listing form's map is held to the same box
 * (filipinohomes-final: create-listing-form/.../locationHelpers.ts PH_BOUNDS).
 */
final class PhilippinePin
{
    public const SOUTH = 4.4;

    public const NORTH = 21.3;

    public const WEST = 116.5;

    public const EAST = 126.8;

    public const MESSAGE = 'The pin must be inside the Philippines.';

    /** Rules for geo_coordinates.lat / .lng, both needed once geo_coordinates is sent. */
    public static function rules(): array
    {
        return [
            'geo_coordinates.lat' => ['required_with:geo_coordinates', 'numeric', 'between:'.self::SOUTH.','.self::NORTH],
            'geo_coordinates.lng' => ['required_with:geo_coordinates', 'numeric', 'between:'.self::WEST.','.self::EAST],
        ];
    }

    public static function inside(float $lat, float $lng): bool
    {
        return $lat >= self::SOUTH && $lat <= self::NORTH && $lng >= self::WEST && $lng <= self::EAST;
    }

    /**
     * True when a pin is lat/lng the wrong way round: outside as saved, inside
     * once the two are swapped (e.g. lat 124.0 / lng 10.29 is really Cebu).
     */
    public static function swapped(mixed $lat, mixed $lng): bool
    {
        $lat = self::number($lat);
        $lng = self::number($lng);

        return $lat !== null && $lng !== null && ! self::inside($lat, $lng) && self::inside($lng, $lat);
    }

    /** A saved coordinate as a number ("10,290.5" and " 10.29 " included), or null. */
    public static function number(mixed $value): ?float
    {
        $value = is_string($value) ? str_replace(',', '', trim($value)) : $value;

        return is_numeric($value) ? (float) $value : null;
    }

    public static function messages(): array
    {
        return [
            'geo_coordinates.lat.between' => self::MESSAGE,
            'geo_coordinates.lng.between' => self::MESSAGE,
        ];
    }
}
