<?php

namespace App\Support;

/**
 * Static Philippine province → administrative-region (17 regions) map, with each
 * region's parent island group.
 *
 * Like {@see IslandMap}, the DB has no region column, so region grouping is
 * resolved here. The province display names below are taken VERBATIM from
 * IslandMap::GROUPS (just partitioned into their regions) so the two maps can
 * never disagree at the island level — a guard test asserts this.
 *
 * This is a 1:1 PORT of the frontend map at
 * src/components/dashboard/admin/listing-insights/regionMap.ts
 * (PROVINCE_REGION + REGION_ISLAND + REGION_LABEL). Keep FE/BE in sync, and keep
 * the region keys identical on both sides.
 *
 * NOTE (NIR): Negros Occidental stays under Western Visayas (matching IslandMap),
 * even though the Negros Island Region was reconstituted in 2024. A future NIR
 * migration must touch IslandMap, RegionMap, and the FE mirror together.
 */
class RegionMap
{
    /** Ordered region keys (identical strings FE/BE). */
    public const REGIONS = [
        'ncr', 'car', 'ilocos', 'cagayan-valley', 'central-luzon', 'calabarzon', 'mimaropa', 'bicol',
        'western-visayas', 'central-visayas', 'eastern-visayas',
        'zamboanga-peninsula', 'northern-mindanao', 'davao', 'soccsksargen', 'caraga', 'barmm',
    ];

    /** region key => island group. Lets island scope compose from regions. */
    public const REGION_ISLAND = [
        'ncr' => 'luzon', 'car' => 'luzon', 'ilocos' => 'luzon', 'cagayan-valley' => 'luzon',
        'central-luzon' => 'luzon', 'calabarzon' => 'luzon', 'mimaropa' => 'luzon', 'bicol' => 'luzon',
        'western-visayas' => 'visayas', 'central-visayas' => 'visayas', 'eastern-visayas' => 'visayas',
        'zamboanga-peninsula' => 'mindanao', 'northern-mindanao' => 'mindanao', 'davao' => 'mindanao',
        'soccsksargen' => 'mindanao', 'caraga' => 'mindanao', 'barmm' => 'mindanao',
    ];

    /** region key => [province display names] — verbatim from IslandMap::GROUPS. */
    private const GROUPS = [
        'ncr' => ['Metro Manila', 'NCR', 'National Capital Region'],
        'car' => ['Abra', 'Apayao', 'Benguet', 'Ifugao', 'Kalinga', 'Mountain Province'],
        'ilocos' => ['Ilocos Norte', 'Ilocos Sur', 'La Union', 'Pangasinan'],
        'cagayan-valley' => ['Batanes', 'Cagayan', 'Isabela', 'Nueva Vizcaya', 'Quirino'],
        'central-luzon' => ['Aurora', 'Bataan', 'Bulacan', 'Nueva Ecija', 'Pampanga', 'Tarlac', 'Zambales'],
        'calabarzon' => ['Batangas', 'Cavite', 'Laguna', 'Quezon', 'Rizal'],
        'mimaropa' => ['Marinduque', 'Occidental Mindoro', 'Oriental Mindoro', 'Palawan', 'Romblon'],
        'bicol' => ['Albay', 'Camarines Norte', 'Camarines Sur', 'Catanduanes', 'Masbate', 'Sorsogon'],
        'western-visayas' => ['Aklan', 'Antique', 'Capiz', 'Guimaras', 'Iloilo', 'Negros Occidental'],
        'central-visayas' => ['Bohol', 'Cebu', 'Negros Oriental', 'Siquijor'],
        'eastern-visayas' => ['Biliran', 'Eastern Samar', 'Leyte', 'Northern Samar', 'Samar', 'Western Samar', 'Southern Leyte'],
        'zamboanga-peninsula' => ['Zamboanga del Norte', 'Zamboanga del Sur', 'Zamboanga Sibugay'],
        'northern-mindanao' => ['Bukidnon', 'Camiguin', 'Lanao del Norte', 'Misamis Occidental', 'Misamis Oriental'],
        'davao' => ['Davao de Oro', 'Davao del Oro', 'Compostela Valley', 'Davao del Norte', 'Davao del Sur', 'Davao Occidental', 'Davao Oriental'],
        'soccsksargen' => ['Cotabato', 'North Cotabato', 'Sarangani', 'South Cotabato', 'Sultan Kudarat'],
        'caraga' => ['Agusan del Norte', 'Agusan del Sur', 'Dinagat Islands', 'Surigao del Norte', 'Surigao del Sur'],
        'barmm' => ['Basilan', 'Lanao del Sur', 'Maguindanao', 'Maguindanao del Norte', 'Maguindanao del Sur', 'Sulu', 'Tawi-Tawi'],
    ];

