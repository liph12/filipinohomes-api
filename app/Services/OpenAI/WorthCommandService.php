<?php

namespace App\Services\OpenAI;

use App\Services\Listing\PriceGuideService;
use OpenAI;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ASA-GPT's "what is my property worth?" — a seller's estimate from our own live listings.
 *
 * The model only EXTRACTS what the owner has (type, size, bedrooms, place, sell or rent out); the
 * estimate is arithmetic over comparable live listings (PriceGuideService rows): the typical ₱/sqm
 * of the same kind of unit in the same barangay — widening to the city, then dropping the size —
 * times the owner's size, with the middle half as the range, the five closest comparables as cards,
 * and the agents who sell most there to list it with. Asking prices, never an appraisal, and the
 * panel says so.
 */
class WorthCommandService extends CacheService
{
    use InsightStream;

    /** Comparables needed before an estimate is shown at all. */
    private const MIN_PEERS = 5;

    private $client;

    public function __construct(private readonly PriceGuideService $guide, private readonly PriceCommandService $price, private readonly DataLayerService $data)
    {
        $this->client = OpenAI::client(config('services.openai.key'));
    }

    public function replyStream(array $thread, $req): StreamedResponse
    {
        $asked = $this->extract($thread);
        if ($asked['city'] === '' && $asked['barangay'] === '') {
            AsaQuestionLog::outcome($req, 'no_data');

            return $this->streamInsight('worth', 'worth', ['empty' => true], "Tell me where it is and what it is — for example **\"What is my 2-bedroom condo in Lahug, 60 sqm, worth?\"** — and I'll work out what similar units are listing for.", [], $req);
        }
        $place = $this->price->resolvePlace(['city' => $asked['city'], 'barangay' => $asked['barangay']]);
        if ($place === null) {
            AsaQuestionLog::outcome($req, 'no_data');
            $where = trim(implode(', ', array_filter([$asked['barangay'], $asked['city']])));

            return $this->streamInsight('worth', 'worth', ['empty' => true], "I don't have enough live listings in **{$where}** yet to estimate a value there. Try the city it belongs to, or a nearby area.", [], $req);
        }

        $insight = $this->insight($place, $asked);
        if ($insight === null) {
            AsaQuestionLog::outcome($req, 'no_data', $place['barangay'] ?: $place['city']);
            $what = strtolower(trim(($asked['tier'] ? $asked['tier'].' ' : '').$asked['property_type']));

            return $this->streamInsight('worth', 'worth', ['empty' => true], sprintf("I don't have enough comparable **%s** listings in **%s** to estimate from yet. I can show you what is listed there so you can compare by hand.", $what ?: 'property', $place['barangay'] ?: $place['city']), [], $req);
        }

        $rentOut = $insight['category'] === 'For Rent';
        $per = $rentOut ? '/month' : '';
        $text = sprintf(
            'Based on **%d comparable %s listings in %s**, your %s would typically list at about **%s%s** — most similar ones ask between %s and %s%s. The working, the closest comparables and the agents who sell most there are below.',
            $insight['peers'],
            $rentOut ? 'rental' : 'sale',
            $insight['scope_label'],
            strtolower($insight['what']),
            PriceCommandService::peso($insight['estimate']),
            $per,
            PriceCommandService::peso($insight['low']),
            PriceCommandService::peso($insight['high']),
            $per,
        );
        $spot = $place['barangay'] ?: $place['city'];
        // The follow-ups name the kind of unit, not its size ("2 bedroom condo", not "…, 60 sqm").
        $what = strtolower(trim(($insight['tier'] ? $insight['tier'].' ' : '').($insight['type'] === 'Condominium' ? 'condo' : ($insight['type'] === 'Land' ? 'lot' : $insight['type']))));
        $followUps = array_values(array_filter([
            $rentOut
                ? ['label' => 'If I sold instead', 'prompt' => sprintf('How much could I sell my %s in %s for?', $what, $spot)]
                : ['label' => 'If I rented it out instead', 'prompt' => sprintf('How much could I rent out my %s in %s for?', $what, $spot)],
            ['label' => "What {$what}s ask in {$spot}", 'prompt' => sprintf('How much are %ss %s in %s?', $what, $rentOut ? 'for rent' : 'for sale', $spot)],
            ['label' => "Who sells most in {$spot}", 'prompt' => "Find me the best agent for {$what}s in {$spot}"],
            $rentOut ? null : ['label' => 'Rent or sell: which pays?', 'prompt' => "Should I rent out or sell my {$what} in {$spot}?"],
        ]));
        AsaQuestionLog::outcome($req, 'answered', $spot);

        return $this->streamInsight('worth', 'worth', $insight, $text, $followUps, $req);
    }

