<?php

namespace App\Services\OpenAI;

use App\Models\Listing;
use App\Services\Listing\PriceGuideService;
use OpenAI;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ASA-GPT's "is this a good investment?" — a listing (or a typical unit in an area) as a rental,
 * by arithmetic.
 *
 * The rent is never invented: it is what the buyer says, else the typical rent of the listing's own
 * peers (same type and unit size in its barangay or city, from PriceGuideService), else nothing and
 * the answer says so. From price and rent: gross and net yield against the area, the mortgage at the
 * usual rates, the monthly cash flow at 20 / 30 / 50 percent down, the year rents outgrow the payment,
 * and the sale price needed to break even after 3, 5 and 10 years. Assumptions are stated on the panel.
 */
class InvestCommandService extends CacheService
{
    use InsightStream;

    private const DEFAULT_DOWN_PCT = 20.0;

    private const DEFAULT_TERM_YEARS = 20;

    private const DEFAULT_RATE_PCT = 6.5;

    private const CLOSING_PCT = 4.0;

    /** Tax, dues and upkeep, per year, as a share of the price. */
    private const OWNING_PCT_PER_YEAR = 1.0;

    /** Months a year the unit sits empty between tenants. */
    private const VACANCY_MONTHS = 1.0;

    private const RENT_GROWTH_PCT = 3.0;

    private const APPRECIATION_PCT = 3.0;

    private const SELLING_COST_PCT = 7.5;

    private $client;

    public function __construct(private readonly PriceGuideService $guide, private readonly PriceCommandService $price, private readonly DataLayerService $data)
    {
        $this->client = OpenAI::client(config('services.openai.key'));
    }

    /** From a card button: "Is this a good investment? listing #123". */
    public function replyStreamForListing(int $listingId, array $thread, $req): StreamedResponse
    {
        $asked = $this->extract($thread);
        $asked['listing_id'] = $listingId;

        return $this->reply($asked, $req);
    }

    /** From a free question: a listing it names, a price and rent, or a typical unit in a place. */
    public function replyStream(array $thread, $req): StreamedResponse
    {
        return $this->reply($this->extract($thread), $req);
    }

