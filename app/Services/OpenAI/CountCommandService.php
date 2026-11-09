<?php

namespace App\Services\OpenAI;

use App\Services\Listing\PriceGuideService;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ASA-GPT's "how many condos are for sale in Lahug?" — the numbers first, from our own listings.
 *
 * The model only EXTRACTS the place and the narrowing (PriceCommandService::extract: city, barangay,
 * For Sale / For Rent, unit size, property type). Every figure — how many in all, for sale, for rent,
 * by unit size, by property type, the price band, how many were listed in the last 30 days — is counted
 * here over the Price Guide's live listing rows, and the sentence is written from those counts, so no
 * number can be invented. A named building or development goes to ProjectCommandService instead.
 */
class CountCommandService extends CacheService
{
    use InsightStream;

    public const NEW_DAYS = 30;

    private const CATEGORIES = ['For Sale', 'For Rent'];

    public function __construct(private readonly PriceGuideService $guide, private readonly PriceCommandService $price)
    {
    }

    public function replyStream(array $thread, $req): StreamedResponse
    {
        $asked = $this->price->extract($thread);
        $place = $this->price->resolvePlace($asked);
        if ($place === null) {
            AsaQuestionLog::outcome($req, 'no_data');

            return $this->streamInsight('count', 'count', ['empty' => true], $this->notFoundText($asked), [], $req);
        }

        $insight = $this->insight($place, $asked);
        $text = self::headline($insight);
        $spot = $insight['place']['label'];
        $what = self::whatPhrase($insight['asked'], 1, '');
        $followUps = array_values(array_filter([
            $insight['totals']['sale_listings'] > 0 && $insight['asked']['category'] !== 'For Rent'
                ? ['label' => 'Show me the ones for sale', 'prompt' => "Show me {$what} for sale in {$spot}"] : null,
            $insight['totals']['rent_listings'] > 0 && $insight['asked']['category'] !== 'For Sale'
                ? ['label' => 'Show me the ones for rent', 'prompt' => "Show me {$what} for rent in {$spot}"] : null,
            $insight['totals']['listings'] > 0
                ? ['label' => 'What do they cost?', 'prompt' => "How much is a {$what} in {$spot}?"] : null,
            ['label' => "About {$insight['place']['city']}", 'prompt' => "Tell me about {$insight['place']['city']} as an area"],
        ]));

        AsaQuestionLog::outcome($req, $insight['totals']['listings'] > 0 ? 'answered' : 'no_data', $spot);

        return $this->streamInsight('count', 'count', $insight, $text, $followUps, $req);
    }

    /**
     * The counts as data for the page's panel. When a barangay has none of what was asked, the city's
     * figures ride along so the answer can point there.
     *
     * @return array<string, mixed>
     */
    public function insight(array $place, array $asked): array
    {
        $rows = $this->guide->rows($place['city_id']);
        $inPlace = $place['barangay_id'] !== null
            ? array_values(array_filter($rows, fn (array $r) => $r['barangay_id'] === $place['barangay_id']))
            : $rows;
        $tally = self::tally($inPlace, $asked);
        $cityTally = $place['barangay_id'] !== null && $tally['totals']['listings'] === 0 ? self::tally($rows, $asked) : null;
        $where = $place['barangay'] ? "{$place['barangay']}, {$place['city']}" : "{$place['city']}, {$place['province']}";

        return [
            'place' => ['label' => $where, 'city' => $place['city'], 'barangay' => $place['barangay'], 'province' => $place['province'], 'scope' => $place['barangay'] ? 'barangay' : 'city'],
            'asked' => [
                'property_type' => (string) ($asked['property_type'] ?? ''),
                'tier' => (string) ($asked['tier'] ?? ''),
                'category' => (string) ($asked['category'] ?? ''),
            ],
            'totals' => $tally['totals'],
            'sections' => $tally['sections'],
            'types' => $tally['types'],
            'inventory' => $tally['inventory'],
            // A city's listings by barangay, so the report says where they sit; a barangay has no split.
            'barangays' => $place['barangay_id'] === null ? self::byBarangay(self::keep($inPlace, $asked)) : [],
            'city_totals' => $cityTally['totals'] ?? null,
            'agents' => $this->guide->topAgents($place['city_id'], $place['barangay_id']),
            'new_days' => self::NEW_DAYS,
            'computed_at' => Carbon::now()->toIso8601String(),
        ];
    }

