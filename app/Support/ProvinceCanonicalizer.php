<?php

namespace App\Support;

/**
 * Folds the province vocabulary of the ADM2 boundary file and of the
 * `provinces` table onto ONE canonical province per real-world place.
 *
 * Why this exists at all: three vocabularies disagree, and a choropleth that
 * paints one polygon per `provinces.id` would otherwise show duplicate,
 * half-filled or missing areas.
 *
 * 1. The ADM2 boundary source splits Metro Manila into four congressional
 *    districts ("NCR, City of Manila, First District", "NCR, Second District",
 *    "NCR, Third District", "NCR, Fourth District") and carries two
 *    single-city "provinces" ("City of Isabela", "Cotabato City") that the
 *    PSGC treats as independent but this database does not.
 * 2. The ADM2 source carries provinces this database has never had a row for.
 * 3. The `provinces` table itself has duplicate rows.
 *
 * VERIFIED against the local database copy on 2026-10-01 (83 province rows):
 *
 * - Northern Samar exists TWICE: ids 53 and 65, each with its own full set of
 *   cities. Canonical = 53 (lowest id; both normalize to the same key).
 * - "Southern Samar" (id 82) and "Samar" (id 83) are the same province.
 *   Canonical = 83, because its own name normalizes to the canonical key —
 *   the lowest-id rule would have picked 82 and labelled the map wrongly.
 * - "Dinagat Islands" (id 31) EXISTS as a row but owns ZERO cities: its towns
 *   Cagdianao (1448), Dinagat (1449), Loreto (1452), Tubajon (1463) and
 *   San Jose (1464) all sit under Surigao del Norte (73). Listings therefore
 *   only ever resolve to 73, so the Dinagat ADM2 polygon is folded onto 73
 *   rather than shading an area that can never hold a count.
 * - There is NO "Davao Occidental" row. Its towns Santa Maria (536),
 *   Malita (537), Don Marcelino (538) and Jose Abad Santos (539) sit under
 *   Davao del Sur (29), so the ADM2 "Davao Occidental" polygon folds onto it.
 * - Cotabato City is city 901 under Maguindanao (45), not a province.
 * - Isabela City is city 129 under Basilan (9), not a province.
 *
 * The day any of those rows is actually created (a real "Davao Occidental" or
 * "Dinagat Islands" province with cities of its own), the matching alias below
 * MUST be deleted in the same change — otherwise idMap() silently folds the
 * new row's listings into its neighbour. ProvinceCanonicalizerTest states that
 * contract in its test names.
 *
 * Island grouping stays in {@see IslandMap}; this class only decides WHICH
 * province row a name belongs to, never which island.
 */
class ProvinceCanonicalizer
{
    /**
     * Normalized source name => normalized canonical name.
     *
     * Keys and values are both in IslandMap::normalize() form (lowercase,
     * alphabetic + single spaces), so an alias value can be compared directly
     * against another name's normalized form.
     *
     * Every value must be a province name IslandMap knows, and no key may
     * belong to a DIFFERENT island than its value — the guard test asserts
     * both, so a typo here can never quietly move listings between islands.
     */
    private const PROVINCE_ALIASES = [
        // ADM2 splits the capital region into four congressional districts.
        'ncr city of manila first district' => 'metro manila',
        'ncr second district' => 'metro manila',
        'ncr third district' => 'metro manila',
        'ncr fourth district' => 'metro manila',
        // Alternate spellings of the same row (provinces.id 81).
        'ncr' => 'metro manila',
        'national capital region' => 'metro manila',

        // Independent cities that ADM2 ranks as ADM2 but this DB stores as cities.
        'city of isabela' => 'basilan',      // city 129 under province 9
        'cotabato city' => 'maguindanao',    // city 901 under province 45

        // Duplicate / legacy spellings of Samar (province 83; 82 is the dupe).
        'southern samar' => 'samar',
        'western samar' => 'samar',

        // Provinces that exist on the map but not as rows here (see docblock).
        'davao occidental' => 'davao del sur',      // towns 536-539 live under 29
        'dinagat islands' => 'surigao del norte',   // row 31 exists but owns no cities
    ];

    /**
     * Canonical key for a province name: normalized, then alias-folded.
     *
     * Returns the normalized name itself when no alias applies, so the result
     * is always comparable with IslandMap::normalize() output.
     */
    public static function key(string $name): string
    {
        $normalized = IslandMap::normalize($name);

        return self::PROVINCE_ALIASES[$normalized] ?? $normalized;
    }

    /** The raw alias table, for guard tests and for the importer's reporting. */
    public static function aliases(): array
    {
        return self::PROVINCE_ALIASES;
    }

    /**
     * Given [province_id => province_name], return [province_id => canonical id].
     *
     * The canonical id of a group is the id whose OWN normalized name equals
     * the group's canonical key — i.e. the row that is actually called by the
     * canonical name — falling back to the lowest id when no row does (or when
     * several do, in which case the lowest of those wins).
     *
     * That ordering is what makes 82 ("Southern Samar") fold onto 83 ("Samar")
     * rather than the other way round, while 65 ("Northern Samar") still folds
     * onto its lower-id twin 53.
     *
     * @param  array<int|string, string>  $idToName
     * @return array<int, int>
     */
    public static function idMap(array $idToName): array
    {
        /** @var array<string, array{exact: int[], all: int[]}> $groups */
        $groups = [];

        foreach ($idToName as $id => $name) {
            $id = (int) $id;
            $normalized = IslandMap::normalize((string) $name);
            $key = self::PROVINCE_ALIASES[$normalized] ?? $normalized;

            if (! isset($groups[$key])) {
                $groups[$key] = ['exact' => [], 'all' => []];
            }

            $groups[$key]['all'][] = $id;

            if ($normalized === $key) {
                $groups[$key]['exact'][] = $id;
            }
        }

        $out = [];

        foreach ($groups as $group) {
            $pool = $group['exact'] !== [] ? $group['exact'] : $group['all'];
            $canonical = min($pool);

            foreach ($group['all'] as $id) {
                $out[$id] = $canonical;
            }
        }

        return $out;
    }

    /**
     * Every province id in $idToName that shares $id's canonical key, ascending.
     *
     * Used to scope a query to "Samar" and still pick up the rows filed under
     * the duplicate id. An id that is not in the map returns just itself, so a
     * caller can pass a province_id straight through without a guard.
     *
     * @param  array<int|string, string>  $idToName
     * @return int[]
     */
    public static function groupIds(array $idToName, int $id): array
    {
        $map = self::idMap($idToName);

        if (! isset($map[$id])) {
            return [$id];
        }

        $canonical = $map[$id];
        $ids = [];

        foreach ($map as $other => $otherCanonical) {
            if ($otherCanonical === $canonical) {
                $ids[] = (int) $other;
            }
        }

        sort($ids);

        return $ids;
    }
}
