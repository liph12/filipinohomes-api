<?php

namespace App\Services\OpenAI;

use App\Models\Listing;
use OpenAI;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ASA-GPT's "what will it really cost to buy?" — the price turned into cash up front, fees and taxes,
 * and the monthly payment, by arithmetic.
 *
 * Reached either from a card ("… listing #123", routed on the id with no model) or from a free
 * question ("fees when buying a 5M condo"), where the model only EXTRACTS the price and the loan
 * terms. The fees are the usual Philippine buyer-side items at their usual rates — documentary
 * stamp tax, transfer tax, registration, notarial, the loan's own stamp tax and bank charges —
 * each labelled, with the seller-side items noted so a buyer knows what is sometimes passed on.
 */
class CostCommandService extends CacheService
{
    use InsightStream;

    private const DEFAULT_DOWN_PCT = 20.0;

    private const DEFAULT_TERM_YEARS = 20;

    private const DEFAULT_RATE_PCT = 6.5;

    /** Banks usually lend against about this share of monthly income. */
    private const INCOME_SHARE = 0.35;

    /** Buyer-side closing items as a share of the price (percent) — the customary rates. */
    private const FEES = [
        ['key' => 'dst', 'label' => 'Documentary stamp tax', 'pct' => 1.5, 'note' => '1.5% of the selling price (or zonal value if higher)'],
        ['key' => 'transfer', 'label' => 'Transfer tax', 'pct' => 0.75, 'note' => 'up to 0.75% in cities, 0.5% in provinces — set by the LGU'],
        ['key' => 'registration', 'label' => 'Registration fee', 'pct' => 0.25, 'note' => 'Registry of Deeds, by schedule — about 0.25%'],
        ['key' => 'notarial', 'label' => 'Notarial fee', 'pct' => 1.0, 'note' => 'commonly 1–2%, negotiable'],
    ];

    /** Loan-side items when financing: stamp tax on the loan and the bank\'s fixed charges. */
    private const LOAN_DST_PCT = 0.75;

    private const BANK_FIXED_FEES = 8000;

    private $client;

    public function __construct(private readonly DataLayerService $data, private readonly DealCommandService $deal)
    {
        $this->client = OpenAI::client(config('services.openai.key'));
    }

    /** From a card button: the listing's own price. */
    public function replyStreamForListing(int $listingId, array $thread, $req): StreamedResponse
    {
        $asked = $this->extract($thread);
        $asked['listing_id'] = $listingId;

        return $this->reply($asked, $req);
    }

    /** From a free question: the price it names (or the listing it names). */
    public function replyStream(array $thread, $req): StreamedResponse
    {
        return $this->reply($this->extract($thread), $req);
    }

    private function reply(array $asked, $req): StreamedResponse
    {
        $card = null;
        $name = null;
        $price = $asked['price'];
        if ($asked['listing_id'] > 0) {
            $listing = Listing::publiclyListed()->with('property')->find($asked['listing_id']);
            if ($listing === null) {
                AsaQuestionLog::outcome($req, 'no_data');

                return $this->streamInsight('cost', 'cost', ['empty' => true], "I can't find that listing any more — it may have been taken down. Tell me a price instead and I'll work out the costs for it.", [], $req);
            }
            $price = (float) $listing->price;
            $name = trim((string) ($listing->property->name ?? $listing->name)) ?: $listing->name;
            $card = $this->data->cardFor((int) $listing->id);
        }
        if ($price < 100000) {
            AsaQuestionLog::outcome($req, 'no_data');

            return $this->streamInsight('cost', 'cost', ['empty' => true], "Tell me the price — for example **\"total cost of buying a ₱4.5M condo\"** — or ask it from a listing's card, and I'll break down the cash you need up front, the fees and taxes, and the monthly payment.", [], $req);
        }

        $insight = $this->insight($price, $asked, $card, $name);
        $text = sprintf(
            '%s at **%s**: you would need about **%s up front** (%s down plus %s in fees and taxes), then about **%s a month** for %d years at %s%%. The full breakdown is below.',
            $name ? "**{$name}**" : 'A home',
            PriceCommandService::peso($price),
            PriceCommandService::peso($insight['cash_upfront']),
            self::pctLabel($insight['down_pct']),
            PriceCommandService::peso($insight['fees_total']),
            PriceCommandService::peso($insight['monthly']),
            $insight['term_years'],
            rtrim(rtrim(number_format($insight['rate_pct'], 2), '0'), '.'),
        );
        $ref = $asked['listing_id'] > 0 ? "listing #{$asked['listing_id']}" : 'a '.PriceCommandService::peso($price).' home';
        $followUps = array_values(array_filter([
            $insight['down_pct'] !== 10.0 ? ['label' => 'With 10% down', 'prompt' => "What will it really cost to buy {$ref} with 10% down payment?"] : ['label' => 'With 20% down', 'prompt' => "What will it really cost to buy {$ref} with 20% down payment?"],
            $insight['term_years'] !== 30 ? ['label' => 'Over 30 years', 'prompt' => "What will it really cost to buy {$ref} over 30 years?"] : ['label' => 'Over 15 years', 'prompt' => "What will it really cost to buy {$ref} over 15 years?"],
            ['label' => 'What can I afford at that monthly?', 'prompt' => sprintf('What can I afford at %s a month?', PriceCommandService::peso($insight['monthly']))],
            $asked['listing_id'] > 0 ? ['label' => 'Is it a good deal?', 'prompt' => "Is this a good deal? listing #{$asked['listing_id']}"] : null,
        ]));
        AsaQuestionLog::outcome($req, 'answered');

        return $this->streamInsight('cost', 'cost', $insight, $text, $followUps, $req);
    }

