<?php

namespace App\Support;

/**
 * Links a barangay boundary polygon to a `barangays` row by NAME, inside one
 * city only. Sibling of {@see CityNameMatcher}, one administrative level down.
 *
 * Deliberately PURE PHP: no DB, no container, no config. The relink command
 * hands it plain arrays, so every rule here is unit-testable on the in-memory
 * sqlite suite and reviewable without a spatial MySQL build.
 *
 * ── Why barangay names need their own matcher ──────────────────────────────
 * The `barangays` registry is name-only (no PSGC code, no coordinates) and was
 * hand-entered over 20 years; the boundary file is the official PSA/NAMRIA
 * attribute table. Measured over all 42,048 polygons × 42,334 registry rows,
 * the two disagree in ways the city matcher never meets:
 *
 *   - the Poblacion marker: 2,774 polygons carry a "(Pob.)" suffix the registry
 *     omits ("Lahug (Pob.)" vs "Lahug"), while a bare "Poblacion" / "Pob." /
 *     "Población" IS the name of 527 registry rows;
 *   - numbering: "Sambag I" vs "Sambag 1", "Zone II-A" vs "Zone 2A",
 *     "Barangay 01" vs "Barangay 1";
 *   - parentheticals that are sometimes an ALTERNATE NAME ("Tejero (Villa
 *     Gonzalo)" vs "Villa Gonzalo") and sometimes a DISTRICT QUALIFIER that
 *     must stay part of the name — Iloilo City has distinct "San Isidro (Jaro)"
 *     and "San Isidro (La Paz)", and Cabatuan numbers its zones "Zone I Pob.
 *     (Barangay 1)" … "Zone XI Pob. (Barangay 11)";
 *   - a registry that lumps numbered siblings into one row ("Fatima" for the
 *     file's "Fatima I" … "Fatima V"), and renamed barangays that keep the old
 *     name as a hyphen prefix ("Acmac-Mariano Badelles Sr." vs "Acmac").
 *
 * EVERY rule below was forced by a measured miss or a measured false match in
 * the prototype that produced the plan's numbers (38,433 of 42,048 polygons
 * linked; 98.8% of listings covered). Change one and re-run the relink dry run
 * against the recorded result before trusting it.
 *
 * ── The three key tiers of a name ──────────────────────────────────────────
 *   full  = outer|inner   "San Isidro (Jaro)" → "sanisidro|jaro". The "|" is
 *                         what keeps "Zone I (Barangay 1)" = "zone1|1" apart
 *                         from "Zone XI" = "zone11".
 *   outer = parentheticals and the Pob marker dropped   "Lahug (Pob.)" → "lahug";
 *                         a bare "Poblacion"/"Pob."/"Población" keys as
 *                         "poblacion". The variant that KEEPS the pob word is
 *                         also an outer key so two names that both spell out
 *                         "Asinan Poblacion" still meet.
 *   inner = each parenthetical alone   "Tejero (Villa Gonzalo)" → "villagonzalo",
 *                         usable only when it is an alternate NAME: an inner
 *                         text shared by two or more names of the same city,
 *                         or equal to the city's own name, is a district
 *                         qualifier and is excluded ({@see qualifierInners()}).
 *
 * ── The six tiers of matching, run in this order over a WHOLE city ─────────
 *   1 exact_full   full key                       claims exclusively
 *   2 exact_outer  outer key                      claims exclusively
 *   3 alt_name     polygon inner ↔ registry outer, or polygon outer ↔ registry
 *                  inner                          claims exclusively
 *   4 fuzzy        Levenshtein ≤ 2 on the outer key, both keys ≥ 6 chars,
 *                  unclaimed rows only, REFUSED when the two keys differ only
 *                  by a trailing numeral ("Sambag I" vs "Sambag II" are
 *                  siblings one edit apart, not a typo)   claims exclusively
 *   5 fanin        many numbered polygons → one unclaimed registry row
 *                  ("Fatima I…V" → "Fatima", "(Pob.)" polygons → a bare
 *                  "Poblacion" row). The ONLY many-to-one allowed, so the
 *                  target is deliberately NOT claimed.
 *   6 prefix       hyphen-prefix rename "Acmac-Mariano Badelles Sr." → "Acmac"
 *                                                 claims exclusively
 *
 * Each tier finishes over every polygon of the city before the next starts,
 * so an exact match can never lose its row to an earlier polygon's guess.
 * {@see matchCity()} is that whole-city run; {@see match()} runs the same
 * tiers for one polygon when the caller manages the order itself.
 */
