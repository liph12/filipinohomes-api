<?php

namespace App\Support;

/**
 * The decision rules of `boundaries:relink-barangays` for placing one TOWN
 * (one `parent_psgc` group of barangay polygons) onto a `cities` row, given
 * its two independent signals.
 *
 * Deliberately PURE PHP: the command gathers the centroid vote with spatial
 * SQL and the name match with {@see CityNameMatcher}, then hands the results
 * here. That split is what lets the rules run on the in-memory sqlite suite,
 * where no ST_* function exists.
 *
 * ── The two signals ────────────────────────────────────────────────────────
 * Geometry vote: the centroid of every polygon in the group is tested against
 * the linked city polygons; each polygon casts one vote for the city it falls
 * in. Majority wins, a tie goes to the lower city id (Manila's 14 district
 * polygons all carry one city_id, so they never tie against each other).
 *
 * Name path: the group's town name (ADM3_EN) matched by CityNameMatcher inside
 * the province the source files it under (ADM2_EN) — exact, alias, or a
 * fenced near-miss.
 *
 * ── Why an exact name beats the vote on a disagreement ─────────────────────
 * The city layer is a different and coarser source (2020 vintage, 148 of its
 * polygons unlinked). A town whose own city polygon is unlinked, or whose
 * border was drawn differently, has its centroids fall into a NEIGHBOUR's
 * polygon — a confident wrong vote. An exact name match scoped to the right
 * province, by contrast, is one town once. So on a disagreement the exact
 * name wins and the pair is printed for a human; a near-miss name is a guess
 * and does not overrule geometric evidence.
 */
final class BarangayGroupPlacement
{
    /** Vote and name agree. */
    public const GEO_AND_NAME = 'geo+name';

    /** Disagreed; the exact in-province name match won. */
    public const NAME_WINS = 'name-wins';

    /** Disagreed; the name was only a near-miss, so the vote won. */
    public const GEO_WINS = 'geo-wins';

    /** Only the vote spoke. */
    public const GEO_ONLY = 'geo-only';

    /** Only the name spoke (a town with no linked city polygon to vote in). */
    public const NAME_ONLY = 'name-only';

    /** Report row order; also the complete vocabulary of `boundaries.link_how`. */
    public const HOWS = [
        self::GEO_AND_NAME,
        self::NAME_WINS,
        self::GEO_WINS,
        self::GEO_ONLY,
        self::NAME_ONLY,
    ];

    /**
     * Majority of one group's vote rows.
     *
     * @param  iterable<array{city_id: int|string, province_id?: int|string|null, votes: int|string}|object>  $rows
     *                                                                                                               one row per (city, province) the group's centroids fell in
     * @return array{city_id: int, province_id: int|null, votes: int, total: int}|null null when nothing voted
     */
    public static function majority(iterable $rows): ?array
    {
        $best = null;
        $total = 0;

        foreach ($rows as $row) {
            $cityId = (int) self::field($row, 'city_id');
            $provinceId = self::field($row, 'province_id');
            $votes = (int) self::field($row, 'votes');
            $total += $votes;

            if (
                $best === null
                || $votes > $best['votes']
                || ($votes === $best['votes'] && $cityId < $best['city_id'])
            ) {
                $best = [
                    'city_id' => $cityId,
                    'province_id' => $provinceId === null ? null : (int) $provinceId,
                    'votes' => $votes,
                    'total' => 0,
                ];
            }
        }

        if ($best === null) {
            return null;
        }

        $best['total'] = $total;

        return $best;
    }

    /**
     * Which city a group belongs to, and how that was decided.
     *
     * @param  int|null  $voteCity  the vote's majority city, or null when no centroid fell in a linked city
     * @param  int|null  $nameCity  the name path's city, or null when the name matched nothing
     * @param  bool  $nameExact  true when the name match was exact (plain or alias), false for a near-miss
     * @param  bool  $sameTown  true when both ids are present and are twin rows of ONE town (same
     *                          {@see CityGroup::key()}); the two signals then agree and the name
     *                          path's row — ordered to prefer the listing-bearing twin — is kept
     * @return array{city_id: int|null, how: string|null}
     */
    public static function decide(?int $voteCity, ?int $nameCity, bool $nameExact, bool $sameTown = false): array
    {
        if ($voteCity === null && $nameCity === null) {
            return ['city_id' => null, 'how' => null];
        }

        if ($voteCity === null) {
            return ['city_id' => $nameCity, 'how' => self::NAME_ONLY];
        }

        if ($nameCity === null) {
            return ['city_id' => $voteCity, 'how' => self::GEO_ONLY];
        }

        if ($voteCity === $nameCity) {
            return ['city_id' => $nameCity, 'how' => self::GEO_AND_NAME];
        }

        if ($sameTown) {
            return ['city_id' => $nameCity, 'how' => self::GEO_AND_NAME];
        }

        return $nameExact
            ? ['city_id' => $nameCity, 'how' => self::NAME_WINS]
            : ['city_id' => $voteCity, 'how' => self::GEO_WINS];
    }

    private static function field(array|object $row, string $name): mixed
    {
        return is_array($row) ? ($row[$name] ?? null) : ($row->{$name} ?? null);
    }
}