    /**
     * @return array<string, mixed>
     */
    public function insight(float $price, array $asked, ?array $card, ?string $name): array
    {
        $downPct = $asked['down_pct'] > 0 ? min(90.0, $asked['down_pct']) : self::DEFAULT_DOWN_PCT;
        $years = $asked['term_years'] > 0 ? min(30, (int) $asked['term_years']) : self::DEFAULT_TERM_YEARS;
        $rate = $asked['rate_pct'] > 0 ? min(20.0, $asked['rate_pct']) : self::DEFAULT_RATE_PCT;
        $cash = $asked['cash'];

        $down = $price * $downPct / 100;
        $loan = $cash ? 0.0 : $price - $down;

        $fees = [];
        foreach (self::FEES as $f) {
            $fees[] = ['key' => $f['key'], 'label' => $f['label'], 'pct' => $f['pct'], 'amount' => round($price * $f['pct'] / 100), 'note' => $f['note'], 'side' => 'buyer'];
        }
        if ($loan > 0) {
            $fees[] = ['key' => 'loan_dst', 'label' => 'Stamp tax on the loan', 'pct' => self::LOAN_DST_PCT, 'amount' => round($loan * self::LOAN_DST_PCT / 100), 'note' => '0.75% of the loan amount', 'side' => 'loan'];
            $fees[] = ['key' => 'bank', 'label' => 'Appraisal, processing and handling', 'pct' => null, 'amount' => self::BANK_FIXED_FEES, 'note' => 'about ₱8,000, varies by bank', 'side' => 'loan'];
        }
        $feesTotal = array_sum(array_column($fees, 'amount'));

        $monthly = $loan > 0 ? self::paymentFor($loan, $rate, $years) : 0.0;
        $rates = [['label' => 'Pag-IBIG 6.25%', 'rate' => 6.25], ['label' => 'Bank 7%', 'rate' => 7.0], ['label' => 'Bank 8%', 'rate' => 8.0]];
        $table = [];
        foreach ([10, 15, 20, 25, 30] as $t) {
            $row = ['years' => $t, 'cells' => []];
            foreach ($rates as $r) {
                $row['cells'][] = ['rate' => $r['rate'], 'monthly' => $loan > 0 ? round(self::paymentFor($loan, $r['rate'], $t)) : 0];
            }
            $table[] = $row;
        }
        $totalPaid = $down + $feesTotal + $monthly * $years * 12;
        $interest = $loan > 0 ? $monthly * $years * 12 - $loan : 0.0;

        return [
            'listing_id' => $asked['listing_id'] > 0 ? (int) $asked['listing_id'] : null,
            'name' => $name,
            'listing' => $card,
            'price' => round($price),
            'cash_purchase' => $cash,
            'down_pct' => $downPct,
            'down_payment' => round($down),
            'loan' => round($loan),
            'term_years' => $years,
            'rate_pct' => $rate,
            'fees' => $fees,
            'fees_total' => round($feesTotal),
            'fees_pct' => round($feesTotal / $price * 100, 2),
            'cash_upfront' => round($down + $feesTotal),
            'monthly' => round($monthly),
            'rates' => $rates,
            'table' => $table,
            'total_paid' => round($totalPaid),
            'total_interest' => round($interest),
            'income_needed' => $monthly > 0 ? round($monthly / self::INCOME_SHARE) : null,
            'seller_side' => [
                ['label' => 'Capital gains tax', 'pct' => 6.0, 'amount' => round($price * 0.06), 'note' => 'on resale by an individual; some sellers ask the buyer to shoulder it'],
                ['label' => "Broker's commission", 'pct' => null, 'amount' => null, 'note' => 'usually 3–5%, paid by the seller'],
            ],
            'notes' => [
                'Developer (pre-selling) prices usually already include VAT; a resale between individuals has none.',
                'Banks add mortgage redemption and fire insurance yearly, and condominiums have monthly association dues.',
                'Who pays which fee is set in the deed of sale and is negotiable.',
            ],
        ];
    }