class BarangayNameMatcher
{
    public const HOW_EXACT_FULL = 'exact_full';

    public const HOW_EXACT_OUTER = 'exact_outer';

    public const HOW_ALT_NAME = 'alt_name';

    public const HOW_FUZZY = 'fuzzy';

    public const HOW_FANIN = 'fanin';

    public const HOW_PREFIX = 'prefix';

    public const HOW_UNMATCHED = 'unmatched';

    /** The tiers in the order they run; also the report's row order. */
    public const TIERS = [
        self::HOW_EXACT_FULL,
        self::HOW_EXACT_OUTER,
        self::HOW_ALT_NAME,
        self::HOW_FUZZY,
        self::HOW_FANIN,
        self::HOW_PREFIX,
    ];

    /** Longest edit distance tier 4 still accepts; 3 starts pairing different barangays. */
    private const FUZZY_MAX_DISTANCE = 2;

    /** Shortest outer key tier 4 may compare; below this, edit distance stops telling places apart. */
    private const FUZZY_MIN_LENGTH = 6;

    /**
     * Shortest stem a numbered polygon may fan in on. "Zone 1" → stem "zone"
     * (4) is allowed; a 3-letter stem would pair unrelated short names.
     */
    private const FANIN_MIN_BASE_LENGTH = 4;

    /** Shortest hyphen prefix tier 6 may use as a name on its own. */
    private const PREFIX_MIN_LENGTH = 5;

    /** Roman numerals the data uses, as whole lowercase tokens. */
    private const ROMAN = [
        'i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4, 'v' => 5, 'vi' => 6, 'vii' => 7, 'viii' => 8, 'ix' => 9, 'x' => 10,
        'xi' => 11, 'xii' => 12, 'xiii' => 13, 'xiv' => 14, 'xv' => 15, 'xvi' => 16, 'xvii' => 17, 'xviii' => 18, 'xix' => 19, 'xx' => 20,
        'xxi' => 21, 'xxii' => 22, 'xxiii' => 23, 'xxiv' => 24, 'xxv' => 25, 'xxvi' => 26, 'xxvii' => 27, 'xxviii' => 28, 'xxix' => 29, 'xxx' => 30,
    ];

    /**
     * Abbreviation => expansion, applied to whole lowercase tokens AFTER
     * punctuation is gone (so "Sto." and "Sto" are one token "sto").
     *
     * "st" → "san" is deliberate: the registry writes "St. Niño" where the
     * file writes "San Niño" ("Sto." keeps expanding to "Santo", so the two
     * saint spellings stay distinct keys). Generic unit words ("Brgy.", "Bo.",
     * "Sitio", "Purok", "Barangay") expand to nothing, so "Barangay 1" and
     * "Brgy. 1" both key as "1".
     */
    private const TOKEN_EXPANSIONS = [
        'sto' => 'santo',
        'sta' => 'santa',
        'gen' => 'general',
        'pres' => 'president',
        'mt' => 'mount',
        'st' => 'san',
        'snr' => 'senior',
        'sr' => 'senior',
        'jr' => 'junior',
        'dr' => 'doctor',
        'brgy' => '',
        'bgy' => '',
        'bgry' => '',
        'barangay' => '',
        'bo' => '',
        'barrio' => '',
        'sitio' => '',
        'purok' => '',
    ];

