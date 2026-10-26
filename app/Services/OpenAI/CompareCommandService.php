<?php

namespace App\Services\OpenAI;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ASA-GPT's "compare these": two or three listings side by side.
 *
 * Reached deterministically — the compare tray sends "Compare listing #12 and listing #34" and the
 * controller routes on the ids. Each listing comes as the card the chat already draws (with its
 * market context), and the winners per criterion are decided here by arithmetic, never by the
 * model: cheaper, better ₱/sqm, bigger, more rooms, higher yield, closer to its typical price, more
 * interest, fresher on the market. The page draws it as a comparison panel.
 */
class CompareCommandService extends CacheService
{
    use InsightStream;

    public function __construct(private readonly DataLayerService $data)
    {
    }

    /** @param array<int, int> $ids */
    public function replyStream(array $ids, array $thread, $req): StreamedResponse
    {
        $insight = $this->insight($ids);
        if ($insight === null) {
            AsaQuestionLog::outcome($req, 'no_data');
            return $this->streamInsight('compare', 'compare', ['empty' => true], "I could only find one of those listings — the other may have been taken down. Pick another card to compare with.", [], $req);
        }

        $names = $insight['labels'];
        $text = sprintf('**%s** side by side. %s', implode('** vs **', $names), $insight['summary']);
        $first = $insight['listings'][0];
        $spot = $first['priceContext']['barangay'] ?? ($first['priceContext']['city'] ?? null);
        $followUps = array_values(array_filter([
            ['label' => 'Is the first one a good deal?', 'prompt' => "Is this a good deal? listing #{$first['id']}"],
            $spot ? ['label' => "More in {$spot}", 'prompt' => "Show me {$first['propertyType']}s for sale in {$spot}"] : null,
            $spot ? ['label' => "About {$spot}", 'prompt' => "Tell me about {$spot} as an area"] : null,
        ]));

        return $this->streamInsight('compare', 'compare', $insight, $text, $followUps, $req);
    }

    /**
     * @param array<int, int> $ids
     * @return array<string, mixed>|null
     */
    public function insight(array $ids): ?array
    {
        $cards = [];
        foreach (array_slice(array_values(array_unique($ids)), 0, 3) as $id) {
            $card = $this->data->cardFor((int) $id);
            if ($card !== null) {
                $cards[] = $card;
            }
        }
        if (count($cards) < 2) {
            return null;
        }

        // Two units in the same building share a name; tell them apart by size, then floor area, then id.
        $base = array_map(fn (array $c) => $c['propertyName'] ?: $c['name'], $cards);
        $labels = $base;
        $dups = fn (array $ls) => count(array_unique($ls)) < count($ls);
        if ($dups($labels)) {
            $labels = array_map(function (array $c, string $b) use ($base) {
                $tier = $c['priceContext']['tier'] ?? null;

                return count(array_keys($base, $b, true)) > 1 && $tier && $tier !== 'All sizes' ? "{$b} · {$tier}" : $b;
            }, $cards, $base);
        }
        if ($dups($labels)) {
            $labels = array_map(function (array $c, string $l) use ($labels) {
                $area = (float) ($c['propertyAttributes']['floorArea'] ?? 0);

                return count(array_keys($labels, $l, true)) > 1 && $area > 0 ? $l.' · '.rtrim(rtrim(number_format($area, 1), '0'), '.').' sqm' : $l;
            }, $cards, $labels);
        }
        if ($dups($labels)) {
            $labels = array_map(fn (array $c, string $l) => count(array_keys($labels, $l, true)) > 1 ? "{$l} (#{$c['id']})" : $l, $cards, $labels);
        }

        // Per criterion: which listing wins (index), with the value shown for each.
        $criteria = [];
        $add = function (string $key, string $label, array $values, bool $higherWins, callable $fmt) use (&$criteria, $cards) {
            $valid = array_filter($values, fn ($v) => $v !== null);
            if (count($valid) < 2) {
                return;
            }
            $best = $higherWins ? max($valid) : min($valid);
            $winners = array_keys(array_filter($values, fn ($v) => $v !== null && abs($v - $best) < 1e-9));
            $criteria[] = [
                'key' => $key,
                'label' => $label,
                'values' => array_map(fn ($v) => $v === null ? null : $fmt($v), $values),
                'raw' => $values,
                'winner' => count($winners) === 1 ? $winners[0] : null,
            ];
        };
        $peso = fn ($v) => PriceCommandService::peso((float) $v);
        $rent = ($cards[0]['category'] ?? '') === 'For Rent';
        $add('price', $rent ? 'Monthly rent' : 'Asking price', array_map(fn ($c) => (float) $c['price'] ?: null, $cards), false, $peso);
        $add('ppsqm', 'Price per sqm', array_map(fn ($c) => $c['priceContext']['ppsqm'] ?? null, $cards), false, $peso);
        $add('diff', 'Against typical', array_map(fn ($c) => $c['priceContext']['diff_pct'] ?? null, $cards), false, fn ($v) => ($v > 0 ? '+' : '').$v.'%');
        $add('floor', 'Floor area', array_map(fn ($c) => ($c['propertyAttributes']['floorArea'] ?? 0) ?: null, $cards), true, fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.').' sqm');
        $add('beds', 'Bedrooms', array_map(fn ($c) => ($c['propertyAttributes']['beds'] ?? 0) ?: null, $cards), true, fn ($v) => (string) (int) $v);
        $add('baths', 'Bathrooms', array_map(fn ($c) => ($c['propertyAttributes']['baths'] ?? 0) ?: null, $cards), true, fn ($v) => (string) (int) $v);
        $add('yield', 'Gross yield', array_map(fn ($c) => $c['priceContext']['yield_pct'] ?? null, $cards), true, fn ($v) => number_format((float) $v, 1).'%');
        $add('interest', 'Views per day', array_map(fn ($c) => $c['priceContext']['demand']['views_per_day'] ?? null, $cards), true, fn ($v) => number_format((float) $v, 2));
        $add('days', 'Days on market', array_map(fn ($c) => $c['priceContext']['demand']['days_listed'] ?? null, $cards), false, fn ($v) => (string) (int) $v);

        // Tally wins into a one-line summary.
        $wins = array_fill(0, count($cards), []);
        foreach ($criteria as $c) {
            if ($c['winner'] !== null) {
                $wins[$c['winner']][] = strtolower($c['label']);
            }
        }
        $parts = [];
        foreach ($cards as $i => $card) {
            if ($wins[$i] !== []) {
                $parts[] = sprintf('**%s** wins on %s', $labels[$i], self::joinNice($wins[$i]));
            }
        }
        $summary = $parts === [] ? 'They are close on every measure we track.' : implode('; ', $parts).'.';

        return ['listings' => $cards, 'labels' => $labels, 'criteria' => $criteria, 'summary' => $summary];
    }

    private static function joinNice(array $items): string
    {
        if (count($items) <= 1) {
            return implode('', $items);
        }
        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
