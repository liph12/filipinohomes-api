<?php

namespace App\Services\OpenAI;

use App\Services\Listing\PriceGuideService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ASA-GPT's "tell me about Lahug" — an area at a glance, from our own listings.
 *
 * The model only EXTRACTS the place (PriceCommandService::extract, the same where/what extractor the
 * Price Guide uses); every number — how many listings, typical prices by type, ₱/sqm, the busiest
 * barangays, the agents who list most there — comes from PriceGuideService::snapshot, and the reply is
 * written here in a fixed shape, so nothing about an area can be invented. Same SSE shape as the other
 * replies; cached in the thread like them.
 */
class AreaCommandService extends CacheService
{
    public function __construct(private readonly PriceGuideService $guide, private readonly PriceCommandService $price)
    {
    }

    use InsightStream;

    public function replyStream(array $thread, $req): StreamedResponse
    {
        $asked = $this->price->extract($thread);
        $place = $this->price->resolvePlace($asked);
        $insight = $place === null ? null : $this->insight($place);

        if ($insight === null) {
            $text = $place === null ? $this->notFoundText($asked) : $this->answerText($place);

            return $this->streamInsight('area', 'area', ['empty' => true], $text, [], $req);
        }

        $spot = $place['barangay'] ?: $place['city'];
        $text = sprintf('Here is **%s** at a glance, from %d live listings on Filipino Homes.', $insight['area']['label'], $insight['totals']['listings']);
        $followUps = [
            ['label' => "Listings in {$spot}", 'prompt' => "Show me properties for sale in {$spot}"],
            ['label' => 'Typical prices by size', 'prompt' => "How much are studios and 2 bedroom condos in {$spot}?"],
            ['label' => 'Agents who work here', 'prompt' => "Find me the best agent for condos in {$spot}"],
        ];
        if ($place['barangay']) {
            $followUps[] = ['label' => "All of {$place['city']}", 'prompt' => "Tell me about {$place['city']} as an area"];
        }

        return $this->streamInsight('area', 'area', $insight, $text, $followUps, $req);
    }

    /**
     * The snapshot as data for the page's panel: totals, each category's types with ranges and
     * tiers, gross yields, the busiest barangays and the most active agents. Null when too thin.
     *
     * @return array<string, mixed>|null
     */
    public function insight(array $place): ?array
    {
        $snap = $this->guide->snapshot($place['city_id'], $place['barangay_id']);
        if ($snap === null) {
            return null;
        }
        $g = $snap['guide'];

        $sections = [];
        foreach ($g['sections'] as $s) {
            $types = [];
            foreach ($s['types'] as $t) {
                $all = $t['tiers'][0] ?? null;
                $types[] = [
                    'type' => $t['type'],
                    'listings' => $t['listings'],
                    'share_pct' => $s['listings'] > 0 ? round($t['listings'] / $s['listings'] * 100) : 0,
                    'low' => $all['low'] ?? null,
                    'median' => $all['median'] ?? null,
                    'high' => $all['high'] ?? null,
                    'median_ppsqm' => $all['median_ppsqm'] ?? null,
                    'tiers' => array_values(array_map(fn (array $x) => array_intersect_key($x, array_flip(['tier', 'listings', 'low', 'median', 'high', 'median_ppsqm'])), array_filter($t['tiers'], fn (array $x) => $x['tier'] !== 'All sizes'))),
                ];
            }
            $sections[] = ['category' => $s['category'], 'listings' => $s['listings'], 'low' => $s['low'], 'median' => $s['median'], 'high' => $s['high'], 'types' => $types];
        }

        $where = $place['barangay'] ? "{$place['barangay']}, {$place['city']}" : "{$place['city']}, {$place['province']}";

        return [
            'area' => ['label' => $where, 'city' => $place['city'], 'barangay' => $place['barangay'], 'province' => $place['province'], 'scope' => $place['barangay'] ? 'barangay' : 'city'],
            'totals' => $g['totals'],
            'sections' => $sections,
            'yields' => $snap['yields'],
            'top_barangays' => array_slice($g['barangays'], 0, 6),
            'agents' => $snap['agents'],
            'computed_at' => $g['computed_at'],
        ];
    }