    /**
     * Characters the data actually contains that must survive as a plain
     * letter, mapped explicitly so the result never depends on which of
     * intl / iconv the host has or how its iconv transliterates.
     */
    private const TRANSLIT = [
        'ñ' => 'n', 'Ñ' => 'N',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'Å' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C',
    ];

    /** The Poblacion marker as a word, in every spelling the two sources use. */
    private const POB_PATTERN = '/\b(?:pob\.?|poblacion|población|poblacíon|poblasyon|pobl\.?)\b/iu';

    /** The Poblacion marker as a parenthetical suffix: "Lahug (Pob.)". */
    private const POB_PAREN_PATTERN = '/\(\s*(?:pob\.?|poblacion|población|poblasyon)\s*\)/iu';

    /** "Proper" is a droppable suffix ("Minuyan Proper" = "Minuyan"), not a marker. */
    private const PROPER_PATTERN = '/\bproper\b/iu';

    /** A trailing numeral, with an optional letter: "fatima1", "zone2a". */
    private const NUMBER_SUFFIX_PATTERN = '/^(.*?)(\d+[a-z]?)$/';

    // ───────────────────────────── normalisation ─────────────────────────────

    /**
     * The single comparison key of a barangay name: its tier-2 OUTER key.
     *
     * "Lahug (Pob.)" → "lahug", "Sambag II" → "sambag2", "Población" →
     * "poblacion", "Minuyan Proper" → "minuyan". This is what the fuzzy tier
     * measures distance on and what callers should use as a plain fold key
     * when parentheticals must NOT separate rows; use {@see fullKey()} when
     * they must.
     */
    public static function normalize(string $name): string
    {
        [$outer, , $isPob] = self::parts($name);
        $key = self::keyOf($outer);

        if ($key !== '') {
            return $key;
        }

        return $isPob ? 'poblacion' : self::squash($outer);
    }

    /**
     * The identity key of ONE registry row, for folding a town's duplicate
     * rows together: the whole name squashed, parentheticals and all.
     *
     * NOT {@see fullKey()}, and the difference matters. fullKey() exists for
     * MATCHING two sources that spell the same place differently, so it drops
     * the Poblacion marker and the word "Proper". Inside ONE source a spelling
     * difference is a real difference: measured over the whole registry, 377
     * groups of rows share a fullKey inside one city, and while 361 of them
     * are the intended identical-name twins (Talisay's two "Lagtang" rows), 16
     * join rows the PSA file lists as SEPARATE barangays — Subic's "Asinan
     * Poblacion" (#31695) and "Asinan Proper" (#31696), San Jose del Monte's
     * "Minuyan" and "Minuyan Proper", and so on, most of which own a polygon
     * each. Folding those onto one row left the second polygon with no counts
     * row at all, which the map then labels as not being in the registry.
     *
     * So: "Lagtang"/"Lagtang" still folds, "Minuyan"/"Minuyan Proper" and
     * "San Isidro (Jaro)"/"San Isidro (La Paz)" stay apart. Returns '' for a
     * name with nothing to key on; the caller decides what that means.
     */
    public static function foldKey(string $name): string
    {
        return self::squash($name);
    }

    /**
     * The MATCHING key across the two sources: outer|inner when the name
     * carries a parenthetical, else the outer key. Keeps Iloilo's two "San
     * Isidro" rows apart while still meeting a differently-spelled twin.
     *
     * To fold duplicate rows of ONE source use {@see foldKey()} instead.
     */
    public static function fullKey(string $name): string
    {
        $tiers = self::tiers($name);

        if ($tiers['full'] !== []) {
            $full = $tiers['full'];
            sort($full);

            return $full[0];
        }

        return self::normalize($name);
    }

