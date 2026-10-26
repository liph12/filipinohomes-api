<?php

namespace App\Services\OpenAI;

use OpenAI;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ASA-GPT's "what can I afford?" — a budget turned into a price ceiling, by arithmetic.
 *
 * The model only EXTRACTS the inputs (a monthly budget or an income, a down payment, a term, a
 * rate, a place and a type when said); the numbers are the standard amortisation formula with
 * Philippine defaults — 20% down, 20 years, 6.5% — and a table across terms and rates so the
 * buyer sees how each lever moves the ceiling. Streams a panel like the other reports.
 */
class AffordCommandService extends CacheService
{
    use InsightStream;

    private const DEFAULT_DOWN_PCT = 20.0;

    private const DEFAULT_TERM_YEARS = 20;

    private const DEFAULT_RATE_PCT = 6.5;

    /** A bank will usually lend against this share of take-home income. */
    private const INCOME_SHARE = 0.35;

    private $client;

    public function __construct()
    {
        $this->client = OpenAI::client(config('services.openai.key'));
    }

    public function replyStream(array $thread, $req): StreamedResponse
    {
        $asked = $this->extract($thread);
        $monthly = $asked['monthly_budget'] > 0 ? $asked['monthly_budget'] : ($asked['monthly_income'] > 0 ? round($asked['monthly_income'] * self::INCOME_SHARE) : 0.0);
        if ($monthly <= 0) {
            $text = "Tell me what you can set aside each month for the loan — for example **\"I can pay ₱25,000 a month\"** — or your monthly income, and I'll work out the price range you can look at.";

            AsaQuestionLog::outcome($req, 'no_data');
            return $this->streamInsight('afford', 'afford', ['empty' => true], $text, [], $req);
        }

        $insight = $this->insight($monthly, $asked);
        $spot = $asked['city'] !== '' ? $asked['city'] : null;
        $what = $asked['property_type'] !== '' ? strtolower($asked['property_type']).'s' : 'properties';
        $text = sprintf('With **%s a month** for the loan, you can look at homes up to about **%s**. The full working is below.', PriceCommandService::peso($monthly), PriceCommandService::peso($insight['max_price']));
        $cap = PriceCommandService::peso($insight['max_price']);
        $followUps = array_values(array_filter([
            $spot ? ['label' => "{$what} in {$spot} under {$cap}", 'prompt' => "Show me {$what} for sale in {$spot} under {$cap}"] : ['label' => "Condos under {$cap}", 'prompt' => "Show me condos for sale under {$cap}"],
            $spot ? ['label' => "Typical prices in {$spot}", 'prompt' => "How much are {$what} in {$spot}?"] : null,
            ['label' => 'With 10% down instead', 'prompt' => sprintf('What can I afford at %s a month with 10%% down payment over %d years?', PriceCommandService::peso($monthly), $asked['term_years'] ?: self::DEFAULT_TERM_YEARS)],
        ]));

        return $this->streamInsight('afford', 'afford', $insight, $text, $followUps, $req);
    }

    /**
     * @return array<string, mixed>
     */
    public function insight(float $monthly, array $asked): array
    {
        $down = $asked['down_payment_pct'] > 0 ? min(90.0, $asked['down_payment_pct']) : self::DEFAULT_DOWN_PCT;
        $years = $asked['term_years'] > 0 ? min(30, (int) $asked['term_years']) : self::DEFAULT_TERM_YEARS;
        $rate = $asked['rate_pct'] > 0 ? min(20.0, $asked['rate_pct']) : self::DEFAULT_RATE_PCT;

        $loan = self::loanFor($monthly, $rate, $years);
        $price = $loan / (1 - $down / 100);

        $terms = [10, 15, 20, 25, 30];
        $rates = [['label' => 'Pag-IBIG 6.25%', 'rate' => 6.25], ['label' => 'Bank 7%', 'rate' => 7.0], ['label' => 'Bank 8%', 'rate' => 8.0]];
        $table = [];
        foreach ($terms as $t) {
            $row = ['years' => $t, 'cells' => []];
            foreach ($rates as $r) {
                $l = self::loanFor($monthly, $r['rate'], $t);
                $row['cells'][] = ['rate' => $r['rate'], 'max_price' => round($l / (1 - $down / 100))];
            }
            $table[] = $row;
        }

        return [
            'monthly' => round($monthly),
            'from_income' => $asked['monthly_budget'] <= 0 && $asked['monthly_income'] > 0 ? ['income' => round($asked['monthly_income']), 'share_pct' => (int) round(self::INCOME_SHARE * 100)] : null,
            'down_pct' => $down,
            'term_years' => $years,
            'rate_pct' => $rate,
            'loan' => round($loan),
            'down_payment' => round($price - $loan),
            'max_price' => round($price),
            'rates' => $rates,
            'table' => $table,
            'city' => $asked['city'] ?: null,
            'property_type' => $asked['property_type'] ?: null,
        ];
    }