    /**
     * The estimate as data for the page's panel. Null when there are too few comparables anywhere
     * in the city.
     *
     * @return array<string, mixed>|null
     */
    public function insight(array $place, array $asked): ?array
    {
        $type = self::dbType($asked['property_type']);
        $category = $asked['purpose'] === 'rent' ? 'For Rent' : 'For Sale';
        $tier = $asked['tier'] !== '' ? $asked['tier'] : null;
        $size = $type === 'Land' || $type === 'Commercial' ? ($asked['lot_area_sqm'] ?: $asked['floor_area_sqm']) : ($asked['floor_area_sqm'] ?: 0.0);

        $rows = array_values(array_filter($this->guide->rows($place['city_id']), fn (array $r) => $r['category'] === $category && $r['type'] === $type));
        if ($rows === []) {
            return null;
        }

        // Narrowest comparable set with enough listings: barangay + size, barangay, city + size, city.
        $attempts = [];
        if ($place['barangay_id'] !== null) {
            if ($tier !== null) {
                $attempts[] = ['barangay', $tier];
            }
            $attempts[] = ['barangay', null];
        }
        if ($tier !== null) {
            $attempts[] = ['city', $tier];
        }
        $attempts[] = ['city', null];

        $peers = [];
        $scope = 'city';
        $tierUsed = null;
        foreach ($attempts as [$sc, $t]) {
            $set = array_values(array_filter($rows, fn (array $r) => ($sc === 'city' || $r['barangay_id'] === $place['barangay_id']) && ($t === null || $r['tier'] === $t)));
            if (count($set) >= self::MIN_PEERS) {
                $peers = $set;
                $scope = $sc;
                $tierUsed = $t;
                break;
            }
        }
        if ($peers === []) {
            return null;
        }

        // ₱/sqm × the owner's size when both sides have sizes; otherwise the typical price outright.
        $withSqm = array_values(array_filter($peers, fn (array $r) => $r['ppsqm'] !== null));
        $basis = 'price';
        $ppsqmMedian = PriceGuideService::percentileOf(array_column($withSqm, 'ppsqm'), 0.5);
        if ($size > 0 && count($withSqm) >= self::MIN_PEERS && $ppsqmMedian !== null) {
            $basis = 'ppsqm';
            $estimate = $ppsqmMedian * $size;
            $low = PriceGuideService::percentileOf(array_column($withSqm, 'ppsqm'), 0.25) * $size;
            $high = PriceGuideService::percentileOf(array_column($withSqm, 'ppsqm'), 0.75) * $size;
        } else {
            $prices = array_column($peers, 'price');
            $estimate = PriceGuideService::percentileOf($prices, 0.5);
            $low = PriceGuideService::percentileOf($prices, 0.25);
            $high = PriceGuideService::percentileOf($prices, 0.75);
        }
        $allPrices = array_column($peers, 'price');
        $min = min($allPrices);
        $max = max($allPrices);

        // The closest comparables: by size when the owner gave one, else by price to the estimate.
        $ranked = $peers;
        usort($ranked, function (array $a, array $b) use ($size, $estimate) {
            if ($size > 0 && $a['sqm'] !== null && $b['sqm'] !== null) {
                return abs($a['sqm'] - $size) <=> abs($b['sqm'] - $size);
            }
            if ($size > 0 && ($a['sqm'] === null) !== ($b['sqm'] === null)) {
                return $a['sqm'] === null ? 1 : -1;
            }

            return abs($a['price'] - $estimate) <=> abs($b['price'] - $estimate);
        });
        $comparables = [];
        foreach (array_slice($ranked, 0, 5) as $r) {
            $card = $this->data->cardFor((int) $r['listing_id']);
            if ($card !== null) {
                $comparables[] = $card;
            }
        }

        // Who to list it with: the agents with the most live listings in the place.
        $top = $this->guide->topAgents($place['city_id'], $scope === 'barangay' ? $place['barangay_id'] : null, 3);
        $scopeLabel = $scope === 'barangay' ? "{$place['barangay']}, {$place['city']}" : $place['city'];
        $agents = $this->data->agentCards(array_column($top, 'id'), array_column($top, 'listings', 'id'), $scope === 'barangay' ? (string) $place['barangay'] : $place['city']);

        $peerCount = count($peers);
        $what = trim(($tierUsed ?? ($tier ?? '')).' '.$type);
        if ($size > 0) {
            $what .= sprintf(', %s sqm', rtrim(rtrim(number_format($size, 1), '0'), '.'));
        }

        return [
            'category' => $category,
            'type' => $type,
            'tier' => $tierUsed,
            'size_sqm' => $size > 0 ? $size : null,
            'what' => $what,
            'place' => ['city' => $place['city'], 'barangay' => $place['barangay'], 'province' => $place['province']],
            'scope' => $scope,
            'scope_label' => $scopeLabel,
            'peers' => $peerCount,
            'confidence' => $peerCount >= 15 ? 'high' : ($peerCount >= 8 ? 'medium' : 'low'),
            'basis' => $basis,
            'estimate' => round($estimate),
            'low' => round($low),
            'high' => round($high),
            'min' => round($min),
            'max' => round($max),
            'median_ppsqm' => $ppsqmMedian === null ? null : round($ppsqmMedian),
            'ppsqm_listings' => count($withSqm),
            'comparables' => $comparables,
            'agents' => $agents,
            'tiers' => $tierUsed === null && $tier === null ? $this->tierBreakdown($peers) : [],
        ];
    }

