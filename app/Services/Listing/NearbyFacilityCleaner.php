<?php

namespace App\Services\Listing;

/**
 * Display-time cleanup of a property's nearby facilities (the Google Places results the listing wizard
 * stored at creation). Read-side only — NearbyFacilityResource runs it; the stored rows are untouched and
 * the agent edit form (/full-listing, raw toArray) still sees exactly what was saved.
 *
 * What it fixes, measured on the full table (863 properties, ~39k entries):
 *  - Plus Codes as addresses ("4JVC+88, Camalig") — ~46% of entries. The code is stripped, the locality kept.
 *  - Individual doctors filed as clinics ("Rico P. Nebres, MD") — ~47% of clinics, often six doctors in
 *    one hospital. Grouped into one entry per building ("Mother Seton Hospital · 6 doctors").
 *  - Wrong-category places: farms, memorial parks, subdivisions and churches as parks; farms, resorts
 *    and LTFRB offices as schools; subdivisions and bare place names ("Naga") as hospitals.
 *    "Memorial" alone is NOT junk — "Ramon Magsaysay Memorial High School" is a real school — so each
 *    category drops a name only when it matches the junk pattern and has none of the category's words.
 */
class NearbyFacilityCleaner
{
    public const CATEGORIES = ['school', 'hospital', 'clinic', 'pharmacy', 'fire_station', 'police_station', 'mall', 'park', 'attraction', 'transport_station'];

    /** A transit "place" that is only a generic label ("Bus Stop", "Loading and Unloading Zone") — not a landmark. */
    private const GENERIC_STOP = '/^\s*(?:(?:bus|jeep(?:ney)?|uv|tricycle|trike|pedicab|habal)\s+)?(?:stop|station|terminal|stand|loading(?:\s+(?:and|&)\s+unloading)?(?:\s+(?:zone|area|bay))?)\s*$/i';

    /** Burial grounds — dropped from parks and attractions outright; "park" in the name can't rescue them. */
    private const BURIAL = '/memorial\s+(park|garden|chapel)|\b(golden|palm)\s+haven\b|\b(cemetery|funeral|columbarium)\b/i';

    /** A name in this category is dropped when it matches JUNK and none of KEEP. */
    private const JUNK = [
        'school' => '/\b(farm|resort|ltfrb|lto|subdivision|church|parish|chapel|hotel|terminal)\b/i',
        'park' => '/\b(farm|farmhouse|subdivision|church|parish|exit|hotel|resort)\b/i',
        'attraction' => '/\b(subdivision)\b/i',
        'hospital' => '/\b(subdivision|village|homes|farm|church)\b/i',
    ];

    private const KEEP = [
        'school' => '/school|academy|college|universit|institute|elementary|kinder|montessori|learning|preschool|daycare|seminary|\bibed\b/i',
        'park' => '/\b(park|plaza|playground|nature|eco|reserve|boulevard|baywalk)\b/i',
        'attraction' => '/(?!)/', // a subdivision is never an "attraction"
        'hospital' => '/hosp|medic|clinic|health|infirm|center|centre/i',
    ];

    /** Healthcare words: a hospital/clinic entry needs one, unless it's a multi-word proper name. */
    private const MEDICAL = '/hosp|medic|\bmed\b|clinic|health|infirm|center|centre|doctor|dental|diagnost|laborator|maternity|lying-in|polyclinic/i';

    /** "Rico P. Nebres, MD", "Dr. Roy Fernando", "Dra. …" — a person, not a place. */
    private const DOCTOR = '/(^|\s)(dr|dra)\.?\s|,?\s*\b(m\.?d|md|dmd|fpcp|fpps|fpog)\.?(\s|,|$)/i';

    /** A building inside an address: "Mother Seton Hospital, Nursery Road…" → "Mother Seton Hospital". */
    private const BUILDING = '/hosp|medic|clinic|center|centre|health|infirm|diagnost|polyclinic/i';

    /** Open Location Code at the start of an address: "4JVC+88, Camalig" / "C7XQ+2R4 Naga". */
    private const PLUS_CODE = '/^[23456789CFGHJMPQRVWX]{4,8}\+[23456789CFGHJMPQRVWX]{0,3}[,\s]*/i';

