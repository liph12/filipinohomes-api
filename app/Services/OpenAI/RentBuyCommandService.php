<?php

namespace App\Services\OpenAI;

use App\Services\Listing\PriceGuideService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ASA-GPT's "should I rent or buy in Lahug?" — the area's own rent and sale prices run through a
 * break-even model.
 *
 * The model only EXTRACTS the place, type and size (the Price Guide's extractor); the typical sale
 * price and the typical rent of the SAME kind of unit come from PriceGuideService::pairFor, and the
 * comparison is arithmetic with stated assumptions: a 20% down payment and ~4% closing costs, a 7%
 * 20-year loan, 1% a year to own (tax, dues, upkeep), rents and values rising 3% a year, 7.5% to
 * sell, and the renter's cash earning 4%. The answer is the year buying overtakes renting.
 */
class RentBuyCommandService extends CacheService
{
    use InsightStream;

    private const DOWN_PCT = 20.0;

    private const CLOSING_PCT = 4.0;

    private const RATE_PCT = 7.0;

    private const TERM_YEARS = 20;

    private const OWNING_PCT_PER_YEAR = 1.0;

    private const APPRECIATION_PCT = 3.0;

    private const RENT_GROWTH_PCT = 3.0;

    private const SELLING_COST_PCT = 7.5;

    private const INVEST_RETURN_PCT = 4.0;

    private const HORIZON_YEARS = 20;

    public function __construct(private readonly PriceGuideService $guide, private readonly PriceCommandService $price)
    {
    }

    public function replyStream(array $thread, $req): StreamedResponse
    {
        $asked = $this->price->extract($thread);
        $last = collect($thread)->where('role', 'user')->last();
        $question = is_array($last) ? (string) ($last['content'] ?? '') : '';
        $stay = preg_match('/\b(\d{1,2})\s*(?:years?|yrs?|taon)\b/iu', $question, $m) ? (int) $m[1] : 0;

        if ($asked['city'] === '' && $asked['barangay'] === '') {
            AsaQuestionLog::outcome($req, 'no_data');

            return $this->streamInsight('rentbuy', 'rentbuy', ['empty' => true], "Tell me where — for example **\"Should I rent or buy a 2-bedroom condo in Lahug?\"** — and I'll compare what renting and buying the same kind of unit there would cost you over the years.", [], $req);
        }
        $place = $this->price->resolvePlace(['city' => $asked['city'], 'barangay' => $asked['barangay']]);
        if ($place === null) {
            AsaQuestionLog::outcome($req, 'no_data');
            $where = trim(implode(', ', array_filter([$asked['barangay'], $asked['city']])));

            return $this->streamInsight('rentbuy', 'rentbuy', ['empty' => true], "I don't have enough live listings in **{$where}** yet to compare renting and buying there. Try the city it belongs to, or a nearby area.", [], $req);
        }

        $insight = $this->insight($place, $asked, $stay);
        $spot = $place['barangay'] ?: $place['city'];
        if ($insight === null) {
            AsaQuestionLog::outcome($req, 'no_data', $spot);

            return $this->streamInsight('rentbuy', 'rentbuy', ['empty' => true], "I don't have enough **{$spot}** listings on both the rent and the sale side for the same kind of unit to compare fairly yet. Try the city as a whole.", [], $req);
        }

        $what = strtolower($insight['what']);
        $be = $insight['break_even_year'];
        $text = $be === null
            ? sprintf('In **%s**, renting a %s stays cheaper than buying one for at least %d years at today\'s prices — buying only makes sense here if you value owning for its own sake. The comparison is below.', $insight['scope_label'], $what, self::HORIZON_YEARS)
            : sprintf('In **%s**, buying a %s overtakes renting after about **%d %s**. Stay longer than that and buying wins; shorter and renting is the cheaper choice. The comparison is below.', $insight['scope_label'], $what, $be, $be === 1 ? 'year' : 'years');
        $followUps = array_values(array_filter([
            ['label' => sprintf('What can I afford at %s/mo?', PriceCommandService::peso($insight['rent_median'])), 'prompt' => sprintf('What can I afford at %s a month?', PriceCommandService::peso($insight['rent_median']))],
            ['label' => "{$what}s for sale in {$spot}", 'prompt' => sprintf('Show me %ss for sale in %s under %s', $what, $spot, PriceCommandService::peso($insight['sale_median'] * 1.1))],
            ['label' => "{$what}s for rent in {$spot}", 'prompt' => sprintf('Show me %ss for rent in %s under %s', $what, $spot, PriceCommandService::peso($insight['rent_median'] * 1.1))],
            ['label' => 'Total cost of buying one', 'prompt' => sprintf('What will it really cost to buy a %s %s?', PriceCommandService::peso($insight['sale_median']), $what)],
        ]));
        AsaQuestionLog::outcome($req, 'answered', $spot);

        return $this->streamInsight('rentbuy', 'rentbuy', $insight, $text, $followUps, $req);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function insight(array $place, array $asked, int $stay = 0): ?array
    {
        $type = match ($asked['property_type']) {
            'House', 'Apartment' => 'House',
            'Lot' => 'Land',
            'Commercial' => 'Commercial',
            default => 'Condominium',
        };
        $tier = $asked['tier'] !== '' ? $asked['tier'] : null;
        $pair = $this->guide->pairFor($place['city_id'], $place['barangay_id'], $type, $tier);
        if ($pair === null) {
            return null;
        }
        $P = (float) $pair['sale']['median'];
        $R = (float) $pair['rent']['median'];

        $down = $P * self::DOWN_PCT / 100;
        $closing = $P * self::CLOSING_PCT / 100;
        $upfront = $down + $closing;
        $loan = $P - $down;
        $monthly = CostCommandService::paymentFor($loan, self::RATE_PCT, self::TERM_YEARS);
        $owningPerMonth = $P * self::OWNING_PCT_PER_YEAR / 100 / 12;

        // Year by year: what each path has cost in total if you walked away at the end of that year.
        $rows = [];
        $breakEven = null;
        $rentCum = 0.0;
        $payCum = 0.0;
        $ownCum = 0.0;
        $i = self::RATE_PCT / 100 / 12;
        for ($y = 1; $y <= self::HORIZON_YEARS; $y++) {
            $rentCum += $R * 12 * (1 + self::RENT_GROWTH_PCT / 100) ** ($y - 1);
            $invested = $upfront * ((1 + self::INVEST_RETURN_PCT / 100) ** $y - 1);
            $rentNet = $rentCum - $invested;

            $months = min($y, self::TERM_YEARS) * 12;
            $payCum = $monthly * $months;
            $ownCum += $P * self::OWNING_PCT_PER_YEAR / 100 * (1 + self::APPRECIATION_PCT / 100) ** ($y - 1);
            $balance = $y >= self::TERM_YEARS ? 0.0 : $loan * (1 + $i) ** $months - $monthly * ((1 + $i) ** $months - 1) / $i;
            $value = $P * (1 + self::APPRECIATION_PCT / 100) ** $y;
            $equity = $value * (1 - self::SELLING_COST_PCT / 100) - $balance;
            $buyNet = $upfront + $payCum + $ownCum - $equity;

            if ($breakEven === null && $buyNet <= $rentNet) {
                $breakEven = $y;
            }
            $rows[] = ['year' => $y, 'rent' => round($rentNet), 'buy' => round($buyNet), 'diff' => round($rentNet - $buyNet)];
        }
        $show = [1, 2, 3, 5, 7, 10, 15, 20];
        if ($stay > 0 && $stay <= self::HORIZON_YEARS && ! in_array($stay, $show, true)) {
            $show[] = $stay;
            sort($show);
        }
        if ($breakEven !== null && ! in_array($breakEven, $show, true)) {
            $show[] = $breakEven;
            sort($show);
        }
        $table = array_values(array_filter($rows, fn (array $r) => in_array($r['year'], $show, true)));

        $ratio = $P / ($R * 12);
        $ratioLabel = $ratio < 15 ? 'buy-leaning' : ($ratio <= 20 ? 'balanced' : 'rent-leaning');
        $stayVerdict = null;
        if ($stay > 0 && $stay <= self::HORIZON_YEARS) {
            $row = $rows[$stay - 1];
            $stayVerdict = ['years' => $stay, 'rent' => $row['rent'], 'buy' => $row['buy'], 'winner' => $row['buy'] <= $row['rent'] ? 'buy' : 'rent', 'saving' => abs($row['diff'])];
        }
        $scopeLabel = $pair['scope'] === 'barangay' ? "{$place['barangay']}, {$place['city']}" : $place['city'];

        return [
            'what' => trim(($pair['tier'] !== 'All sizes' ? $pair['tier'].' ' : '').$type),
            'type' => $type,
            'tier' => $pair['tier'],
            'place' => ['city' => $place['city'], 'barangay' => $place['barangay'], 'province' => $place['province']],
            'scope' => $pair['scope'],
            'scope_label' => $scopeLabel,
            'sale_median' => round($P),
            'sale_low' => $pair['sale']['low'],
            'sale_high' => $pair['sale']['high'],
            'sale_listings' => $pair['sale']['listings'],
            'rent_median' => round($R),
            'rent_low' => $pair['rent']['low'],
            'rent_high' => $pair['rent']['high'],
            'rent_listings' => $pair['rent']['listings'],
            'price_to_rent' => round($ratio, 1),
            'price_to_rent_label' => $ratioLabel,
            'gross_yield_pct' => round($R * 12 / $P * 100, 1),
            'mortgage_monthly' => round($monthly),
            'owning_monthly' => round($owningPerMonth),
            'buy_monthly_total' => round($monthly + $owningPerMonth),
            'upfront' => round($upfront),
            'down_payment' => round($down),
            'closing_costs' => round($closing),
            'break_even_year' => $breakEven,
            'horizon_years' => self::HORIZON_YEARS,
            'table' => $table,
            'stay' => $stayVerdict,
            'assumptions' => [
                'down_pct' => self::DOWN_PCT,
                'closing_pct' => self::CLOSING_PCT,
                'rate_pct' => self::RATE_PCT,
                'term_years' => self::TERM_YEARS,
                'owning_pct_per_year' => self::OWNING_PCT_PER_YEAR,
                'appreciation_pct' => self::APPRECIATION_PCT,
                'rent_growth_pct' => self::RENT_GROWTH_PCT,
                'selling_cost_pct' => self::SELLING_COST_PCT,
                'invest_return_pct' => self::INVEST_RETURN_PCT,
            ],
        ];
    }
}