    /**
     * When no size was given, what each unit size typically asks, so the owner can place theirs.
     *
     * @return array<int, array{tier:string, listings:int, median:float}>
     */
    private function tierBreakdown(array $peers): array
    {
        $out = [];
        foreach (PriceGuideService::tierOrder() as $t) {
            $set = array_values(array_filter($peers, fn (array $r) => $r['tier'] === $t));
            if (count($set) >= 3) {
                $out[] = ['tier' => $t, 'listings' => count($set), 'median' => round((float) PriceGuideService::percentileOf(array_column($set, 'price'), 0.5))];
            }
        }

        return $out;
    }

    /** The extractor's type words as the database's property types. */
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
     * @return array{city:string, barangay:string, property_type:string, tier:string, floor_area_sqm:float, lot_area_sqm:float, purpose:string}
     */
    public function extract(array $thread): array
    {
        $empty = ['city' => '', 'barangay' => '', 'property_type' => '', 'tier' => '', 'floor_area_sqm' => 0.0, 'lot_area_sqm' => 0.0, 'purpose' => 'sell'];
        try {
            $response = $this->client->chat()->create([
                'model' => 'gpt-5.4-mini',
                'messages' => array_merge([[
                    'role' => 'system',
                    'content' => <<<'SYSTEM'
                    You extract what an owner is describing in a "what is my property worth" question for Filipino Homes (Philippines).
                    Use the MOST RECENT user message; earlier messages only for a place, type or size the latest one refers to.
                    Return the city and, when named, the barangay (e.g. "Lahug" is a barangay of "Cebu City"; "IT Park" is in Lahug, Cebu City; "BGC" is in Taguig City; "Mactan" is in Lapu-Lapu City). Fill the city when you know it from the barangay.
                    Sizes in square metres: "60 sqm" = 60, "1,200 sq m lot" = lot 1200. "2BR" = "2 Bedroom"; 4 or more bedrooms = "4+ Bedroom".
                    purpose is "rent" when they ask what they could RENT it out / lease it for; otherwise "sell".
                    Leave what is not said empty ("" or 0). Never output prices.
                    SYSTEM,
                ]], $thread),
                'functions' => [[
                    'name' => 'extract_valuation',
                    'description' => 'The owner\'s property as described.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'city' => ['type' => 'string', 'description' => 'City or municipality. "" if none.'],
                            'barangay' => ['type' => 'string', 'description' => 'Barangay / district inside the city. "" if none.'],
                            'property_type' => ['type' => 'string', 'enum' => ['', 'Condominium', 'House', 'Townhouse', 'Apartment', 'Lot', 'Commercial'], 'description' => 'What it is (condo/unit = Condominium; house and lot/townhouse = House; lot/land = Lot).'],
                            'tier' => ['type' => 'string', 'enum' => ['', 'Studio', '1 Bedroom', '2 Bedroom', '3 Bedroom', '4+ Bedroom'], 'description' => 'Unit size by bedrooms if said.'],
                            'floor_area_sqm' => ['type' => 'number', 'description' => 'Floor area in sqm. 0 if not said.'],
                            'lot_area_sqm' => ['type' => 'number', 'description' => 'Lot area in sqm. 0 if not said.'],
                            'purpose' => ['type' => 'string', 'enum' => ['sell', 'rent'], 'description' => '"rent" only when they ask what it could rent for.'],
                        ],
                        'required' => ['city', 'barangay', 'property_type', 'tier', 'floor_area_sqm', 'lot_area_sqm', 'purpose'],
                    ],
                ]],
                'function_call' => ['name' => 'extract_valuation'],
            ]);
            $args = json_decode($response->choices[0]->message->functionCall->arguments ?? '{}', true) ?: [];

            return [
                'city' => trim((string) ($args['city'] ?? '')),
                'barangay' => trim((string) ($args['barangay'] ?? '')),
                'property_type' => trim((string) ($args['property_type'] ?? '')),
                'tier' => trim((string) ($args['tier'] ?? '')),
                'floor_area_sqm' => max(0.0, (float) ($args['floor_area_sqm'] ?? 0)),
                'lot_area_sqm' => max(0.0, (float) ($args['lot_area_sqm'] ?? 0)),
                'purpose' => ($args['purpose'] ?? 'sell') === 'rent' ? 'rent' : 'sell',
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }
}