    /**
     * The three key tiers of one name (see the class docblock).
     *
     * `outer` lists the pob-kept variant FIRST when it differs from the
     * stripped one, so "Asinan Poblacion" prefers a registry row of exactly
     * that spelling over a bare "Asinan".
     *
     * @return array{full: string[], outer: string[], inner: string[], is_pob: bool}
     */
    public static function tiers(string $name): array
    {
        [$outer, $inners, $isPob] = self::parts($name);

        $outerKey = self::keyOf($outer);
        if ($outerKey === '' && $isPob) {
            $outerKey = 'poblacion';
        }
        $outerRaw = self::keyOf($outer, false);

        $innerKeys = [];
        foreach ($inners as $inner) {
            $k = self::keyOf($inner);
            if ($k !== '' && ! in_array($k, $innerKeys, true)) {
                $innerKeys[] = $k;
            }
        }

        $full = [];
        if ($outerKey !== '') {
            foreach ($innerKeys as $k) {
                $full[] = $outerKey.'|'.$k;
            }
        } else {
            foreach ($innerKeys as $k) {
                $full[] = '|'.$k;
            }
        }

        $outerSet = [];
        foreach ([$outerRaw, $outerKey] as $k) {
            if ($k !== '' && ! in_array($k, $outerSet, true)) {
                $outerSet[] = $k;
            }
        }

        return ['full' => $full, 'outer' => $outerSet, 'inner' => $innerKeys, 'is_pob' => $isPob];
    }