    /** The monthly payment that amortises a loan at an annual rate over a term — the ordinary annuity. */
    public static function paymentFor(float $loan, float $ratePct, int $years): float
    {
        $i = $ratePct / 100 / 12;
        $n = $years * 12;
        if ($i <= 0) {
            return $loan / $n;
        }

        return $loan * $i / (1 - (1 + $i) ** -$n);
    }

    private static function pctLabel(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
    }

    /**
     * @return array{listing_id:int, price:float, down_pct:float, term_years:int, rate_pct:float, cash:bool}
     */
    public function extract(array $thread): array
    {
        $empty = ['listing_id' => 0, 'price' => 0.0, 'down_pct' => 0.0, 'term_years' => 0, 'rate_pct' => 0.0, 'cash' => false];
        $last = collect($thread)->where('role', 'user')->last();
        $question = is_array($last) ? (string) ($last['content'] ?? '') : '';
        // The id and plain percent/term words are read directly; the model fills the rest.
        if (preg_match('/\blisting\s*#\s*(\d+)/i', $question, $m)) {
            $empty['listing_id'] = (int) $m[1];
        }
        try {
            $response = $this->client->chat()->create([
                'model' => 'gpt-5.4-mini',
                'messages' => array_merge([[
                    'role' => 'system',
                    'content' => 'You extract the inputs of a Philippine "total cost of buying" question for Filipino Homes from the MOST RECENT user message (earlier messages only for a price or listing it refers to). Amounts are pesos: "4.5M" = 4500000, "800k" = 800000. The PROPERTY PRICE goes in price (never a monthly amount). Down payment as a percent (e.g. 10). Term in years. Interest rate as a percent per year. cash is true only if they say they will pay in cash / no loan. Leave anything not said as 0 or false.',
                ]], $thread),
                'functions' => [[
                    'name' => 'extract_cost_inputs',
                    'description' => 'The inputs of a cost-of-buying question.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'price' => ['type' => 'number', 'description' => 'The property price in pesos. 0 if not said.'],
                            'down_pct' => ['type' => 'number', 'description' => 'Down payment percent. 0 if not said.'],
                            'term_years' => ['type' => 'integer', 'description' => 'Loan term in years. 0 if not said.'],
                            'rate_pct' => ['type' => 'number', 'description' => 'Annual interest rate percent. 0 if not said.'],
                            'cash' => ['type' => 'boolean', 'description' => 'True only if paying in full without a loan.'],
                        ],
                        'required' => ['price', 'down_pct', 'term_years', 'rate_pct', 'cash'],
                    ],
                ]],
                'function_call' => ['name' => 'extract_cost_inputs'],
            ]);
            $args = json_decode($response->choices[0]->message->functionCall->arguments ?? '{}', true) ?: [];

            return [
                'listing_id' => $empty['listing_id'],
                'price' => max(0.0, (float) ($args['price'] ?? 0)),
                'down_pct' => max(0.0, (float) ($args['down_pct'] ?? 0)),
                'term_years' => max(0, (int) ($args['term_years'] ?? 0)),
                'rate_pct' => max(0.0, (float) ($args['rate_pct'] ?? 0)),
                'cash' => (bool) ($args['cash'] ?? false),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }
}