    // ── Pure counting ─────────────────────────────────────────────────────

    /**
     * Counts over the Price Guide's slim rows, narrowed to what was asked (property type, unit size,
     * For Sale / For Rent). Unlike the price bands, one listing is enough to be counted.
     *
     * @param  array<int, array{category: string, type: string, tier: ?string, price: float, listed_at?: string}>  $rows
     * @param  array{property_type?: string, tier?: string, category?: string}  $asked
     * @return array{totals: array{listings: int, sale_listings: int, rent_listings: int, new_30d: int}, sections: array<int, array<string, mixed>>, types: array<int, array{type: string, listings: int}>, inventory: array<int, array<string, mixed>>}
     */
    public static function tally(array $rows, array $asked): array
    {
        $kept = self::keep($rows, $asked);
        $since = Carbon::now()->subDays(self::NEW_DAYS)->toDateTimeString();
        $isNew = fn (array $r) => isset($r['listed_at']) && (string) $r['listed_at'] >= $since;

        $sections = [];
        foreach (self::CATEGORIES as $c) {
            $in = array_values(array_filter($kept, fn (array $r) => $r['category'] === $c));
            if ($in === []) {
                continue;
            }
            $tiers = [];
            foreach (array_merge(PriceGuideService::tierOrder(), [null]) as $t) {
                $set = array_values(array_filter($in, fn (array $r) => ($r['tier'] ?? null) === $t));
                if ($set === []) {
                    continue;
                }
                $p = array_column($set, 'price');
                $tiers[] = ['tier' => $t ?? 'Other', 'listings' => count($set), 'low' => (float) min($p), 'high' => (float) max($p)];
            }
            $sections[] = [
                'category' => $c,
                'listings' => count($in),
                ...self::band(array_column($in, 'price')),
                'new_30d' => count(array_filter($in, $isNew)),
                'tiers' => $tiers,
                'types' => self::byType($in),
            ];
        }

        return [
            'totals' => [
                'listings' => count($kept),
                'sale_listings' => count(array_filter($kept, fn (array $r) => $r['category'] === 'For Sale')),
                'rent_listings' => count(array_filter($kept, fn (array $r) => $r['category'] === 'For Rent')),
                'new_30d' => count(array_filter($kept, $isNew)),
            ],
            'sections' => $sections,
            'types' => self::byType($kept),
            'inventory' => self::inventory($kept),
        ];
    }

    /**
     * The rows that match what was asked: property type, unit size, For Sale / For Rent.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function keep(array $rows, array $asked): array
    {
        $type = (string) ($asked['property_type'] ?? '');
        $tier = (string) ($asked['tier'] ?? '');
        $cat = (string) ($asked['category'] ?? '');

        return array_values(array_filter($rows, fn (array $r) => self::typeMatches((string) $r['type'], $type)
            && ($tier === '' || ($r['tier'] ?? null) === $tier)
            && ($cat === '' || $r['category'] === $cat)));
    }

    /**
     * Every property type and subtype with how many are for sale and for rent and each side's price
     * band — "warehouses: 9 for sale (₱8M – ₱120M), 3 for rent (₱80K – ₱400K/month)". Most first.
     *
     * @return array<int, array{type: string, subtype: string, listings: int, sale: ?array{listings: int, low: float, high: float}, rent: ?array{listings: int, low: float, high: float}}>
     */
    public static function inventory(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $key = $r['type'].'|'.($r['subtype'] ?? '');
            $groups[$key] ??= ['type' => (string) $r['type'], 'subtype' => (string) ($r['subtype'] ?? ''), 'sale' => [], 'rent' => []];
            $groups[$key][$r['category'] === 'For Rent' ? 'rent' : 'sale'][] = (float) $r['price'];
        }
        $side = fn (array $p) => $p === [] ? null : ['listings' => count($p), 'low' => (float) min($p), 'high' => (float) max($p)];
        $out = [];
        foreach ($groups as $g) {
            $out[] = ['type' => $g['type'], 'subtype' => $g['subtype'], 'listings' => count($g['sale']) + count($g['rent']), 'sale' => $side($g['sale']), 'rent' => $side($g['rent'])];
        }
        usort($out, fn ($a, $b) => [$b['listings'], $a['type'], $a['subtype']] <=> [$a['listings'], $b['type'], $b['subtype']]);