    /** The snapshot in Markdown. */
    public function answerText(array $place): string
    {
        $snap = $this->guide->snapshot($place['city_id'], $place['barangay_id']);
        $where = $place['barangay'] ? "{$place['barangay']}, {$place['city']}" : "{$place['city']}, {$place['province']}";
        if ($snap === null) {
            return "I don't have enough live listings in **{$where}** yet to describe the area reliably. Try the city as a whole, or a nearby area.";
        }
        $g = $snap['guide'];
        $t = $g['totals'];

        $lines = ["**{$where}** at a glance — from {$t['listings']} live listings on Filipino Homes", ''];

        foreach ($g['sections'] as $s) {
            $rent = $s['category'] === 'For Rent';
            $per = $rent ? '/month' : '';
            $lines[] = sprintf('**%s** — %d listings · typical **%s%s** (%s – %s)', $s['category'], $s['listings'], self::peso($s['median']), $per, self::peso($s['low']), self::peso($s['high']));
            foreach (array_slice($s['types'], 0, 3) as $type) {
                $all = $type['tiers'][0] ?? null; // "All sizes" leads the tiers
                if ($all === null) {
                    continue;
                }
                $sqm = $all['median_ppsqm'] !== null && ! $rent ? sprintf(' · ~%s/sqm', self::peso($all['median_ppsqm'])) : '';
                $lines[] = sprintf('- %s: **%s%s** (%d listings)%s', $type['type'], self::peso($all['median']), $per, $type['listings'], $sqm);
            }
            $lines[] = '';
        }

        $y = $snap['yields'];
        if ($y['types'] !== []) {
            $parts = array_map(fn (array $t) => sprintf('%s **~%s%%** (e.g. %s: buy %s, rent %s/month)', $t['type'], number_format($t['yield_pct'], 1), $t['tier'] === 'All sizes' ? 'typical unit' : strtolower($t['tier']), self::peso($t['sale_median']), self::peso($t['rent_median'])), array_slice($y['types'], 0, 3));
            $lines[] = '**Gross rental yield:** '.implode(' · ', $parts);
            $lines[] = '';
        }
        if ($y['barangays'] !== []) {
            $parts = array_map(fn (array $b) => sprintf('%s ~%s%%', $b['barangay'], number_format($b['yield_pct'], 1)), $y['barangays']);
            $lines[] = '**Best condo yields by barangay:** '.implode(', ', $parts);
            $lines[] = '';
        }

        if ($g['barangays'] !== []) {
            $top = array_map(fn (array $b) => "{$b['barangay']} ({$b['listings']})", array_slice($g['barangays'], 0, 5));
            $lines[] = '**Most listed barangays:** '.implode(', ', $top);
            $lines[] = '';
        }

        if ($snap['agents'] !== []) {
            $top = array_map(fn (array $a) => sprintf('%s (%d)', $a['name'], $a['listings']), $snap['agents']);
            $lines[] = '**Agents most active here:** '.implode(', ', $top);
            $lines[] = '';
        }

        $spot = $place['barangay'] ?: $place['city'];
        $lines[] = "Prices are typical (median) asking prices of live listings; the range is where the middle half sit, and yields are gross (typical rent ×12 over typical price, before costs). Want me to show listings in {$spot}, the typical price for a specific size, or the agents who work there?";

        return implode("\n", $lines);
    }

    private function notFoundText(array $asked): string
    {
        $named = trim(implode(', ', array_filter([$asked['barangay'], $asked['city']])));
        $examples = array_slice(array_map(fn ($p) => $p['city'], array_filter($this->guide->places(), fn ($p) => $p['barangay'] === null)), 0, 4);
        $eg = $examples !== [] ? ' For example: '.implode(', ', $examples).'.' : '';

        return $named !== ''
            ? "I don't have enough live listings in **{$named}** yet to describe it. Which city or barangay would you like instead?{$eg}"
            : "Which area would you like to know about? Name a city or a barangay.{$eg}";
    }

    private static function peso(float|int $v): string
    {
        return PriceCommandService::peso($v);
    }
}