    /** Raw coordinates as an address or a name: "13.6663, 123.2617". */
    private const COORDS = '/^\s*-?\d{1,3}\.\d+\s*,\s*-?\d{1,3}\.\d+\s*$/';

    /** @param array<string, mixed> $facilities category => list of {name, address, lat, lng, distance_meters} */
    public static function clean(array $facilities): array
    {
        $out = [];
        foreach (self::CATEGORIES as $cat) {
            $items = array_values(array_filter(
                array_map(fn ($f) => self::tidy($f), is_array($facilities[$cat] ?? null) ? $facilities[$cat] : []),
                fn ($f) => $f !== null && self::belongs($cat, $f['name']),
            ));
            if ($cat === 'clinic') {
                $items = self::groupDoctors($items);
            }
            usort($items, fn ($a, $b) => ($a['distance_meters'] ?? PHP_INT_MAX) <=> ($b['distance_meters'] ?? PHP_INT_MAX));
            $out[$cat] = self::dedupe($items);
        }

        return $out;
    }

    /** Trim strings, strip Plus Codes / coordinates from the address; null when there's no usable name. */
    private static function tidy(mixed $f): ?array
    {
        if (! is_array($f)) {
            return null;
        }
        $name = trim((string) ($f['name'] ?? ''));
        if ($name === '' || preg_match(self::COORDS, $name)) {
            return null;
        }
        $address = trim((string) ($f['address'] ?? ''));
        $address = preg_match(self::COORDS, $address) ? '' : trim(preg_replace(self::PLUS_CODE, '', $address), ' ,');

        return ['name' => $name, 'address' => $address] + array_intersect_key($f, array_flip(['lat', 'lng', 'distance_meters']));
    }

    private static function belongs(string $cat, string $name): bool
    {
        if (in_array($cat, ['park', 'attraction'], true) && preg_match(self::BURIAL, $name)) {
            return false;
        }
        if (isset(self::JUNK[$cat]) && preg_match(self::JUNK[$cat], $name) && ! preg_match(self::KEEP[$cat], $name)) {
            return false;
        }
        if ($cat === 'transport_station' && preg_match(self::GENERIC_STOP, $name)) {
            return false;
        }
        // A one-word hospital with no medical word is a place name ("Naga"), not a hospital.
        if ($cat === 'hospital' && ! preg_match(self::MEDICAL, $name) && str_word_count($name) < 2) {
            return false;
        }

        return true;
    }

    /**
     * Doctors → their building. Doctors whose address names a medical building collapse into one entry per
     * building (nearest distance, "· N doctors"); a doctor with no recognisable building is dropped — a
     * person, not a place a buyer can go to.
     */
    private static function groupDoctors(array $items): array
    {
        $groups = [];
        $rest = [];
        foreach ($items as $f) {
            if (! preg_match(self::DOCTOR, $f['name'])) {
                $rest[] = $f;

                continue;
            }
            $building = null;
            foreach (array_map('trim', explode(',', $f['address'])) as $part) {
                // "Department Surgery" / "Department Family Medicine" are wings, not buildings.
                if ($part !== '' && preg_match(self::BUILDING, $part) && ! preg_match('/^department\b/i', $part)) {
                    $building = $part;
                    break;
                }
            }
            if ($building === null) {
                continue;
            }
            $key = mb_strtolower($building);
            if (! isset($groups[$key])) {
                $address = trim(str_ireplace($building, '', $f['address']), ' ,');
                $groups[$key] = ['name' => $building, 'address' => preg_replace('/^department[^,]*,\s*/i', '', $address), 'doctors' => 0] + array_intersect_key($f, array_flip(['lat', 'lng', 'distance_meters']));
            }
            $groups[$key]['doctors']++;
            $groups[$key]['distance_meters'] = min($groups[$key]['distance_meters'] ?? PHP_INT_MAX, $f['distance_meters'] ?? PHP_INT_MAX);
        }
        foreach ($groups as &$g) {
            if ($g['doctors'] > 1) {
                $g['name'] .= " · {$g['doctors']} doctors";
            }
            unset($g['doctors']);
        }

        return array_merge($rest, array_values($groups));
    }

    /** Same place listed twice (same name, case-insensitive) → keep the nearest. */
    private static function dedupe(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $f) {
            $key = mb_strtolower($f['name']);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $f;
            }
        }

        return $out;
    }
}
