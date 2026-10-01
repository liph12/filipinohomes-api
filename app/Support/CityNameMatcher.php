<?php

namespace App\Support;

/**
 * Name-based fallback for linking a city/municipality boundary polygon to a
 * `cities` row, used only after the geometric passes of the relink command
 * have failed to place a polygon.
 *
 * Deliberately PURE PHP: no DB, no container, no config. The relink command
 * hands it plain arrays, which keeps the matching rules unit-testable on the
 * in-memory sqlite suite and keeps the cascade's decisions reviewable without
 * a MySQL spatial build.
 *
 * WHY the spellings diverge at all: the boundary file is an external
 * administrative dataset, while `cities` is 20 years of hand-entered listing
 * data. The two disagree in four reproducible ways, each handled below:
 *
 *   1. Status words — "City of Talisay" / "Talisay City" / "Talisay",
 *      "Island Garden City of Samal" / "Samal", "Municipality of X" / "X".
 *   2. Abbreviations — "Gen. Trias" vs "City of General Trias", and the
 *      Sta./Sto./Gov./Mt. family.
 *   3. Disambiguating parentheticals this DB adds and the map file does not —
 *      "Narra (Panacan)", "Montalban (Rodriguez)".
 *   4. Outright different names for the same place, which cannot be derived
 *      and are listed one by one in CITY_ALIASES.
 *
 * normalize() squashes a name to bare [a-z0-9] with no separators, so every
 * alias key and value below is written in that squashed form.
 */
class CityNameMatcher
{
    /**
     * Longest-distance Levenshtein still accepted by pass C.
     *
     * 2 catches the real typo/transliteration pairs in this data set; 3 starts
     * pairing genuinely different towns.
     */
    private const FUZZY_MAX_DISTANCE = 2;

    /**
     * Minimum normalized length before pass C is allowed to run.
     *
     * Short names are where edit distance lies: "leon" and "oton" are distance
     * 2 apart but are different Iloilo municipalities. Requiring 6 characters
     * pins that false positive out.
     */
    private const FUZZY_MIN_LENGTH = 6;

    /**
     * Abbreviation => expansion, applied as whole tokens with an optional dot.
     *
     * Keep these conservative: every entry must be an abbreviation that cannot
     * also be a real standalone LGU name.
     */
    private const TOKEN_EXPANSIONS = [
        'gen' => 'general',
        'gov' => 'governor',
        'sta' => 'santa',
        'sto' => 'santo',
        'mt' => 'mount',
    ];

    /**
     * Normalized boundary-file spelling => normalized `cities` spelling.
     *
     * Direction is always MAP NAME => DB NAME, because the boundary name is
     * what the relink command is trying to place.
     *
     * Verified against the local DB on 2026-10-01:
     *   bacong            -> cities 1057 "Bacung" (Negros Oriental)
     *   rodriguez         -> cities 1313 "Montalban (Rodriguez)" (Rizal)
     *   salvadorbenedicto -> cities 1716 "Don Salvador" (Negros Occidental)
     *   enriquebmagalona  -> cities 1026 "Enrique Magalona" (Negros Occidental)
     *   lupon             -> cities 548  "Lopon" (Davao Oriental)
     *
     * "Lupon" is one keystroke from the row it belongs to, but the near-miss
     * pass cannot reach it: both names are five characters and the fuzzy stage
     * refuses anything shorter than six, because at that length edit distance
     * stops telling towns apart. An alias is the honest way to state a fact we
     * actually know.
     *
     * The 16 Manila entries are the city districts the boundary file splits
     * Manila into; this DB has a single row 1555 "Manila City", so all 16
     * polygons legitimately claim the same city id (pass A allows many-to-one).
     * "tondoiii" is the squashed form of the file's literal "Tondo I / II".
     *
     * ⚠️ EIGHT of those district names are ALSO ordinary municipalities
     * elsewhere: the ADM3 file alone carries 7 "Santa Cruz", 8 "San Miguel",
     * 4 "San Nicolas", 3 "San Andres", 3 "Santa Ana" and 2 "Sampaloc", plus
     * Paco/Malate look-alikes. So this table is a SECOND-CHANCE lookup only —
     * match() always tries the plain normalized name first, and the candidate
     * side is NEVER alias-folded. Fold both sides and Laguna's Santa Cruz
     * turns into Manila. Every value below is therefore written as the PLAIN
     * normalize() form of the target `cities` row, so the two stages agree.
     */
    private const CITY_ALIASES = [
        'bacong' => 'bacung',
        'rodriguez' => 'montalban',
        // The file drops the "Don"; both spellings are listed so neither side
        // of the rename can miss.
        'donsalvadorbenedicto' => 'donsalvador',
        'salvadorbenedicto' => 'donsalvador',
        'enriquebmagalona' => 'enriquemagalona',
        // Too short for the fuzzy pass (5 chars); see the note above.
        'lupon' => 'lopon',

        // Manila's 16 city districts.
        'binondo' => 'manila',
        'ermita' => 'manila',
        'intramuros' => 'manila',
        'malate' => 'manila',
        'paco' => 'manila',
        'pandacan' => 'manila',
        'portarea' => 'manila',
        'quiapo' => 'manila',
        'sampaloc' => 'manila',
        'sanandres' => 'manila',
        'sanmiguel' => 'manila',
        'sannicolas' => 'manila',
        'santaana' => 'manila',
        'santacruz' => 'manila',
        'santamesa' => 'manila',
        'tondo' => 'manila',
        'tondoiii' => 'manila',
    ];