    /** Lazily-built normalized-name => region lookup. */
    private static ?array $lookup = null;

    /** Human label for a region key (e.g. 'central-visayas' => 'Central Visayas'). */
    public static function label(string $region): string
    {
        return match ($region) {
            'ncr' => 'NCR',
            'car' => 'CAR',
            'ilocos' => 'Ilocos Region',
            'cagayan-valley' => 'Cagayan Valley',
            'central-luzon' => 'Central Luzon',
            'calabarzon' => 'CALABARZON',
            'mimaropa' => 'MIMAROPA',
            'bicol' => 'Bicol Region',
            'western-visayas' => 'Western Visayas',
            'central-visayas' => 'Central Visayas',
            'eastern-visayas' => 'Eastern Visayas',
            'zamboanga-peninsula' => 'Zamboanga Peninsula',
            'northern-mindanao' => 'Northern Mindanao',
            'davao' => 'Davao Region',
            'soccsksargen' => 'SOCCSKSARGEN',
            'caraga' => 'Caraga',
            'barmm' => 'BARMM',
            default => ucfirst($region),
        };
    }

    /** Normalize a province name to match the map keys (delegates to IslandMap). */
    public static function normalize(string $name): string
    {
        return IslandMap::normalize($name);
    }

    private static function lookup(): array
    {
        if (self::$lookup === null) {
            self::$lookup = [];
            foreach (self::GROUPS as $region => $names) {
                foreach ($names as $n) {
                    self::$lookup[self::normalize($n)] = $region;
                }
            }
        }

        return self::$lookup;
    }

    /** Region key for a province name, or null when unmapped. */
    public static function regionOf(?string $provinceName): ?string
    {
        if ($provinceName === null || $provinceName === '') {
            return null;
        }

        return self::lookup()[self::normalize($provinceName)] ?? null;
    }

    /** Island group a region belongs to, or null for an unknown region key. */
    public static function islandOf(string $region): ?string
    {
        return self::REGION_ISLAND[$region] ?? null;
    }

    /**
     * Given [province_id => province_name], return [province_id => region|null].
     */
    public static function byProvinceIds(array $idToName): array
    {
        $out = [];
        foreach ($idToName as $id => $name) {
            $out[$id] = self::regionOf($name);
        }

        return $out;
    }

    /**
     * Province IDs belonging to a given region, from a [id => name] list. Used to
     * scope a query to one region before grouping by province.
     */
    public static function provinceIdsForRegion(array $idToName, string $region): array
    {
        $ids = [];
        foreach ($idToName as $id => $name) {
            if (self::regionOf($name) === $region) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Province IDs belonging to a given island, composed from this map's regions
     * so the island↔region↔province hierarchy stays internally consistent.
     */
    public static function provinceIdsForIsland(array $idToName, string $island): array
    {
        $ids = [];
        foreach ($idToName as $id => $name) {
            $region = self::regionOf($name);
            if ($region !== null && (self::REGION_ISLAND[$region] ?? null) === $island) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
