<?php

namespace App\Services\OpenAI;

use App\Models\Listing;
use App\Services\Listing\PriceGuideService;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ASA-GPT's "is this a good deal?" — the button on every listing card.
 *
 * Reached deterministically: the card sends "Is this a good deal? listing #123" and the controller
 * routes on the "#123", so no classifier and no model are involved. The answer is arithmetic over our
 * own live listings (PriceGuideService::contextForListing: the median asking price of the listing's
 * peers, where this price sits among them, price per sqm, time on market) written here in a fixed
 * shape, so the verdict can never be invented. Streams the same SSE shape as the other replies and is
 * cached in the thread like them.
 */
class DealCommandService extends CacheService
{
    public function __construct(private readonly PriceGuideService $guide)
    {
    }

    use InsightStream;

    public function replyStream(int $listingId, array $thread, $req): StreamedResponse
    {
        $insight = $this->insight($listingId);
        if ($insight === null) {
            AsaQuestionLog::outcome($req, 'no_data');
            return $this->streamInsight('deal', 'deal', ['empty' => true], $this->answerText($listingId), [], $req);
        }

        $what = strtolower($insight['what']);
        $spot = $insight['context']['barangay'] ?: $insight['context']['city'];
        $verdictLine = match ($insight['verdict']) {
            'below' => 'priced **below the market** for its size and area',
            'above' => 'priced **above the typical** asking price for its size and area',
            default => 'priced **in line with the market** for its size and area',
        };
        $text = sprintf('**%s** is %s. The full comparison is below.', $insight['name'], $verdictLine);
        $followUps = [
            ['label' => 'Where is it?', 'prompt' => "Where is this? listing #{$listingId}"],
            ['label' => 'Total cost to buy', 'prompt' => "What will it really cost to buy listing #{$listingId}?"],
            ['label' => 'As an investment', 'prompt' => "Is this a good investment? listing #{$listingId}"],
            ['label' => "Cheaper {$what}s in {$spot}", 'prompt' => "Show me {$what}s for sale in {$spot} under ".PriceCommandService::peso($insight['context']['median'])],
            ['label' => 'Similar ones nearby', 'prompt' => "Show me {$what}s for sale near {$spot}"],
            ['label' => "About {$spot}", 'prompt' => "Tell me about {$spot} as an area"],
        ];

        return $this->streamInsight('deal', 'deal', $insight, $text, $followUps, $req);
    }

    /**
     * The verdict as data for the page's panel. Null when the listing is gone or has no comparables.
     *
     * @return array<string, mixed>|null
     */
    public function insight(int $listingId): ?array
    {
        $listing = Listing::publiclyListed()->with('property')->find($listingId);
        if ($listing === null) {
            return null;
        }
        $c = $this->guide->contextForListing($listingId);
        if ($c === null) {
            return null;
        }
        $name = trim((string) ($listing->property->name ?? $listing->name)) ?: $listing->name;
        $what = trim(($c['tier'] !== 'All sizes' ? $c['tier'].' ' : '').$c['type']);
        $days = (int) Carbon::parse($c['listed_at'])->diffInDays(now());
        $d = $c['demand'] ?? null;
        $quietOrOld = $d !== null && ($d['label'] === 'quiet' || $d['days_listed'] >= 60);

        $offer = null;
        if ($c['verdict'] === 'above' && $c['low'] < $c['price']) {
            $offer = ['low' => $c['low'], 'high' => min($c['median'], $c['price'] * 0.95), 'reason' => $quietOrOld ? 'The time on market and the interest level both say the seller has room.' : 'Comparable units ask that much.'];
        } elseif ($c['verdict'] === 'near' && $quietOrOld && $c['low'] < $c['price']) {
            $offer = ['low' => $c['low'], 'high' => $c['price'] * 0.97, 'reason' => 'Fairly priced, but it has sat a while, so a little below asking is reasonable.'];
        }

        return [
            'listing_id' => $listingId,
            'name' => $name,
            'slug' => $listing->slug,
            'what' => $what,
            'verdict' => $c['verdict'],
            'verdict_text' => match ($c['verdict']) {
                'below' => 'Priced below the market for its size and area — worth a closer look, and worth asking why.',
                'above' => 'Priced above the typical asking price for its size and area — compare with the cheaper ones or use this as room to negotiate.',
                default => 'Priced in line with the market for its size and area — a fair asking price; the decision comes down to the unit itself.',
            },
            'context' => array_intersect_key($c, array_flip(['scope', 'area', 'city', 'barangay', 'category', 'type', 'tier', 'listings', 'low', 'median', 'high', 'median_ppsqm', 'price', 'ppsqm', 'diff_pct', 'cheaper', 'pricier', 'rent_median', 'yield_pct', 'area_yield_pct'])),
            'listed_at' => Carbon::parse($c['listed_at'])->toDateString(),
            'days_listed' => $days,
            'demand' => $d,
            'offer' => $offer === null ? null : ['low' => round($offer['low']), 'high' => round($offer['high']), 'reason' => $offer['reason']],
            'timing' => $c['verdict'] === 'below' ? 'Already under the typical price — if it checks out in person, this is one to move on quickly.' : null,
        ];
    }