    /**
     * Squash a city/municipality name to a comparable key.
     *
     * Order matters: transliterate before lowercasing so "Peñaranda" survives
     * as "penaranda"; drop parentheticals before expanding tokens so a
     * parenthesised alias cannot inject one; expand abbreviations before
     * stripping status words so "Gen." is already "general" when "city of"
     * goes; strip the longest status phrase first so "Island Garden City of
     * Samal" does not degrade to "island garden samal".
     */
    public static function normalize(string $name): string
    {
        $s = $name;

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if (is_string($ascii) && $ascii !== '') {
            $s = $ascii;
        }

        $s = mb_strtolower($s);

        // "Narra (Panacan)" => "narra"; also tolerates an unclosed paren.
        $s = preg_replace('/\([^)]*\)?/', ' ', $s);

        foreach (self::TOKEN_EXPANSIONS as $short => $long) {
            $s = preg_replace('/\b'.$short.'\.?(?![a-z])/', $long, $s);
        }

        $s = preg_replace(
            '/\b(?:island garden city of|city of|municipality of|municipality|city)\b/',
            ' ',
            $s
        );

        $s = preg_replace('/[^a-z0-9]/', '', $s);

        return (string) $s;
    }

    /**
     * normalize(), then fold a known alias onto the `cities` spelling.
     *
     * Only ever apply this to a BOUNDARY name. Applying it to a `cities` row
     * would turn every provincial Santa Cruz / San Miguel / San Nicolas into
     * Manila — match() keeps the two sides apart deliberately.
     */
    public static function aliasKey(string $name): string
    {
        $key = self::normalize($name);

        return self::CITY_ALIASES[$key] ?? $key;
    }

    /** The raw alias table, for guard tests and the relink command's report. */
    public static function aliases(): array
    {
        return self::CITY_ALIASES;
    }

    /**
     * Pick the `cities` id a boundary name belongs to, or null.
     *
     * @param  array<int|string, string>  $candidates  [city id => name], the cities of the
     *                                                 polygon's province group. ORDER IS THE
     *                                                 CALLER'S TIE-BREAK: when two rows in the
     *                                                 group carry the same name, the first one
     *                                                 listed wins, so the command passes them
     *                                                 ordered by listing count then by id.
     * @param  string  $boundaryName  the polygon's name, as spelled by the boundary file.
     * @param  int[]  $claimedIds  city ids already taken by another polygon. Exact matches
     *                             ignore this (Manila's 16 districts all claim one city);
     *                             the fuzzy pass respects it, because a second-best guess
     *                             must never steal a city an exact match already earned.
     * @param  array<string, int>  $nationwideUnique  optional [normalize()d name => city id]
     *                                                index containing ONLY names that are unique
     *                                                across the whole country. Pass B consults it
     *                                                so a polygon filed under the wrong province
     *                                                can still land, without risking the 10
     *                                                "San Jose" rows. Empty disables pass B. Build
     *                                                it with normalize(), not aliasKey() — see the
     *                                                warning on CITY_ALIASES.
     */
    public static function match(
        array $candidates,
        string $boundaryName,
        array $claimedIds = [],
        array $nationwideUnique = []
    ): ?int {
        $plain = self::normalize($boundaryName);

        if ($plain === '') {
            return null;
        }

        $aliased = self::CITY_ALIASES[$plain] ?? $plain;

        // The plain spelling is always tried first; the alias is a fallback.
        // That ordering is what lets Laguna's "Santa Cruz" polygon find its own
        // city while Manila's "Santa Cruz" district still reaches Manila City.
        $targets = $aliased === $plain ? [$plain] : [$plain, $aliased];

        // Pass A — exact match inside the province group. Candidate order is
        // the caller's stated preference, so the first hit wins.
        foreach ($targets as $target) {
            foreach ($candidates as $id => $name) {
                if (self::normalize((string) $name) === $target) {
                    return (int) $id;
                }
            }
        }

        // Pass B — exact match nationwide, but only for names the caller has
        // certified unique; anything ambiguous is left for the geometry.
        foreach ($targets as $target) {
            if (isset($nationwideUnique[$target])) {
                return (int) $nationwideUnique[$target];
            }
        }

        // Pass C — near-miss inside the group, on the PLAIN spelling only: an
        // alias is an exact statement about one place, and running a fuzzy
        // search from it would multiply one curated claim into a guess.
        // Guarded by a length floor and by the claimed set; both exist to stop
        // confident wrong answers.
        if (strlen($plain) < self::FUZZY_MIN_LENGTH) {
            return null;
        }

        $claimed = array_flip(array_map('intval', $claimedIds));
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $id => $name) {
            $id = (int) $id;

            if (isset($claimed[$id])) {
                continue;
            }

            $key = self::normalize((string) $name);

            if (strlen($key) < self::FUZZY_MIN_LENGTH) {
                continue;
            }

            $distance = levenshtein($plain, $key);

            if ($distance <= self::FUZZY_MAX_DISTANCE && $distance < $bestDistance) {
                $best = $id;
                $bestDistance = $distance;
            }
        }

        return $best;
    }
}
