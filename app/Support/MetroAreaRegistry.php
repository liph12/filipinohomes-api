<?php

namespace App\Support;

/**
 * Static province → curated URBAN LGU list, used to split province market
 * stats into urban/rural segments.
 *
 * Default classification comes from cities.type (0 = city → urban,
 * 1 = municipality → rural). A province listed in OVERRIDES replaces that
 * default entirely: its curated LGUs are urban and every other LGU in the
 * province is rural regardless of cities.type — this captures metro-area
 * municipalities (type 1) and miscoded component cities in one place.
 *
 * Names are VERBATIM DB spellings, matched via IslandMap::normalize() on
 * both the province and city names. Curated names must be UNIQUE within
 * their province (a duplicate city name inside one province would collide
 * in the normalized lookup). The frontend label map
 * (src/lib/metroLabels.ts) must stay in sync with the provinces curated
 * here.
 */
class MetroAreaRegistry
{
    /** province display name => curated urban LGU display names. */
    private const OVERRIDES = [
        // Metro Cebu (13 LGUs).
        'Cebu' => [
            'Cebu City', 'Mandaue City', 'Lapu-lapu City', 'Talisay City',
            'Danao City', 'Carcar', 'Naga City', 'Compostela', 'Consolacion',
            'Cordova', 'Liloan', 'Minglanilla', 'San Fernando',
        ],
    ];

    /** Lazily-built normalized province => [normalized city => true] lookup. */
    private static ?array $lookup = null;

    private static function lookup(): array
    {
        if (self::$lookup === null) {
            self::$lookup = [];
            foreach (self::OVERRIDES as $province => $cities) {
                $key = IslandMap::normalize($province);
                self::$lookup[$key] = [];
                foreach ($cities as $city) {
                    self::$lookup[$key][IslandMap::normalize($city)] = true;
                }
            }
        }

        return self::$lookup;
    }

    /**
     * 'urban' | 'rural' segment for a city within its province, or null when
     * the row must not be segmented. cities.type 2 rows are junk POIs and are
     * never classified — segmenting them would grow phantom sides (e.g. a
     * rural segment for Metro Manila).
     */
    public static function classify(string $provinceName, string $cityName, int $cityType): ?string
    {
        if ($cityType === 2) {
            return null;
        }

        $curated = self::lookup()[IslandMap::normalize($provinceName)] ?? null;
        if ($curated !== null) {
            return isset($curated[IslandMap::normalize($cityName)]) ? 'urban' : 'rural';
        }

        return $cityType === 0 ? 'urban' : 'rural';
    }
}