    /**
     * Inner texts that are district QUALIFIERS rather than alternate names,
     * for one city's list of names: every inner key shared by two or more
     * names ("(Jaro)" on five Iloilo barangays), plus the city's own name when
     * given ("Poblacion (Dumalneg)" inside Dumalneg).
     *
     * The relink command calls this once per city for the polygon side; the
     * matcher calls it itself for the registry side.
     *
     * @param  string[]  $names
     * @return array<string, true>
     */
    public static function qualifierInners(array $names, ?string $cityName = null): array
    {
        $count = [];
        foreach ($names as $name) {
            foreach (self::tiers((string) $name)['inner'] as $k) {
                $count[$k] = ($count[$k] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($count as $k => $n) {
            if ($n >= 2) {
                $out[$k] = true;
            }
        }

        if ($cityName !== null) {
            $cityKey = self::squash($cityName);
            if ($cityKey !== '') {
                $out[$cityKey] = true;
            }
        }

        return $out;
    }

    /**
     * Lowercase ASCII, apostrophes removed, roman numerals → digits, tokens
     * expanded, leading zeros dropped, everything squashed to [a-z0-9].
     */
    public static function squash(string $text): string
    {
        $s = mb_strtolower(self::translit($text));
        $s = str_replace(["'", '’', '`'], '', $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);

        $out = '';
        foreach (preg_split('/\s+/', trim((string) $s), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            if (isset(self::ROMAN[$tok])) {
                $tok = (string) self::ROMAN[$tok];
            } elseif (preg_match('/^([ivx]+)([a-z])$/', $tok, $m) === 1 && isset(self::ROMAN[$m[1]])) {
                // "II-A" arrives as the tokens "ii" + "a"; a fused "iia" is this branch.
                $tok = self::ROMAN[$m[1]].$m[2];
            } elseif (array_key_exists($tok, self::TOKEN_EXPANSIONS)) {
                $tok = self::TOKEN_EXPANSIONS[$tok];
            }

            if ($tok !== '' && ctype_digit($tok)) {
                $tok = ltrim($tok, '0');
                if ($tok === '') {
                    $tok = '0';
                }
            }

            $out .= $tok;
        }

        return $out;
    }

    // ─────────────────────────────── matching ────────────────────────────────

    /**
     * Run the six tiers for ONE polygon against a city's registry rows.
     *
     * Prefer {@see matchCity()}: it runs each tier over every polygon before
     * the next, which is what the claim semantics assume. This single-polygon
     * form exists for callers that drive the order themselves and for tests.
     *
     * @param  array<int|string, string>  $candidates  [barangay id => name], the registry rows of
     *                                                 the polygon's city. ORDER IS THE CALLER'S
     *                                                 TIE-BREAK: same-name twins resolve to the
     *                                                 first one listed, so pass them ordered by
     *                                                 listing count desc, then id.
     * @param  string  $polygonName  the polygon's name as the boundary file spells it.
     * @param  int[]  $claimedIds  registry ids already taken by another polygon; never returned.
     * @param  array{city_name?: ?string, polygon_names?: string[]}  $cityContext
     *                                                                             `polygon_names` = every polygon name of the city (for the qualifier
     *                                                                             exclusion; defaults to just this one), `city_name` = the city's name.
     * @return array{id: int, how: string}|null
     */
    public static function match(
        array $candidates,
        string $polygonName,
        array $claimedIds = [],
        array $cityContext = []
    ): ?array {
        $index = self::indexCandidates($candidates);
        $qualifiers = self::qualifierInners(
            $cityContext['polygon_names'] ?? [$polygonName],
            $cityContext['city_name'] ?? null
        );
        $poly = self::describePolygon($polygonName, $qualifiers);
        $claimed = array_fill_keys(array_map('intval', $claimedIds), true);
        $memo = [];

        foreach (self::TIERS as $how) {
            $id = self::runTier($how, $poly, $index, $claimed, $memo);
            if ($id !== null) {
                return ['id' => $id, 'how' => $how];
            }
        }

        return null;
    }

    /**
     * Link every polygon of one city, tier by tier.
     *
     * @param  array<int|string, string>  $candidates  [barangay id => name] in preference order (see match()).
     * @param  array<string, string>  $polygons  [polygon code => polygon name].
     * @param  string|null  $cityName  the city's own name, excluded as an inner text.
     * @return array<string, array{id: int|null, how: string}> keyed like $polygons, same order.
     */
    public static function matchCity(array $candidates, array $polygons, ?string $cityName = null): array
    {
        $index = self::indexCandidates($candidates);
        $qualifiers = self::qualifierInners(array_values($polygons), $cityName);

        $pending = [];
        foreach ($polygons as $code => $name) {
            $pending[$code] = self::describePolygon((string) $name, $qualifiers);
        }

        /** @var array<int, true> $claimed */
        $claimed = [];
        /** @var array<string, int> $memo */
        $memo = [];
        $result = [];

        foreach (self::TIERS as $how) {
            foreach ($pending as $code => $poly) {
                $id = self::runTier($how, $poly, $index, $claimed, $memo);
                if ($id === null) {
                    continue;
                }
                $result[$code] = ['id' => $id, 'how' => $how];
                unset($pending[$code]);
            }
        }

        foreach ($pending as $code => $poly) {
            $result[$code] = ['id' => null, 'how' => self::HOW_UNMATCHED];
        }

        // Hand the rows back in the caller's polygon order.
        $ordered = [];
        foreach ($polygons as $code => $name) {
            $ordered[$code] = $result[$code];
        }

        return $ordered;
    }

    // ─────────────────────────────── internals ───────────────────────────────

    /**
     * One tier for one polygon. Tiers 1–4 and 6 add the row to $claimed; tier 5
     * does not, and remembers its target per stem so every sibling lands on
     * the same row.
     *
     * @param  array{tiers: array, primary: string, outer_text: string}  $poly
     * @param  array<int, true>  $claimed
     * @param  array<string, int>  $memo
     */
    private static function runTier(string $how, array $poly, array $index, array &$claimed, array &$memo): ?int
    {
        $tiers = $poly['tiers'];

        switch ($how) {
            case self::HOW_EXACT_FULL:
                foreach ($tiers['full'] as $k) {
                    $hit = self::take($index['full'][$k] ?? [], $claimed);
                    if ($hit !== null) {
                        return self::claim($hit, $claimed);
                    }
                }

                return null;

            case self::HOW_EXACT_OUTER:
                foreach ($tiers['outer'] as $k) {
                    $hit = self::take($index['outer'][$k] ?? [], $claimed);
                    if ($hit !== null) {
                        return self::claim($hit, $claimed);
                    }
                }

                return null;

            case self::HOW_ALT_NAME:
                foreach ($tiers['inner'] as $k) {
                    $hit = self::take($index['outer'][$k] ?? [], $claimed);
                    if ($hit !== null) {
                        return self::claim($hit, $claimed);
                    }
                }
                foreach ($tiers['outer'] as $k) {
                    $hit = self::take($index['inner'][$k] ?? [], $claimed);
                    if ($hit !== null) {
                        return self::claim($hit, $claimed);
                    }
                }

                return null;

            case self::HOW_FUZZY:
                $hit = self::fuzzy($poly['primary'], $index, $claimed);

                return $hit !== null ? self::claim($hit, $claimed) : null;

            case self::HOW_FANIN:
                return self::fanIn($poly['primary'], $tiers['is_pob'], $index, $claimed, $memo);

            case self::HOW_PREFIX:
                $outer = $poly['outer_text'];
                if (! str_contains($outer, '-')) {
                    return null;
                }
                $head = self::keyOf(explode('-', $outer, 2)[0]);
                if (strlen($head) < self::PREFIX_MIN_LENGTH) {
                    return null;
                }
                $hit = self::take($index['outer'][$head] ?? [], $claimed);

                return $hit !== null ? self::claim($hit, $claimed) : null;
        }

        return null;
    }

    /**
     * Tier 4. Distance is measured on the outer keys; the best (lowest)
     * distance wins and a tie goes to the earlier candidate. Refuses the pair
     * when the two keys share a stem and differ only by a trailing numeral:
     * "fatima1" vs "fatima", "sambag1" vs "sambag2" are siblings, not typos.
     *
     * @param  array<int, true>  $claimed
     */
    private static function fuzzy(string $primary, array $index, array $claimed): ?int
    {
        if (strlen($primary) < self::FUZZY_MIN_LENGTH) {
            return null;
        }

        $stem = self::stripNumber($primary) ?? $primary;
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($index['primary'] as $id => $key) {
            if (isset($claimed[$id]) || strlen($key) < self::FUZZY_MIN_LENGTH) {
                continue;
            }

            if ((self::stripNumber($key) ?? $key) === $stem) {
                continue;
            }

            $distance = levenshtein($primary, $key);
            if ($distance <= self::FUZZY_MAX_DISTANCE && $distance < $bestDistance) {
                $best = $id;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * Tier 5. A numbered polygon ("Fatima I", "Tanza 2") lands on the
     * unclaimed registry row named by its stem; failing that, a Poblacion-
     * marked polygon lands on the city's bare "Poblacion" row. The target is
     * never claimed — sharing it is the point — and is memoised per stem.
     *
     * @param  array<int, true>  $claimed
     * @param  array<string, int>  $memo
     */
    private static function fanIn(string $primary, bool $isPob, array $index, array $claimed, array &$memo): ?int
    {
        $base = self::stripNumber($primary);
        if ($base !== null && strlen($base) >= self::FANIN_MIN_BASE_LENGTH) {
            $target = self::take($index['outer'][$base] ?? [], $claimed) ?? ($memo[$base] ?? null);
            if ($target !== null) {
                $memo[$base] = $target;

                return $target;
            }
        }

        if ($isPob) {
            $target = self::take($index['outer']['poblacion'] ?? [], $claimed) ?? ($memo['poblacion'] ?? null);
            if ($target !== null) {
                $memo['poblacion'] = $target;

                return $target;
            }
        }

        return null;
    }

    /**
     * Per-tier lookup tables for one city's registry rows, in candidate order.
     * Inner keys shared by two or more registry names are left out, mirroring
     * the polygon side's qualifier exclusion.
     *
     * @param  array<int|string, string>  $candidates
     * @return array{full: array<string, int[]>, outer: array<string, int[]>, inner: array<string, int[]>, primary: array<int, string>}
     */
    private static function indexCandidates(array $candidates): array
    {
        $qualifiers = self::qualifierInners(array_values($candidates));
        $index = ['full' => [], 'outer' => [], 'inner' => [], 'primary' => []];

        foreach ($candidates as $id => $name) {
            $id = (int) $id;
            $name = (string) $name;
            $tiers = self::tiers($name);

            foreach ($tiers['full'] as $k) {
                $index['full'][$k][] = $id;
            }
            foreach ($tiers['outer'] as $k) {
                $index['outer'][$k][] = $id;
            }
            foreach ($tiers['inner'] as $k) {
                if (! isset($qualifiers[$k])) {
                    $index['inner'][$k][] = $id;
                }
            }
            $index['primary'][$id] = self::normalize($name);
        }

        return $index;
    }

    /**
     * @param  array<string, true>  $qualifiers
     * @return array{tiers: array, primary: string, outer_text: string}
     */
    private static function describePolygon(string $name, array $qualifiers): array
    {
        $tiers = self::tiers($name);
        $tiers['inner'] = array_values(array_filter(
            $tiers['inner'],
            fn (string $k) => ! isset($qualifiers[$k])
        ));
        [$outer] = self::parts($name);

        return ['tiers' => $tiers, 'primary' => self::normalize($name), 'outer_text' => $outer];
    }

    /**
     * First id of $ids not yet claimed, honouring the caller's order.
     *
     * @param  int[]  $ids
     * @param  array<int, true>  $claimed
     */
    private static function take(array $ids, array $claimed): ?int
    {
        foreach ($ids as $id) {
            if (! isset($claimed[$id])) {
                return $id;
            }
        }

        return null;
    }

    /** @param  array<int, true>  $claimed */
    private static function claim(int $id, array &$claimed): int
    {
        $claimed[$id] = true;

        return $id;
    }

    /**
     * Split a name into its outer text and its parenthetical inner texts, with
     * the "(Pob.)" marker removed from both and reported as a flag.
     *
     * @return array{0: string, 1: string[], 2: bool}
     */
    private static function parts(string $name): array
    {
        $s = trim($name);
        $isPob = preg_match(self::POB_PAREN_PATTERN, $s) === 1 || preg_match(self::POB_PATTERN, $s) === 1;
        $s = (string) preg_replace(self::POB_PAREN_PATTERN, ' ', $s);

        // Also tolerates an unclosed paren, like CityNameMatcher.
        $outer = (string) preg_replace('/\([^)]*\)?/', ' ', $s);

        preg_match_all('/\(([^)]*)\)/', $s, $m);

        return [$outer, $m[1], $isPob];
    }

    /** squash() with the Pob marker (optionally) and "Proper" dropped first. */
    private static function keyOf(string $text, bool $dropPob = true): string
    {
        $t = $dropPob ? (string) preg_replace(self::POB_PATTERN, ' ', $text) : $text;
        $t = (string) preg_replace(self::PROPER_PATTERN, ' ', $t);

        return self::squash($t);
    }

    /** "fatima1" → "fatima", "zone2a" → "zone"; null when there is no trailing numeral or nothing before it. */
    private static function stripNumber(string $key): ?string
    {
        if (preg_match(self::NUMBER_SUFFIX_PATTERN, $key, $m) !== 1 || $m[1] === '') {
            return null;
        }

        return $m[1];
    }

    /**
     * Fold accented letters onto plain ASCII: the explicit table first, then
     * a decomposition pass for anything else, then iconv as a last resort.
     * Characters that still resist become token separators in squash().
     */
    private static function translit(string $s): string
    {
        $s = strtr($s, self::TRANSLIT);

        if (preg_match('/[^\x00-\x7F]/', $s) !== 1) {
            return $s;
        }

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($s, \Normalizer::FORM_KD);
            if (is_string($decomposed)) {
                $s = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }

        if (preg_match('/[^\x00-\x7F]/', $s) === 1) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
            if (is_string($ascii) && $ascii !== '') {
                $s = $ascii;
            }
        }

        return $s;
    }
}