    /** The loan a monthly payment carries at an annual rate over a term — the ordinary annuity. */
    private static function loanFor(float $monthly, float $ratePct, int $years): float
    {
        $i = $ratePct / 100 / 12;
        $n = $years * 12;
        if ($i <= 0) {
            return $monthly * $n;
        }

        return $monthly * (1 - (1 + $i) ** -$n) / $i;
    }

    /**
     * @return array{monthly_budget: float, monthly_income: float, down_payment_pct: float, term_years: int, rate_pct: float, city: string, property_type: string}
     */
    public function extract(array $thread): array
    {
        $empty = ['monthly_budget' => 0.0, 'monthly_income' => 0.0, 'down_payment_pct' => 0.0, 'term_years' => 0, 'rate_pct' => 0.0, 'city' => '', 'property_type' => ''];
        try {
            $response = $this->client->chat()->create([
                'model' => 'gpt-5.4-mini',
                'messages' => array_merge([[
                    'role' => 'system',
                    'content' => 'You extract the inputs of a Philippine home-affordability question for Filipino Homes from the MOST RECENT user message (earlier messages only for a place or type it refers to). Amounts are pesos: "50k" = 50000, "1.2M" = 1200000. A monthly amount they can PAY goes in monthly_budget; a monthly SALARY/income goes in monthly_income. Down payment as a percent (e.g. 20). Term in years. Interest rate as a percent per year. Write places as full names (CDO → Cagayan de Oro). Leave anything not said as 0 or "".',
                ]], $thread),
                'functions' => [[
                    'name' => 'extract_affordability',
                    'description' => 'The inputs of a "what can I afford" question.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'monthly_budget' => ['type' => 'number', 'description' => 'Pesos per month they can pay toward a loan. 0 if not said.'],
                            'monthly_income' => ['type' => 'number', 'description' => 'Pesos per month they earn, if they gave income instead of a budget. 0 if not said.'],
                            'down_payment_pct' => ['type' => 'number', 'description' => 'Down payment percent, e.g. 20. 0 if not said.'],
                            'term_years' => ['type' => 'integer', 'description' => 'Loan term in years. 0 if not said.'],
                            'rate_pct' => ['type' => 'number', 'description' => 'Annual interest rate percent. 0 if not said.'],
                            'city' => ['type' => 'string', 'description' => 'City or area if named, else "".'],
                            'property_type' => ['type' => 'string', 'enum' => ['', 'Condominium', 'House', 'Apartment', 'Lot', 'Commercial'], 'description' => 'Type if said.'],
                        ],
                        'required' => ['monthly_budget', 'monthly_income', 'down_payment_pct', 'term_years', 'rate_pct', 'city', 'property_type'],
                    ],
                ]],
                'function_call' => ['name' => 'extract_affordability'],
            ]);
            $args = json_decode($response->choices[0]->message->functionCall->arguments ?? '{}', true) ?: [];

            return [
                'monthly_budget' => max(0.0, (float) ($args['monthly_budget'] ?? 0)),
                'monthly_income' => max(0.0, (float) ($args['monthly_income'] ?? 0)),
                'down_payment_pct' => max(0.0, (float) ($args['down_payment_pct'] ?? 0)),
                'term_years' => max(0, (int) ($args['term_years'] ?? 0)),
                'rate_pct' => max(0.0, (float) ($args['rate_pct'] ?? 0)),
                'city' => trim((string) ($args['city'] ?? '')),
                'property_type' => trim((string) ($args['property_type'] ?? '')),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }
}