    private function reply(array $asked, $req): StreamedResponse
    {
        $insight = null;
        $spot = null;
        if ($asked['listing_id'] > 0) {
            $insight = $this->insightForListing($asked['listing_id'], $asked);
            if ($insight === null) {
                AsaQuestionLog::outcome($req, 'no_data');
                $listing = Listing::publiclyListed()->with('property')->find($asked['listing_id']);
                if ($listing === null || (float) $listing->price <= 0) {
                    return $this->streamInsight('invest', 'invest', ['empty' => true], "I can't find that listing any more — it may have been taken down. Pick another card, or tell me a price and the rent you expect.", [], $req);
                }
                $name = trim((string) ($listing->property->name ?? $listing->name)) ?: $listing->name;
                if ($this->guide->contextForListing($asked['listing_id']) === null) {
                    return $this->streamInsight('invest', 'invest', ['empty' => true], "**{$name}** is not in ASA's market data — its price looks like a placeholder, it is not for sale, or it has no comparable live listings — so I can't judge it as an investment. Ask the agent for the real price and the rent it gets, then tell me both.", [], $req);
                }
                // No rentals of its kind anywhere in its city: a rule-of-thumb rent (0.5% of the
                // price a month) as the figure to correct, since a guess must never pose as data.
                $guess = round((float) $listing->price * 0.005, -2);

                return $this->streamInsight('invest', 'invest', ['empty' => true], sprintf("There are no **%ss for rent** in its city on Filipino Homes, so I can't tell what **%s** would earn without inventing a number. Tell me the rent you expect — for example **\"Is this a good investment if it rents for %s a month? listing #%d\"** — and I'll run the full numbers.", strtolower((string) ($listing->property?->propertyAttribute?->subtype?->type?->name ?? 'unit')), $name, PriceCommandService::peso($guess), $asked['listing_id']), [], $req);
            }
        } else {
            $place = ($asked['city'] !== '' || $asked['barangay'] !== '') ? $this->price->resolvePlace(['city' => $asked['city'], 'barangay' => $asked['barangay']]) : null;
            if ($asked['price'] > 0 && $asked['rent'] > 0) {
                $type = self::dbType($asked['property_type']);
                $scope = $place === null
                    ? ['place' => null, 'scope' => null, 'tier' => $asked['tier'] ?: null, 'type' => $type, 'area_label' => null, 'area_yield_pct' => null]
                    : ['place' => ['city' => $place['city'], 'barangay' => $place['barangay'], 'province' => $place['province']], 'scope' => $place['barangay'] ? 'barangay' : 'city', 'tier' => $asked['tier'] ?: null, 'type' => $type, 'area_label' => $place['barangay'] ? "{$place['barangay']}, {$place['city']}" : $place['city'], 'area_yield_pct' => null];
                $insight = $this->insight($asked['price'], $asked['rent'], 'you', null, $asked, $scope);
                $insight['name'] = sprintf('A %s%s', strtolower(str_replace('Condominium', 'condo', $insight['what'])), $scope['area_label'] ? " in {$scope['area_label']}" : '');
            } elseif ($place !== null) {
                $insight = $this->insightForArea($place, $asked);
            }
            if ($insight === null) {
                AsaQuestionLog::outcome($req, 'no_data', $place['barangay'] ?? null ?: ($place['city'] ?? null));
                if ($place !== null) {
                    return $this->streamInsight('invest', 'invest', ['empty' => true], $this->thinAreaText($place, $asked), [], $req);
                }

                return $this->streamInsight('invest', 'invest', ['empty' => true], "Tell me the property and where it is — **\"Is a 1-bedroom condo in Lahug a good investment?\"** — or a price and the rent you expect — **\"a ₱4M condo that rents for ₱22,000\"** — and I'll work out the yield, the cash flow and the break-even.", [], $req);
            }
            $spot = $place['barangay'] ?? null ?: ($place['city'] ?? null);
        }
        $spot = $spot ?: ($insight['place']['barangay'] ?? null) ?: ($insight['place']['city'] ?? null);

        $cf = $insight['cash_flow_monthly'];
        $text = sprintf(
            '%s at **%s** would rent for about **%s/month**%s: a gross yield of **%s%%**%s. At %s down it %s. The full working is below.',
            $insight['name'] ? "**{$insight['name']}**" : 'This unit',
            PriceCommandService::peso($insight['price']),
            PriceCommandService::peso($insight['rent']),
            $insight['rent_source'] === 'you' ? ' (your figure)' : ($insight['rent_source'] === 'peers' ? sprintf(' (what %d similar rentals ask)', $insight['rent_peers']) : ' (typical for the area)'),
            number_format($insight['gross_yield_pct'], 1),
            $insight['area_yield_pct'] !== null ? sprintf(' against **%s%%** for the area', number_format($insight['area_yield_pct'], 1)) : '',
            rtrim(rtrim(number_format($insight['down_pct'], 1), '0'), '.').'%',
            $cf >= 0 ? sprintf('**pays for itself** with about %s/month to spare', PriceCommandService::peso($cf)) : sprintf('needs about **%s/month on top** of the rent', PriceCommandService::peso(abs($cf))),
        );
        $ref = $asked['listing_id'] > 0 ? "listing #{$asked['listing_id']}" : null;
        $what = strtolower($insight['what']);
        $followUps = array_values(array_filter([
            $insight['down_pct'] < 30 ? ['label' => 'With 30% down', 'prompt' => $ref ? "Is this a good investment with 30% down? {$ref}" : sprintf('Is a %s %s in %s a good investment with 30%% down?', PriceCommandService::peso($insight['price']), $what, $spot ?? 'the area')] : null,
            ['label' => sprintf('If it rents for %s', PriceCommandService::peso(round($insight['rent'] * 1.15, -2))), 'prompt' => $ref ? sprintf('Is this a good investment if it rents for %s a month? %s', PriceCommandService::peso(round($insight['rent'] * 1.15, -2)), $ref) : sprintf('Is a %s %s a good investment if it rents for %s a month?', PriceCommandService::peso($insight['price']), $what, PriceCommandService::peso(round($insight['rent'] * 1.15, -2)))],
            $spot ? ['label' => "Where {$what}s yield most", 'prompt' => sprintf('Where do %ss yield the most in %s?', $what, $insight['place']['city'] ?? $spot)] : null,
            $ref ? ['label' => 'Is it a good deal?', 'prompt' => "Is this a good deal? {$ref}"] : ($spot ? ['label' => "{$what}s for sale in {$spot}", 'prompt' => sprintf('Show me %ss for sale in %s under %s', $what, $spot, PriceCommandService::peso($insight['price'] * 1.1))] : null),
        ]));
        AsaQuestionLog::outcome($req, 'answered', $spot);

        return $this->streamInsight('invest', 'invest', $insight, $text, $followUps, $req);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function insightForListing(int $listingId, array $asked): ?array
    {
        $listing = Listing::publiclyListed()->with('property')->find($listingId);
        if ($listing === null) {
            return null;
        }
        $c = $this->guide->contextForListing($listingId);
        if ($c === null || $c['category'] !== 'For Sale') {
            return null;
        }
        $rent = $asked['rent'] > 0 ? $asked['rent'] : (float) ($c['rent_median'] ?? 0);
        $source = $asked['rent'] > 0 ? 'you' : 'peers';
        $rentPeers = null;
        if ($source === 'peers' && $rent <= 0) {
            // No rentals among its direct peers: the area's typical rent for the same kind of unit,
            // else the rent the area's yield for this type implies at this price.
            $pair = $this->guide->pairFor((int) ($c['city_id'] ?? 0), $c['barangay_id'] ?? null, $c['type'], $c['tier'] !== 'All sizes' ? $c['tier'] : null);
            if ($pair !== null) {
                $rent = (float) $pair['rent']['median'];
                $rentPeers = (int) $pair['rent']['listings'];
                $source = 'area';
            } else {
                $yields = $this->guide->yields((int) ($c['city_id'] ?? 0), null);
                foreach ($yields['types'] as $t) {
                    if ($t['type'] === $c['type'] && $t['yield_pct'] > 0) {
                        $rent = (float) $c['price'] * $t['yield_pct'] / 100 / 12;
                        $rentPeers = (int) ($t['rent_listings'] ?? 0);
                        $source = 'area';
                        break;
                    }
                }
            }
        }
        if ($rent <= 0) {
            return null;
        }
        $name = trim((string) ($listing->property->name ?? $listing->name)) ?: $listing->name;
        $scope = ['place' => ['city' => $c['city'], 'barangay' => $c['barangay'], 'province' => null], 'scope' => $c['scope'], 'tier' => $c['tier'] !== 'All sizes' ? $c['tier'] : null, 'type' => $c['type'], 'area_label' => $c['area'], 'area_yield_pct' => $c['area_yield_pct'] ?? null];
        $out = $this->insight((float) $c['price'], $rent, $source, $rentPeers, $asked, $scope);
        $out['listing_id'] = $listingId;
        $out['name'] = $name;
        $out['listing'] = $this->data->cardFor($listingId);
        $out['price_vs_typical_pct'] = $c['diff_pct'];
        $out['deal_verdict'] = $c['verdict'];

        return $out;
    }

    /**
     * A typical unit of the asked kind in the place, from the area's own sale and rent medians.
     *
     * @return array<string, mixed>|null
     */
    public function insightForArea(array $place, array $asked): ?array
    {
        $type = self::dbType($asked['property_type']);
        $tier = $asked['tier'] !== '' ? $asked['tier'] : null;
        $pair = $this->guide->pairFor($place['city_id'], $place['barangay_id'], $type, $tier);
        if ($pair === null) {
            return null;
        }
        $price = $asked['price'] > 0 ? $asked['price'] : (float) $pair['sale']['median'];
        $rent = $asked['rent'] > 0 ? $asked['rent'] : (float) $pair['rent']['median'];
        $scope = ['place' => ['city' => $place['city'], 'barangay' => $place['barangay'], 'province' => $place['province']], 'scope' => $pair['scope'], 'tier' => $pair['tier'] !== 'All sizes' ? $pair['tier'] : null, 'type' => $type, 'area_label' => $pair['scope'] === 'barangay' ? "{$place['barangay']}, {$place['city']}" : $place['city'], 'area_yield_pct' => null];
        $out = $this->insight($price, $rent, $asked['rent'] > 0 ? 'you' : 'area', (int) $pair['rent']['listings'], $asked, $scope);
        $out['name'] = sprintf('A typical %s in %s', $out['what'], $scope['area_label']);
        $out['sale_listings'] = (int) $pair['sale']['listings'];

        return $out;
    }

    /**
     * The numbers, from a price and a monthly rent.
     *
     * @return array<string, mixed>
     */
    public function insight(float $price, float $rent, string $rentSource, ?int $rentPeers, array $asked, ?array $scope): array
    {
        $downPct = $asked['down_pct'] > 0 ? min(90.0, $asked['down_pct']) : self::DEFAULT_DOWN_PCT;
        $years = $asked['term_years'] > 0 ? min(30, (int) $asked['term_years']) : self::DEFAULT_TERM_YEARS;
        $rate = $asked['rate_pct'] > 0 ? min(20.0, $asked['rate_pct']) : self::DEFAULT_RATE_PCT;

        $owningMonthly = $price * self::OWNING_PCT_PER_YEAR / 100 / 12;
        $netRentMonthly = $rent * (12 - self::VACANCY_MONTHS) / 12 - $owningMonthly;
        $grossYield = $rent * 12 / $price * 100;
        $netYield = $netRentMonthly * 12 / $price * 100;

        $areaYield = $scope['area_yield_pct'] ?? null;
        if ($areaYield === null && $scope !== null && isset($scope['place']['city'])) {
            $areaYield = $this->areaYield($scope);
        }

        $calc = function (float $dp, float $r) use ($price, $years, $netRentMonthly): array {
            $down = $price * $dp / 100;
            $closing = $price * self::CLOSING_PCT / 100;
            $loan = $price - $down;
            $mortgage = $loan > 0 ? CostCommandService::paymentFor($loan, $r, $years) : 0.0;
            $cf = $netRentMonthly - $mortgage;
            $invested = $down + $closing;

            return ['down_pct' => $dp, 'down_payment' => round($down), 'closing_costs' => round($closing), 'cash_invested' => round($invested), 'loan' => round($loan), 'mortgage_monthly' => round($mortgage), 'cash_flow_monthly' => round($cf), 'cash_on_cash_pct' => $invested > 0 ? round($cf * 12 / $invested * 100, 1) : null];
        };
        $main = $calc($downPct, $rate);

        // The year rents (rising 3% a year) first cover the payment and the owning costs; once the
        // loan is paid off there is no payment, so the answer is never beyond the term's end.
        $positiveFrom = null;
        for ($y = 1; $y <= $years + 1; $y++) {
            $rentY = $rent * (1 + self::RENT_GROWTH_PCT / 100) ** ($y - 1);
            $owningY = $owningMonthly * (1 + self::APPRECIATION_PCT / 100) ** ($y - 1);
            $paymentY = $y <= $years ? $main['mortgage_monthly'] : 0.0;
            if ($rentY * (12 - self::VACANCY_MONTHS) / 12 - $owningY - $paymentY >= 0) {
                $positiveFrom = $y;
                break;
            }
        }

        // Walk-away years: cumulative cash flow, loan balance, value, and the sale price that breaks even.
        $i = $rate / 100 / 12;
        $loan = (float) $main['loan'];
        $yearsOut = [];
        $cum = 0.0;
        foreach ([3, 5, 10] as $y) {
            $cum = 0.0;
            for ($k = 1; $k <= $y; $k++) {
                $rentK = $rent * (1 + self::RENT_GROWTH_PCT / 100) ** ($k - 1);
                $owningK = $owningMonthly * (1 + self::APPRECIATION_PCT / 100) ** ($k - 1);
                $cum += ($rentK * (12 - self::VACANCY_MONTHS) / 12 - $owningK - ($k <= $years ? $main['mortgage_monthly'] : 0.0)) * 12;
            }
            $months = min($y, $years) * 12;
            $balance = $loan <= 0 ? 0.0 : ($y >= $years ? 0.0 : $loan * (1 + $i) ** $months - $main['mortgage_monthly'] * ((1 + $i) ** $months - 1) / $i);
            $value = $price * (1 + self::APPRECIATION_PCT / 100) ** $y;
            $equity = $value * (1 - self::SELLING_COST_PCT / 100) - $balance;
            $breakEvenSale = ($main['cash_invested'] - $cum + $balance) / (1 - self::SELLING_COST_PCT / 100);
            $totalReturn = $equity + $cum - $main['cash_invested'];
            $annualized = $main['cash_invested'] > 0 && ($equity + $cum) > 0 ? ((($equity + $cum) / $main['cash_invested']) ** (1 / $y) - 1) * 100 : null;
            $yearsOut[] = ['year' => $y, 'cum_cash_flow' => round($cum), 'loan_balance' => round($balance), 'value' => round($value), 'equity_after_sale' => round($equity), 'break_even_sale_price' => round(max(0, $breakEvenSale)), 'total_return' => round($totalReturn), 'annualized_pct' => $annualized === null ? null : round($annualized, 1)];
        }

        $byDown = array_map(fn ($dp) => $calc((float) $dp, $rate), [20, 30, 50]);
        $byRate = array_map(fn ($r) => ['label' => $r['label'], 'rate' => $r['rate']] + $calc($downPct, $r['rate']), [['label' => 'Pag-IBIG 6.25%', 'rate' => 6.25], ['label' => 'Bank 7%', 'rate' => 7.0], ['label' => 'Bank 8%', 'rate' => 8.0]]);

        $cf = (float) $main['cash_flow_monthly'];
        $strongYield = $areaYield !== null ? $grossYield >= $areaYield : $grossYield >= 5.0;
        if ($grossYield < 2.5) {
            $verdict = 'weak';
            $verdictText = 'The rent is very low against the price: as a rental this loses money every month and only a strong rise in value could rescue it.';
        } elseif ($cf >= 0 && $strongYield) {
            $verdict = 'income';
            $verdictText = 'The rent covers the loan and the running costs with money to spare, and the yield beats the area: an income property from the first month.';
        } elseif ($grossYield >= 4.5) {
            $verdict = 'balanced';
            $verdictText = $positiveFrom !== null && $positiveFrom > $years
                ? sprintf('A fair yield, but at this down payment the rent never covers the loan during its %d years; the unit only pays its own way once the loan is paid off. A larger down payment changes that.', $years)
                : ($positiveFrom !== null && $positiveFrom > 1
                    ? sprintf('A fair yield, but at this down payment the rent falls short of the loan until about year %d as rents rise; a larger down payment turns it positive sooner.', $positiveFrom)
                    : 'A fair yield; the rent roughly covers the loan, so the return comes mostly from paying the loan down and from any rise in value.');
        } else {
            $verdict = 'appreciation';
            $verdictText = 'A low yield for the price: the rent will not carry the loan, so this is a bet on the value rising, not an income property.';
        }

        $what = trim(($scope['tier'] ?? '') !== null && ($scope['tier'] ?? '') !== '' ? $scope['tier'].' '.($scope['type'] ?? 'property') : ($scope['type'] ?? 'property'));

        return [
            'listing_id' => null,
            'name' => null,
            'listing' => null,
            'what' => $what,
            'place' => $scope['place'] ?? null,
            'scope' => $scope['scope'] ?? null,
            'scope_label' => $scope['area_label'] ?? null,
            'price' => round($price),
            'rent' => round($rent),
            'rent_source' => $rentSource,
            'rent_peers' => $rentPeers,
            'gross_yield_pct' => round($grossYield, 1),
            'net_yield_pct' => round($netYield, 1),
            'area_yield_pct' => $areaYield === null ? null : round($areaYield, 1),
            'owning_monthly' => round($owningMonthly),
            'vacancy_months' => self::VACANCY_MONTHS,
            'net_rent_monthly' => round($netRentMonthly),
            'term_years' => $years,
            'rate_pct' => $rate,
            'positive_from_year' => $positiveFrom,
            'by_down' => $byDown,
            'by_rate' => $byRate,
            'years' => $yearsOut,
            'verdict' => $verdict,
            'verdict_text' => $verdictText,
            'assumptions' => [
                'closing_pct' => self::CLOSING_PCT,
                'owning_pct_per_year' => self::OWNING_PCT_PER_YEAR,
                'vacancy_months' => self::VACANCY_MONTHS,
                'rent_growth_pct' => self::RENT_GROWTH_PCT,
                'appreciation_pct' => self::APPRECIATION_PCT,
                'selling_cost_pct' => self::SELLING_COST_PCT,
            ],
        ] + $main;
    }

    /**
     * A place we know but with too few rentals of the asked kind: say how many there are, give the
     * typical sale price when there are enough sales, and the exact question that completes the sum.
     */
    private function thinAreaText(array $place, array $asked): string
    {
        $type = self::dbType($asked['property_type']);
        $plural = strtolower($type === 'Condominium' ? 'condos' : ($type === 'Land' ? 'lots' : $type.'s'));
        $where = $place['barangay'] ? "{$place['barangay']}, {$place['city']}" : $place['city'];
        $rows = array_values(array_filter($this->guide->rows($place['city_id']), fn (array $r) => $r['type'] === $type));
        $rents = count(array_filter($rows, fn (array $r) => $r['category'] === 'For Rent'));
        $sales = array_values(array_filter($rows, fn (array $r) => $r['category'] === 'For Sale'));
        $saleMedian = count($sales) >= 5 ? PriceGuideService::percentileOf(array_column($sales, 'price'), 0.5) : null;

        $lead = $rents === 0
            ? sprintf('There are no **%s for rent in %s** on Filipino Homes', $plural, $place['city'])
            : sprintf('Only **%d %s %s for rent in %s** on Filipino Homes', $rents, $plural, $rents === 1 ? 'is' : 'are', $place['city']);
        $ask = $saleMedian !== null
            ? sprintf('%s typically ask **%s** there. Tell me the rent you expect and I\'ll run the full numbers — for example **"Is a %s %s in %s that rents for %s a month a good investment?"**', ucfirst($plural), PriceCommandService::peso($saleMedian), PriceCommandService::peso($saleMedian), strtolower($type === 'Condominium' ? 'condo' : $type), $where, PriceCommandService::peso(round($saleMedian * 0.005, -2)))
            : sprintf('Tell me the price and the rent you expect and I\'ll run the full numbers — for example **"Is a ₱4M %s in %s that rents for ₱20,000 a month a good investment?"**', strtolower($type === 'Condominium' ? 'condo' : $type), $where);

        return "{$lead}, too few to say what one earns without guessing. {$ask}";
    }

    /** The area's gross yield for the same type, from the guide, when the listing context did not carry one. */
    private function areaYield(array $scope): ?float
    {
        try {
            $place = $this->price->resolvePlace(['city' => (string) ($scope['place']['city'] ?? ''), 'barangay' => (string) ($scope['place']['barangay'] ?? '')]);
            if ($place === null) {
                return null;
            }
            $y = $this->guide->yields($place['city_id'], $place['barangay_id']);
            foreach ($y['types'] as $t) {
                if ($t['type'] === ($scope['type'] ?? '')) {
                    return (float) $t['yield_pct'];
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private static function dbType(string $asked): string
    {
        return match ($asked) {
            'House', 'Apartment', 'Townhouse' => 'House',
            'Lot', 'Land' => 'Land',
            'Commercial' => 'Commercial',
            default => 'Condominium',
        };
    }

    /**
     * @return array{listing_id:int, price:float, rent:float, city:string, barangay:string, property_type:string, tier:string, down_pct:float, term_years:int, rate_pct:float}
     */
    public function extract(array $thread): array
    {
        $empty = ['listing_id' => 0, 'price' => 0.0, 'rent' => 0.0, 'city' => '', 'barangay' => '', 'property_type' => '', 'tier' => '', 'down_pct' => 0.0, 'term_years' => 0, 'rate_pct' => 0.0];
        $last = collect($thread)->where('role', 'user')->last();
        $question = is_array($last) ? (string) ($last['content'] ?? '') : '';
        if (preg_match('/\blisting\s*#\s*(\d+)/i', $question, $m)) {
            $empty['listing_id'] = (int) $m[1];
        }
        try {
            $response = $this->client->chat()->create([
                'model' => 'gpt-5.4-mini',
                'messages' => array_merge([[
                    'role' => 'system',
                    'content' => <<<'SYSTEM'
                    You extract the inputs of a Philippine "is this a good investment" question for Filipino Homes from the MOST RECENT user message (earlier messages only for a place, type or listing it refers to).
                    Amounts are pesos: "4M" = 4000000, "22k" = 22000. The PROPERTY PRICE goes in price; the MONTHLY RENT they expect goes in rent (never swap them: rent is the small monthly figure).
                    City and barangay when named ("Lahug" is a barangay of "Cebu City"; "IT Park" is in Lahug; "BGC" is in Taguig City). "2BR" = "2 Bedroom"; 4 or more = "4+ Bedroom".
                    Down payment as a percent, term in years, rate as a percent per year. Leave what is not said as 0 or "".
                    SYSTEM,
                ]], $thread),
                'functions' => [[
                    'name' => 'extract_investment',
                    'description' => 'The inputs of an investment question.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'price' => ['type' => 'number', 'description' => 'Property price in pesos. 0 if not said.'],
                            'rent' => ['type' => 'number', 'description' => 'Expected monthly rent in pesos. 0 if not said.'],
                            'city' => ['type' => 'string', 'description' => 'City if named, else "".'],
                            'barangay' => ['type' => 'string', 'description' => 'Barangay / district if named, else "".'],
                            'property_type' => ['type' => 'string', 'enum' => ['', 'Condominium', 'House', 'Townhouse', 'Apartment', 'Lot', 'Commercial'], 'description' => 'Type if said.'],
                            'tier' => ['type' => 'string', 'enum' => ['', 'Studio', '1 Bedroom', '2 Bedroom', '3 Bedroom', '4+ Bedroom'], 'description' => 'Unit size if said.'],
                            'down_pct' => ['type' => 'number', 'description' => 'Down payment percent. 0 if not said.'],
                            'term_years' => ['type' => 'integer', 'description' => 'Loan term in years. 0 if not said.'],
                            'rate_pct' => ['type' => 'number', 'description' => 'Annual interest rate percent. 0 if not said.'],
                        ],
                        'required' => ['price', 'rent', 'city', 'barangay', 'property_type', 'tier', 'down_pct', 'term_years', 'rate_pct'],
                    ],
                ]],
                'function_call' => ['name' => 'extract_investment'],
            ]);
            $args = json_decode($response->choices[0]->message->functionCall->arguments ?? '{}', true) ?: [];

            return [
                'listing_id' => $empty['listing_id'],
                'price' => max(0.0, (float) ($args['price'] ?? 0)),
                'rent' => max(0.0, (float) ($args['rent'] ?? 0)),
                'city' => trim((string) ($args['city'] ?? '')),
                'barangay' => trim((string) ($args['barangay'] ?? '')),
                'property_type' => trim((string) ($args['property_type'] ?? '')),
                'tier' => trim((string) ($args['tier'] ?? '')),
                'down_pct' => max(0.0, (float) ($args['down_pct'] ?? 0)),
                'term_years' => max(0, (int) ($args['term_years'] ?? 0)),
                'rate_pct' => max(0.0, (float) ($args['rate_pct'] ?? 0)),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }
}
