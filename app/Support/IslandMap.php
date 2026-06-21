<?php

namespace App\Support;

/**
 * Static Philippine province → island-group (Luzon / Visayas / Mindanao) map.
 *
 * The DB stores only province name + code (no region/island column), so island
 * grouping is resolved here. This is a 1:1 PORT of the frontend map at
 * src/components/dashboard/admin/listing-insights/ListingsByProvince.tsx
 * (PROVINCE_ISLAND + normalize) so backend and frontend grouping agree exactly.
 * Keep the two in sync if province names ever change.
 */
class IslandMap
{
    public const ISLANDS = ['luzon', 'visayas', 'mindanao'];

    /** island => [province display names] — verbatim from the frontend map. */
    private const GROUPS = [
        'luzon' => [
            'Metro Manila', 'NCR', 'National Capital Region',
            'Abra', 'Apayao', 'Benguet', 'Ifugao', 'Kalinga', 'Mountain Province',
            'Ilocos Norte', 'Ilocos Sur', 'La Union', 'Pangasinan',
            'Batanes', 'Cagayan', 'Isabela', 'Nueva Vizcaya', 'Quirino',
            'Aurora', 'Bataan', 'Bulacan', 'Nueva Ecija', 'Pampanga', 'Tarlac', 'Zambales',
            'Batangas', 'Cavite', 'Laguna', 'Quezon', 'Rizal',
            'Marinduque', 'Occidental Mindoro', 'Oriental Mindoro', 'Palawan', 'Romblon',
            'Albay', 'Camarines Norte', 'Camarines Sur', 'Catanduanes', 'Masbate', 'Sorsogon',
        ],
        'visayas' => [
            'Aklan', 'Antique', 'Capiz', 'Guimaras', 'Iloilo', 'Negros Occidental',
            'Bohol', 'Cebu', 'Negros Oriental', 'Siquijor',
            'Biliran', 'Eastern Samar', 'Leyte', 'Northern Samar', 'Samar', 'Western Samar', 'Southern Leyte',
        ],
        'mindanao' => [
            'Zamboanga del Norte', 'Zamboanga del Sur', 'Zamboanga Sibugay',
            'Bukidnon', 'Camiguin', 'Lanao del Norte', 'Misamis Occidental', 'Misamis Oriental',
            'Davao de Oro', 'Davao del Oro', 'Compostela Valley', 'Davao del Norte', 'Davao del Sur', 'Davao Occidental', 'Davao Oriental',
            'Cotabato', 'North Cotabato', 'Sarangani', 'South Cotabato', 'Sultan Kudarat',
            'Agusan del Norte', 'Agusan del Sur', 'Dinagat Islands', 'Surigao del Norte', 'Surigao del Sur',
            'Basilan', 'Lanao del Sur', 'Maguindanao', 'Maguindanao del Norte', 'Maguindanao del Sur', 'Sulu', 'Tawi-Tawi',
        ],
    ];

    /** Lazily-built normalized-name => island lookup. */
    private static ?array $lookup = null;

    /** Human labels for the island keys. */
    public static function label(string $island): string
    {
        return match ($island) {
            'luzon' => 'Luzon',
            'visayas' => 'Visayas',
            'mindanao' => 'Mindanao',
            default => ucfirst($island),
        };
    }

    /**
     * Normalize a province name to match the map keys. Mirrors the frontend
     * normalize(): lowercase, strip (parens), strip "province of", strip
     * non-alpha, collapse whitespace, trim.
     */
    public static function normalize(string $name): string
    {
        $s = mb_strtolower($name);
        $s = preg_replace('/\(.*?\)/', ' ', $s);
        $s = preg_replace('/province of/', ' ', $s);
        $s = preg_replace('/[^a-z\s]/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);

        return trim($s);
    }

    private static function lookup(): array
    {
        if (self::$lookup === null) {
            self::$lookup = [];
            foreach (self::GROUPS as $island => $names) {
                foreach ($names as $n) {
                    self::$lookup[self::normalize($n)] = $island;
                }
            }
        }

        return self::$lookup;
    }

    /** Island key for a province name, or null when unmapped. */
    public static function islandOf(?string $provinceName): ?string
    {
        if ($provinceName === null || $provinceName === '') {
            return null;
        }

        return self::lookup()[self::normalize($provinceName)] ?? null;
    }

    /**
     * Given [province_id => province_name], return [province_id => island|null].
     * Lets a query group by province_id in SQL, then fold provinces into
     * islands in PHP.
     */
    public static function byProvinceIds(array $idToName): array
    {
        $out = [];
        foreach ($idToName as $id => $name) {
            $out[$id] = self::islandOf($name);
        }

        return $out;
    }

    /**
     * Province IDs belonging to a given island, from a [id => name] list.
     * Used to scope a query to one island before grouping by province.
     */
    public static function provinceIdsForIsland(array $idToName, string $island): array
    {
        $ids = [];
        foreach ($idToName as $id => $name) {
            if (self::islandOf($name) === $island) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