        return $out;
    }

    /**
     * Where the listings sit within a city: each barangay's count, most first, with the sale / rent split.
     *
     * @return array<int, array{barangay: string, listings: int, sale_listings: int, rent_listings: int}>
     */
    public static function byBarangay(array $rows, int $limit = 12): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $name = (string) ($r['barangay'] ?? '') ?: 'Barangay not stated';
            $groups[$name] ??= ['barangay' => $name, 'listings' => 0, 'sale_listings' => 0, 'rent_listings' => 0];
            $groups[$name]['listings']++;
            $groups[$name][$r['category'] === 'For Rent' ? 'rent_listings' : 'sale_listings']++;
        }
        $groups = array_values($groups);
        usort($groups, fn ($a, $b) => $b['listings'] <=> $a['listings'] ?: strcmp($a['barangay'], $b['barangay']));

        return array_slice($groups, 0, $limit);
    }

    /** @return array{low: float, median: float, high: float} */
    private static function band(array $prices): array
    {
        sort($prices);
        $n = count($prices);
        $median = $n % 2 === 1 ? (float) $prices[intdiv($n, 2)] : ((float) $prices[$n / 2 - 1] + (float) $prices[$n / 2]) / 2;

        return ['low' => (float) $prices[0], 'median' => $median, 'high' => (float) $prices[$n - 1]];
    }

    /** @return array<int, array{type: string, listings: int}> */
    private static function byType(array $rows): array
    {
        $counts = array_count_values(array_map(fn (array $r) => (string) $r['type'], $rows));
        arsort($counts);
        $out = [];
        foreach ($counts as $type => $n) {
            $out[] = ['type' => (string) $type, 'listings' => (int) $n];
        }

        return $out;
    }

    /** The Price Guide's reading of a typed property type (mirrors PriceCommandService::typeMatches). */
    public static function typeMatches(string $typeName, string $asked): bool
    {
        if ($asked === '') {
            return true;
        }
        $t = mb_strtolower($typeName);

        return match ($asked) {
            'Condominium' => str_contains($t, 'condo'),
            'House' => str_contains($t, 'house') || str_contains($t, 'town'),
            'Apartment' => str_contains($t, 'apart') || str_contains($t, 'room'),
            'Lot' => str_contains($t, 'lot') || str_contains($t, 'land') || str_contains($t, 'farm'),
            'Commercial' => str_contains($t, 'commercial') || str_contains($t, 'office') || str_contains($t, 'warehouse') || str_contains($t, 'building'),
            default => str_contains($t, mb_strtolower($asked)),
        };
    }

    // ── Words ─────────────────────────────────────────────────────────────

    /**
     * The sentence the counts make, with nothing from the model.
     *
     * "**Lahug, Cebu City** has **143 condo listings** right now: **98 for sale** (₱2.1M – ₱15M; 30 studios,
     * 41 one-bedroom, 22 two-bedroom) and **45 for rent** (₱12K – ₱60K/month; 20 studios, 25 one-bedroom).
     * 12 were listed in the last 30 days."
     */
    public static function headline(array $insight): string
    {
        $t = $insight['totals'];
        $place = $insight['place']['label'];
        $asked = $insight['asked'];

        if ($t['listings'] === 0) {
            $what = self::whatPhrase($asked, 0, '');
            $text = "I have no {$what} in **{$place}** on Filipino Homes right now.";
            $ct = $insight['city_totals'] ?? null;
            if ($ct && $ct['listings'] > 0) {
                $text .= sprintf(' **%s** as a whole has **%d %s**: %d for sale and %d for rent.', $insight['place']['city'], $ct['listings'], self::whatPhrase($asked, $ct['listings'], ''), $ct['sale_listings'], $ct['rent_listings']);
            } else {
                $text .= ' Try the city as a whole or a nearby area, and I can show the closest matches.';
            }

            return $text;
        }

        $band = function (array $s): string {
            $per = $s['category'] === 'For Rent' ? '/month' : '';
            $low = PriceCommandService::peso($s['low']);
            $high = PriceCommandService::peso($s['high']);

            return $low === $high ? $low.$per : "{$low} – {$high}{$per}";
        };

        if ($asked['category'] !== '' && count($insight['sections']) === 1) {
            // One category asked ("how many 2 bedroom condos for rent"): say it once, with the band and,
            // unless the size was asked too, the sizes.
            $s = $insight['sections'][0];
            $sizes = $asked['tier'] === '' ? self::sizesPhrase($s['tiers']) : '';
            $text = sprintf('**%s** has **%d %s** right now (%s%s).', $place, $t['listings'], self::whatPhrase($asked, $t['listings'], ''), $band($s), $sizes !== '' ? "; {$sizes}" : '');
        } else {
            $parts = [];
            foreach ($insight['sections'] as $s) {
                $sizes = $asked['tier'] === '' ? self::sizesPhrase($s['tiers']) : '';
                $parts[] = sprintf('**%d %s** (%s%s)', $s['listings'], $s['category'] === 'For Rent' ? 'for rent' : 'for sale', $band($s), $sizes !== '' ? "; {$sizes}" : '');
            }
            $text = sprintf('**%s** has **%d %s** right now', $place, $t['listings'], self::whatPhrase($asked, $t['listings'], 'noun'));
            $text .= $parts !== [] ? ': '.implode(' and ', $parts).'.' : '.';
        }
        if ($t['new_30d'] > 0) {
            $text .= sprintf(' %s listed in the last %d days.', $t['new_30d'] === $t['listings'] ? ($t['listings'] === 1 ? 'It was' : 'All of them were') : ($t['new_30d'] === 1 ? '1 of them was' : "{$t['new_30d']} of them were"), self::NEW_DAYS);
        }

        return $text;
    }

    /**
     * "condo listings", "one-bedroom condos for sale", "houses", "listings" — what was counted, in words.
     * `$mode` 'noun' keeps the category out of the phrase (the sentence lists the categories after it).
     */
    public static function whatPhrase(array $asked, int $n, string $mode): string
    {
        $type = $asked['property_type'] ?? '';
        $tier = $asked['tier'] ?? '';
        $cat = $asked['category'] ?? '';
        $plural = $n !== 1;
        $noun = match ($type) {
            'Condominium' => $plural ? 'condos' : 'condo',
            'House' => $plural ? 'houses' : 'house',
            'Apartment' => $plural ? 'apartments' : 'apartment',
            'Lot' => $plural ? 'lots' : 'lot',
            'Commercial' => $plural ? 'commercial listings' : 'commercial listing',
            default => $plural ? 'listings' : 'listing',
        };
        if ($type === 'Condominium' && $tier === '' && $mode === 'noun') {
            $noun = $plural ? 'condo listings' : 'condo listing';
        }
        $size = $tier !== '' ? self::tierWord($tier).' ' : '';
        $catWord = $cat !== '' && $mode !== 'noun' ? ' '.mb_strtolower($cat) : '';

        return trim($size.$noun.$catWord);
    }

    /** "4 studios, 5 one-bedroom, 1 two-bedroom, 2 size not stated"; "" when no size is known at all. */
    public static function sizesPhrase(array $tiers): string
    {
        $named = array_filter($tiers, fn (array $t) => $t['tier'] !== 'Other');
        if ($named === []) {
            return '';
        }
        $parts = [];
        foreach ($tiers as $t) {
            $parts[] = $t['tier'] === 'Other'
                ? sprintf('%d %s', $t['listings'], 'size not stated')
                : sprintf('%d %s', $t['listings'], $t['tier'] === 'Studio' ? ($t['listings'] === 1 ? 'studio' : 'studios') : self::tierWord($t['tier']));
        }

        return implode(', ', $parts);
    }

    /** "1 Bedroom" → "one-bedroom", "4+ Bedroom" → "four-bedroom or bigger", "Studio" → "studio". */
    public static function tierWord(string $tier): string
    {
        return match ($tier) {
            'Studio' => 'studio',
            '1 Bedroom' => 'one-bedroom',
            '2 Bedroom' => 'two-bedroom',
            '3 Bedroom' => 'three-bedroom',
            '4+ Bedroom' => 'four-bedroom or bigger',
            default => mb_strtolower($tier),
        };
    }

    private function notFoundText(array $asked): string
    {
        $named = trim(implode(', ', array_filter([$asked['barangay'] ?? '', $asked['city'] ?? ''])));

        return $named !== ''
            ? "I can't find **{$named}** among the places with live listings on Filipino Homes. Check the spelling, or name the city it is in."
            : 'Which city or barangay should I count listings in? You can add a type and size too, like "2 bedroom condos for rent in Lahug".';
    }
}
