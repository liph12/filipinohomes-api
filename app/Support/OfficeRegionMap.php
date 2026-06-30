<?php

namespace App\Support;

/**
 * FH "office region" map — the regional-office grouping a Secretary oversees.
 *
 * This is the `LOCATIONS_LATEST` taxonomy used by the LR regional offices: a raw
 * LR `state` (e.g. "Cebu", "Bukidnon") is mapped to one office region key (e.g.
 * "cebu", "cagayan"). A Secretary (FH role 5) is scoped to a single region key.
 *
 * IMPORTANT — this is NOT {@see RegionMap}. RegionMap is the 17 PSA administrative
 * regions (Bukidnon → northern-mindanao). The office grouping below is a business
 * grouping and disagrees on purpose (Bukidnon → cagayan, i.e. the Cagayan de Oro
 * office). Use OfficeRegionMap for secretary scoping; use RegionMap for the
 * province/island analytics.
 *
 * Name matching reuses {@see IslandMap::normalize()} (lowercase, strip parens /
 * "province of" / non-alpha, collapse whitespace) so the three "Lapu-lapu"
 * spellings, "Cotabato City", etc. all collapse to a single key.
 */
class OfficeRegionMap
{
    /** Ordered office-region keys. */
    public const REGIONS = [
        'cebu', 'davao', 'bohol', 'gensan', 'dumaguete', 'iloilo', 'metro-manila',
        'bacolod', 'cagayan', 'iligan', 'leyte', 'palawan', 'pampanga',
    ];

    /** region key => human label (verbatim from LOCATIONS_LATEST "name"). */
    private const LABELS = [
        'cebu' => 'Cebu',
        'davao' => 'Davao',
        'bohol' => 'Bohol',
        'gensan' => 'Gensan',
        'dumaguete' => 'Dumaguete',
        'iloilo' => 'Iloilo',
        'metro-manila' => 'Metro Manila',
        'bacolod' => 'Bacolod',
        'cagayan' => 'Cagayan',
        'iligan' => 'Iligan',
        'leyte' => 'Leyte',
        'palawan' => 'Palawan',
        'pampanga' => 'Pampanga',
    ];

    /**
     * Office regions that are NOT grouped (LOCATIONS_LATEST grouped=false): they
     * match ONLY on their own label name. Listed separately so they can WIN the
     * lookup over a grouped region that also contains their name (e.g. "Pampanga"
     * appears inside metro-manila's data, but a standalone "pampanga" region wins).
     */
    private const STANDALONE = [
        'bohol' => 'Bohol',
        'iligan' => 'Iligan',
        'palawan' => 'Palawan',
        'pampanga' => 'Pampanga',
    ];

    /**
     * Grouped office regions (LOCATIONS_LATEST grouped=true): region key => member
     * state/province/city names, verbatim from the superior's LOCATIONS_LATEST.
     */
    private const GROUPS = [
        'cebu' => ['Lapu-lapu', 'Cordova', 'Mactan', 'Lapu-lapu City', 'Lapu- lapu City', 'Lapu - lapu City', 'Cebu'],
        // LOCATIONS_LATEST listed davao as a single name, but LR returns
        // province-level states (Davao del Norte/Sur/…), so enumerate the whole
        // Davao region under the davao office.
        'davao' => ['Davao', 'Davao City', 'Davao del Norte', 'Davao del Sur', 'Davao Oriental', 'Davao Occidental', 'Davao de Oro', 'Davao del Oro', 'Compostela Valley'],
        'gensan' => ['General Santos', 'Sultan Kudarat', 'South Cotabato', 'Sarangani', 'Cotabato', 'Cotabato City'],
        'dumaguete' => ['Dumaguete', 'Negros Oriental', 'Siquijor'],
        'iloilo' => ['Iloilo', 'Aklan', 'Capiz', 'Antique', 'Roxas', 'Kalibo', 'Guimaras'],
        'metro-manila' => ['Metro Manila', 'Manila', 'Bulacan', 'Cavite', 'Rizal', 'Laguna', 'Camarines Sur', 'Batangas', 'Ilocos Sur', 'Ilocos Norte', 'Camarines Norte', 'Albay', 'Tarlac', 'Pampanga', 'Pangasinan', 'Sorsogon', 'Masbate', 'Ifugao'],
        'bacolod' => ['Bacolod', 'Negros Occidental'],
        'cagayan' => ['Misamis Oriental', 'Bukidnon', 'Lanao del Norte', 'Zamboanga del Sur', 'Zamboanga del Norte', 'Misamis Occidental', 'Lanao del Sur', 'Butuan', 'Agusan Del Sur', 'Agusan del Norte', 'Surigao del Norte', 'Surigao del Sur', 'Dinagat Islands'],
        'leyte' => ['Leyte', 'Samar', 'Tacloban', 'Southern Leyte', 'Ormoc'],
    ];

    /** Lazily-built normalized-name => region-key lookup. */
    private static ?array $lookup = null;

    /** Human label for an office-region key (e.g. 'metro-manila' => 'Metro Manila'). */
    public static function label(string $region): string
    {
        return self::LABELS[$region] ?? ucfirst($region);
    }

    /** True when $region is one of the known office-region keys. */
    public static function isValid(string $region): bool
    {
        return in_array($region, self::REGIONS, true);
    }

    /**
     * Build the lookup in two passes so the overlap precedence is deterministic:
     *   1) grouped regions (lower precedence)
     *   2) standalone (grouped=false) regions OVERWRITE — they win any overlap.
     *
     * Net effect: "Pampanga" => 'pampanga' (not 'metro-manila'); "Cotabato" /
     * "Cotabato City" => 'gensan' (no standalone Cotabato region, so no conflict).
     */
    private static function lookup(): array
    {
        if (self::$lookup !== null) {
            return self::$lookup;
        }

        $map = [];

        // Pass 1: grouped regions.
        foreach (self::GROUPS as $region => $names) {
            foreach ($names as $name) {
                $map[IslandMap::normalize($name)] = $region;
            }
        }

        // Pass 2: standalone regions overwrite — they win the overlap.
        foreach (self::STANDALONE as $region => $name) {
            $map[IslandMap::normalize($name)] = $region;
        }

        return self::$lookup = $map;
    }

    /**
     * Office-region key for a raw LR state / province name, or null when the state
     * is empty or doesn't map to any office region.
     *
     * Reusable for a future property-location feature too: LOCATIONS_LATEST already
     * carries province-level names (e.g. "Negros Oriental"), so passing a listing's
     * province here would resolve its office region. (Current secretary scoping is
     * by AGENT region, not property location.)
     */
    public static function regionOf(?string $state): ?string
    {
        if ($state === null || trim($state) === '') {
            return null;
        }

        return self::lookup()[IslandMap::normalize($state)] ?? null;
    }
}