    /** The verdict in Markdown, or a plain reason we cannot give one. */
    public function answerText(int $listingId): string
    {
        $listing = Listing::publiclyListed()->with('property')->find($listingId);
        if ($listing === null) {
            return "I can't find that listing any more — it may have been taken down. Want me to look for similar ones?";
        }
        $name = trim((string) ($listing->property->name ?? $listing->name)) ?: $listing->name;

        $c = $this->guide->contextForListing($listingId);
        if ($c === null) {
            return "**{$name}** — I don't have enough comparable live listings in its area yet to judge the price fairly. I can show you what else is listed nearby so you can compare by hand.";
        }

        $rent = $c['category'] === 'For Rent';
        $per = $rent ? '/month' : '';
        $what = trim(($c['tier'] !== 'All sizes' ? $c['tier'].' ' : '').$c['type']);
        $peers = $c['listings'] - 1; // the listing itself is one of them
        $lines = ["**Is this a good deal?** {$name}", ''];

        $diff = $c['diff_pct'];
        $vs = $diff === null ? '' : ($diff === 0 ? ' — right at the typical price' : sprintf(' — **%d%% %s** typical', abs($diff), $diff < 0 ? 'below' : 'above'));
        $lines[] = sprintf('- Asking **%s%s**, against a typical **%s%s** for a %s in %s%s.', self::peso($c['price']), $per, self::peso($c['median']), $per, $what, $c['area'], $vs);
        $lines[] = sprintf('- The middle half of those %d listings sit between %s and %s.', $peers, self::peso($c['low']), self::peso($c['high']));
        if ($c['ppsqm'] !== null && $c['median_ppsqm'] !== null) {
            $lines[] = sprintf('- Per square metre: **%s** here vs **%s** typical.', self::peso($c['ppsqm']), self::peso($c['median_ppsqm']));
        }
        $lines[] = sprintf('- %d comparable %s cheaper and %d pricier.', $c['cheaper'], $c['cheaper'] === 1 ? 'listing is' : 'listings are', $c['pricier']);
        $days = (int) Carbon::parse($c['listed_at'])->diffInDays(now());
        $lines[] = sprintf('- Listed %s (%s).', Carbon::parse($c['listed_at'])->format('M j, Y'), $days < 1 ? 'today' : ($days === 1 ? 'yesterday' : "{$days} days ago"));

        if ($c['yield_pct'] !== null) {
            $lines[] = sprintf('- Gross rental yield: **~%s%%** if rented at the typical **%s/month** for a %s here (the area as a whole yields ~%s%%).', number_format($c['yield_pct'], 1), self::peso($c['rent_median']), strtolower($what), number_format($c['area_yield_pct'], 1));
        }

        $d = $c['demand'] ?? null;
        if ($d !== null && $d['views_per_day'] !== null && $d['peer_views_per_day'] !== null) {
            $share = (int) round($d['ratio'] * 100);
            $interest = match ($d['label']) {
                'hot' => sprintf('**Hot** — about %sx the views per day of similar listings', number_format($d['ratio'], 1)),
                'quiet' => sprintf('**Quiet** — %s of the views per day similar listings get, after %d days on the market', $share < 5 ? 'almost none' : "about {$share}%", $d['days_listed']),
                default => 'Steady — about the same interest as similar listings',
            };
            $extras = array_filter([
                $d['favorites'] > 0 ? sprintf('%d %s saved it', $d['favorites'], $d['favorites'] === 1 ? 'person' : 'people') : null,
                $d['inquiries_30d'] > 0 ? sprintf('%d %s in the last 30 days', $d['inquiries_30d'], $d['inquiries_30d'] === 1 ? 'inquiry' : 'inquiries') : null,
                $d['views_30d'] > 0 ? sprintf('%s views in the last 30 days', number_format($d['views_30d'])) : null,
            ]);
            $lines[] = '- Interest: '.$interest.($extras !== [] ? '; '.implode(', ', $extras) : '').'.';
        }
        $lines[] = '';

        $verdict = match ($c['verdict']) {
            'below' => 'Priced **below the market** for its size and area — worth a closer look, and worth asking why.',
            'above' => 'Priced **above the typical** asking price for its size and area — compare with the cheaper ones or use this as room to negotiate.',
            default => 'Priced **in line with the market** for its size and area — a fair asking price; the decision comes down to the unit itself.',
        };
        $lines[] = "**Verdict:** {$verdict}";

        // An opening offer, when the numbers leave room: the lower-middle of comparable asking prices.
        $quietOrOld = $d !== null && ($d['label'] === 'quiet' || $d['days_listed'] >= 60);
        if ($c['verdict'] === 'above' && $c['low'] < $c['price']) {
            $top = min($c['median'], $c['price'] * 0.95);
            $lines[] = sprintf('**Opening offer worth trying:** %s – %s%s.', self::peso($c['low']), self::peso($top), $quietOrOld ? ' — the time on market and the interest level both say the seller has room' : ' — comparable units ask that much');
        } elseif ($c['verdict'] === 'near' && $quietOrOld && $c['low'] < $c['price']) {
            $lines[] = sprintf('**Opening offer worth trying:** %s – %s — it is fairly priced, but it has sat a while, so a little below asking is reasonable.', self::peso($c['low']), self::peso($c['price'] * 0.97));
        } elseif ($c['verdict'] === 'below') {
            $lines[] = '**Timing:** already under the typical price — if it checks out in person, this is one to move on quickly.';
        }
        $lines[] = '';
        $lines[] = 'These are asking prices of live listings on Filipino Homes, not a valuation. Want me to show the cheaper '.strtolower($what).'s in '.($c['barangay'] ?: $c['city']).', or similar ones nearby?';

        return implode("\n", $lines);
    }

    private static function peso(float|int $v): string
    {
        return PriceCommandService::peso($v);
    }
}
