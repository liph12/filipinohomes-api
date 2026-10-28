<?php

namespace App\Services\OpenAI;

use App\Models\Agent;
use App\Models\Listing;
use App\Services\Listing\PriceGuideService;
use Illuminate\Support\Facades\DB;

class DataLayerService
{
    /** Set by getAgentsByArguments for extractAgent: the words of the area the user asked about, lowercased. */
    private array $areaWords = [];

    private string $areaLabel = '';

    /** NATCON awardee records by lower-cased email, loaded once per agent search (read-only). */
    private array $natconByEmail = [];

    public function __construct(private readonly PriceGuideService $priceGuide, private readonly LandmarkLocator $landmarks)
    {
    }

    /** Everyday abbreviations for places, expanded before matching addresses. Lower-case keys. */
    private const PLACE_ALIASES = [
        'cdo' => 'Cagayan de Oro',
        // Addresses say "Taguig" far more often than they spell out the district.
        'bgc' => 'Taguig',
        'qc' => 'Quezon City',
        'gensan' => 'General Santos',
        'mnl' => 'Manila',
        'ncr' => 'Metro Manila',
        'mm' => 'Metro Manila',
        'sjdm' => 'San Jose del Monte',
    ];

    /** Words that match almost every address or none ("de", "city", "(cdo)") and only slow the LIKEs. */
    private const PLACE_STOPWORDS = ['de', 'del', 'la', 'las', 'los', 'city', 'cty', 'the', 'of', 'in', 'sa', 'ng', 'philippines', 'ph', 'area', 'province', 'metro'];

    /**
     * The words of a typed place that are worth matching an address on: abbreviations expanded,
     * punctuation dropped, filler words removed. "Cagayan de Oro (CDO)" → ["cagayan", "oro"].
     *
     * @return array<int, string>
     */
    public static function placeWords(string $place): array
    {
        $text = mb_strtolower(trim($place));
        $text = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?? $text;
        $out = [];
        foreach (preg_split('/\s+/u', $text) ?: [] as $w) {
            if ($w === '') {
                continue;
            }
            $expanded = mb_strtolower(self::PLACE_ALIASES[$w] ?? $w);
            foreach (explode(' ', $expanded) as $part) {
                if (mb_strlen($part) >= 3 && ! in_array($part, self::PLACE_STOPWORDS, true)) {
                    $out[] = $part;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** Whether a table exists, asked once per request — the production schema can trail local. */
    private static function hasTable(string $table): bool
    {
        static $known = [];
        if (! array_key_exists($table, $known)) {
            try {
                $known[$table] = \Illuminate\Support\Facades\Schema::hasTable($table);
            } catch (\Throwable) {
                $known[$table] = false;
            }
        }

        return $known[$table];
    }

    /** The listing against its market (see PriceGuideService::contextForListing); never lets a card fail. */
    private function priceContext(int $listingId): ?array
    {
        try {
            return $this->priceGuide->contextForListing($listingId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function priceChange(int $listingId): ?array
    {
        try {
            return $this->priceGuide->priceChangeFor($listingId);
        } catch (\Throwable) {
            return null;
        }
    }

    /** One listing as the chat draws it (the same shape the search returns), or null when not public. */
    public function cardFor(int $listingId): ?array
    {
        $l = Listing::publiclyListed()->with(['property.propertyAttribute.subtype.type', 'property.furnishing', 'category', 'agent.user'])->find($listingId);

        return $l === null ? null : $this->extractListing($l);
    }

    protected function extractListing($l)
    {
        $agentName = $l->agent->first_name . " " . $l->agent->last_name;
        $userData = $l->agent->user;
        $attributes = $l->property->propertyAttribute;
        $subType = $attributes->subtype;
        
        return [
            'id' => $l->id,
            'name' => $l->name,
            'photo' => $l->featured_photo,
            'slug' => $l->slug,
            'propertyName' => $l->property->name ?? "",
            'propertyType' => $subType->type->name,
            'propertySubType' => $subType->name,
            'propertyAttributes' => [
                'beds' => $attributes->bedroom_count,
                'baths' => $attributes->bathroom_count,
                'garage' => $attributes->garage_count,
                'floorArea' => (float)$attributes->floor_area ?? 0,
                'lotArea' => (float)$attributes->lot_area ?? 0,
            ],
            'address' => $l->property->address ?? "",
            'description' => $l->property->description ?? "",
            'furnishing' => $l->property->furnishing->name ?? "",
            'category' => $l->category->name ?? "",
            'price' => $l->price ?? 0,
            // "12% below typical for a 2 Bedroom Condominium in Lahug" — the card's one line of market context.
            'priceContext' => $this->priceContext((int) $l->id),
            // "₱500K off · 12 days ago" — the latest price cut from the audit log, when recent.
            'priceChange' => $this->priceChange((int) $l->id),
            // "650 m from IT Park" — set only on a near-a-place search (see getListingsByArguments).
            'distance' => isset($l->distance_m) && isset($l->near_label)
                ? ['landmark' => (string) $l->near_label, 'meters' => (int) round((float) $l->distance_m)]
                : null,
            'views' => $l->clicks ?? 0,
            'listedAt' => date('M d, Y', strtotime($l->created_at)),
            'agent' => [
                'name' => $agentName,
                'avatar' => $userData->avatar ?? null,
                'mobile' => $userData->mobile_no ?? null,
                'email' => $userData->email ?? null,
                'address' => $l->agent->address ?? null,
            ]
        ];
    }

    protected function extractAgent($a, ?int $areaListings = null)
    {
        $agentName = $a->first_name . " " . $a->last_name;
        $topListings = [];

        // A light summary of the agent's most-viewed listings, straight from the eager-loaded
        // relations. The full card builder (market context, price change, lazy agent/category
        // loads) ran here before — thousands of queries for a ten-agent answer on production.
        foreach ($a->listings as $l) {
            $sub = $l->property?->propertyAttribute?->subtype;
            $topListings[] = [
                'type' => $sub?->type?->name ?? '',
                'subType' => $sub?->name ?? '',
                'views' => (int) ($l->clicks ?? 0),
                'category' => $l->category?->name ?? '',
            ];
        }
        
        // Proven performance, from our own records. Null where there is no data yet — never a guess.
        $reviews = (int) ($a->total_reviews ?? 0);
        $sample = (int) ($a->response_sample_size ?? 0);
        $medianSec = $a->median_first_response_seconds;
        // Production may trail local on migrations (awards, response metrics): a missing table or
        // column must cost the badge, never the whole agent search.
        $awards = [];
        try {
            if (self::hasTable('agent_awards')) {
                $awards = DB::table('agent_awards')->where('agent_id', $a->id)->orderByDesc('granted_at')->limit(3)->get(['title', 'tier'])
                    ->map(fn ($w) => ['title' => (string) $w->title, 'tier' => $w->tier === null ? null : (string) $w->tier])->values()->all();
            }
        } catch (\Throwable) {
            $awards = [];
        }
        // NATCON awardee years, matched by the agent's email — Leuterio Realty's own recognition,
        // read from the convention's list and never written to.
        $natcon = $this->natconByEmail[mb_strtolower(trim((string) ($a->user->email ?? '')))] ?? [];

        return [
            'id' => $a->id,
            'name' => $agentName,
            'avatar' => $a->user->avatar,
            'mobile' => $a->user->mobile_no,
            'email' => $a->user->email,
            'address' => $a->address,
            'listings_count' => $a->listings_count,
            'top_listings' => $topListings,
            'rating' => $reviews > 0 && $a->avg_rating !== null ? ['avg' => round((float) $a->avg_rating, 1), 'count' => $reviews] : null,
            'response' => $sample > 0 ? [
                'median_minutes' => $medianSec === null ? null : (int) round(((int) $medianSec) / 60),
                'within_1h_pct' => $a->within_1h_response_pct === null ? null : (int) round((float) $a->within_1h_response_pct),
                'unanswered_pct' => $a->unanswered_response_pct === null ? null : (int) round((float) $a->unanswered_response_pct),
                'sample' => $sample,
            ] : null,
            'awards' => $awards,
            'years_active' => $a->member_since ? max(0, (int) \Illuminate\Support\Carbon::parse($a->member_since)->diffInYears(now())) : null,
            'area_listings' => $areaListings,
            'area_label' => $areaListings === null ? null : $this->areaLabel,
            'natcon' => $natcon,
        ];
    }

    /**
     * NATCON awardee records for a set of emails: [{year, segment, team}] per lower-cased email,
     * newest convention first. Read-only; an unavailable table simply means no badges.
     *
     * @param  array<int, string>  $emails
     * @return array<string, array<int, array{year:int, segment:?string, team:?string}>>
     */
    private function natconAwards(array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(fn ($e) => mb_strtolower(trim((string) $e)), $emails))));
        if ($emails === [] || ! self::hasTable('natcon_recipients') || ! self::hasTable('natcon_events')) {
            return [];
        }
        try {
            $rows = DB::table('natcon_recipients')
                ->join('natcon_events', 'natcon_events.id', '=', 'natcon_recipients.natcon_event_id')
                ->whereNull('natcon_recipients.deleted_at')
                ->whereIn(DB::raw('LOWER(natcon_recipients.email)'), $emails)
                ->orderByDesc('natcon_events.year')
                ->get(['natcon_recipients.email', 'natcon_recipients.award_segment', 'natcon_recipients.team', 'natcon_events.year']);
            $byEmail = [];
            foreach ($rows as $r) {
                $byEmail[mb_strtolower((string) $r->email)][] = [
                    'year' => (int) $r->year,
                    'segment' => $r->award_segment !== null && $r->award_segment !== '' ? (string) $r->award_segment : null,
                    'team' => $r->team !== null && $r->team !== '' ? (string) $r->team : null,
                ];
            }

            return $byEmail;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Set by getListingsByArguments when the results come from a wider place than asked. */
    public ?string $searchNote = null;

    /** How many listings a search aims to hand the picker. */
    private const SEARCH_TARGET = 5;

    /**
     * The listings a search asks for. The strict search runs first; when it finds too few, it is relaxed
     * one notch at a time — keywords, then subtype, then the barangay → its city → its province — so
     * "commercial lot in Davao" still finds Davao's lots when no title says "commercial lot", and "house
     * in Lahug under ₱5M" shows the rest of Cebu City when Lahug has none. The budget, property type and
     * category never relax, and widening the place is announced in $searchNote.
     */
    public function getListingsByArguments($args): array
    {
        $this->searchNote = null;
        $asked = trim((string) ($args['address'] ?? ''));
        $near = trim((string) ($args['near'] ?? '')) !== '';
        $expanded = self::PLACE_ALIASES[mb_strtolower($asked)] ?? $asked;
        $place = ($asked !== '' && ! $near && (int) ($args['city_id'] ?? 0) === 0) ? $this->priceGuide->placeOf($expanded) : null;
        $askedLabel = ucwords(mb_strtolower($expanded));

        // A named city means that city — matched by its id, not by the word "Cebu", which every town
        // in the province carries. "Lahug, Cebu City" keeps the barangay words and adds the city id.
        // A bare "Cebu" stays province-wide: it names the province as much as the city.
        $strict = $args;
        if ($place !== null && $place['kind'] === 'city' && self::namesCityStrictly($expanded, $place)) {
            $strict['city_id'] = $place['city_id'];
            $strict['address'] = self::withoutCityPiece($expanded, $place);
        }

        $rows = $this->runListingSearch($strict);
        if (count($rows) >= self::SEARCH_TARGET) {
            return $rows;
        }

        // Keywords are the loosest part of a search ("commercial lot" in the title): top up without them.
        $relaxed = $strict;
        if (trim((string) ($relaxed['query_words'] ?? '')) !== '') {
            $relaxed['query_words'] = '';
            $rows = self::mergeRows($rows, $this->runListingSearch($relaxed));
        }
        if ($rows === [] && trim((string) ($relaxed['property_subtype'] ?? '')) !== '') {
            $relaxed['property_subtype'] = '';
            $rows = $this->runListingSearch($relaxed);
        }
        if (count($rows) >= self::SEARCH_TARGET || $place === null) {
            return $rows;
        }

        // Too few in the place itself: the city it belongs to, then the province — and say so.
        $inPlace = count($rows);
        $wider = [];
        if ($place['kind'] === 'barangay') {
            $more = $this->runListingSearch(['city_id' => $place['city_id'], 'address' => ''] + $relaxed);
            $merged = self::mergeRows($rows, $more);
            if (count($merged) > count($rows)) {
                $wider[] = "the rest of {$place['city']}";
            }
            $rows = $merged;
        }
        if (count($rows) < self::SEARCH_TARGET && mb_strtolower($place['province']) !== mb_strtolower($expanded)) {
            $more = $this->runListingSearch(['city_id' => 0, 'address' => $place['province']] + $relaxed);
            $merged = self::mergeRows($rows, $more);
            if (count($merged) > count($rows)) {
                $wider[] = "elsewhere in {$place['province']}";
            }
            $rows = $merged;
        }
        if ($wider !== []) {
            $this->searchNote = $inPlace === 0
                ? sprintf('Nothing matched in %s itself, so these are from %s.', $askedLabel, implode(' and ', $wider))
                : sprintf('Only %d matched in %s itself; the rest are from %s.', $inPlace, $askedLabel, implode(' and ', $wider));
        }

        return $rows;
    }

    /** "Cebu City", "Davao", "Mandaue" name a city; a bare "Cebu" could mean the province too. */
    private static function namesCityStrictly(string $asked, array $place): bool
    {
        $norm = fn (string $s) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9ñ ]/u', ' ', mb_strtolower($s))));
        $bare = fn (string $s) => trim(preg_replace('/\b(city|municipality)\b/', '', $norm($s)));
        if (preg_match('/\bcity\b/i', $asked)) {
            return true;
        }

        return $bare($place['city']) !== $bare($place['province']);
    }

    /** "Lahug, Cebu City" → "Lahug"; "Cebu City" → "" (the id carries the city). */
    private static function withoutCityPiece(string $asked, array $place): string
    {
        $norm = fn (string $s) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9ñ ]/u', ' ', mb_strtolower($s))));
        $bare = fn (string $s) => trim(preg_replace('/\b(city|municipality)\b/', '', $norm($s)));
        $cityForms = [$norm($place['city']), $bare($place['city'])];
        $kept = [];
        foreach (explode(',', $asked) as $piece) {
            $piece = trim($piece);
            if ($piece === '' || in_array($norm($piece), $cityForms, true) || in_array($bare($piece), $cityForms, true)) {
                continue;
            }
            $kept[] = $piece;
        }

        return implode(', ', $kept);
    }

    /** Rows of $a, then those of $b not already in it, up to the search target. */
    private static function mergeRows(array $a, array $b): array
    {
        $seen = array_flip(array_map(fn ($r) => (string) $r['id'], $a));
        foreach ($b as $r) {
            if (count($a) >= self::SEARCH_TARGET) {
                break;
            }
            if (! isset($seen[(string) $r['id']])) {
                $a[] = $r;
                $seen[(string) $r['id']] = true;
            }
        }

        return $a;
    }

    private function runListingSearch(array $args): array
    {
        $address = $args['address'];
        $attr = $args['attributes'];
        $inquiredListings = [];
        $words = strtolower($args['query_words']);
        $array_words = explode(' ', $words);

        // "Near IT Park": a point to measure from. When the place is known, results are the listings
        // within the radius (1.5 km unless the user said otherwise), nearest first, each carrying its
        // distance. When it is not, the search runs as before, by text.
        $near = $args['near'] ?? '';
        $point = $near !== '' ? $this->landmarks->locate((string) $near, (string) $address) : null;
        $radiusM = ((float) ($args['radius_km'] ?? 0) > 0 ? (float) $args['radius_km'] : 1.5) * 1000;

        // "Listings that dropped price this month": only ids with a recent cut, biggest cut first.
        $dropDays = (int) ($args['price_dropped_days'] ?? 0);
        $dropIds = $dropDays > 0 ? $this->priceGuide->priceDropListingIds($dropDays) : null;
        if ($dropIds !== null && $dropIds === []) {
            return [];
        }

        // No category said: a budget in the millions is a purchase (sale or foreclosure), a budget of a
        // few thousand a month is a rental — so a ₱30k rental never answers "house with a ₱5M budget".
        $priceMax = (float) ($attr['price_max'] ?? 0);
        $listings = Listing::publiclyListed()->whereHas('category', function($q) use($args, $priceMax){
            if($args['category'] !== "")
            {
                $q->where('categories.name', $args['category']);
            } elseif ($priceMax >= 300000) {
                $q->where('categories.name', '!=', 'For Rent');
            } elseif ($priceMax > 0 && $priceMax <= 100000) {
                $q->where('categories.name', 'For Rent');
            }
        })->where(function ($q) use ($array_words) {
            // Each keyword in the title OR the description ("beachfront" is rarely in both).
            foreach ($array_words as $w) {
                $lower = trim(strtolower($w));
                if ($lower === '') {
                    continue;
                }
                $q->where(function ($sub) use ($lower) {
                    $sub->where('listings.name', 'LIKE', "%{$lower}%")
                        ->orWhereHas('property', fn ($p) => $p->where('properties.description', 'LIKE', "%{$lower}%"));
                });
            }
        })
        // A ₱990 house or a ₱100 condo is a placeholder, not a price: never offer one. Same floors as
        // the market statistics.
        ->whereRaw('listings.price >= CASE WHEN listings.category_id = ? THEN ? ELSE ? END', [2, PriceGuideService::RENT_PRICE_FLOOR, PriceGuideService::SALE_PRICE_FLOOR]);

        if($args['date_from'] !== "" && $args["date_to"] !== "")
        {
            $listings = $listings->whereBetween('listings.created_at', [$args['date_from'], $args['date_to']]);
        }

        if($attr['price_min'] > 0 || $attr['price_max'] > 0)
        {
            if($attr['price_min'] > $attr['price_max'])
            {
                $listings = $listings->whereBetween('listings.price', [$attr['price_max'], $attr['price_min']]);
            }else{
                $listings = $listings->whereBetween('listings.price', [$attr['price_min'], $attr['price_max']]);
            }
        }
        
        $listings = $listings->whereHas('agent', function($q) use($args){
            $agent = $args['agent_name'];
            $queries = explode(' ', $agent);

            $q->withCount('listings')->where(function ($q) use ($queries) {
                foreach ($queries as $w) {
                    $q->where(function ($sub) use ($w) {
                        $sub->where('first_name', 'LIKE', "%{$w}%")
                        ->orWhere('middle_name', 'LIKE', "%{$w}%")
                        ->orWhere('last_name', 'LIKE', "%{$w}%");
                    });
                }
            })->having('listings_count', '>=', $args['listings_count']);
        })->whereHas('property', function($q) use($args, $address, $attr, $point){    
            // "Lahug, Cebu City" → lahug / cebu; "CDO" → cagayan / oro (see placeWords).
            $addr = self::placeWords((string) $address);

            // Widened to a city: the property's effective city (its pin, else its typed barangay's city).
            $cityId = (int) ($args['city_id'] ?? 0);
            if ($cityId > 0) {
                $q->where(function ($q) use ($cityId) {
                    $q->where('properties.geo_city_id', $cityId)
                        ->orWhere(function ($q) use ($cityId) {
                            $q->whereNull('properties.geo_city_id')
                                ->whereHas('barangay', fn ($b) => $b->where('barangays.city_id', $cityId));
                        });
                });
            }

            // Measuring from a known point, the pin decides the place — the typed address need not
            // name it (a listing 400 m from IT Park may say "Apas", not "Lahug"). With a city id the
            // remaining words (a barangay) still narrow within that city.
            if ($point === null && $addr !== []) {
                $q->where(function ($q) use ($addr) {
                    foreach ($addr as $w) {
                        $q->where(function ($sub) use ($w) {
                            $sub->where('properties.address', 'LIKE', "%{$w}%");
                        });
                    }
                });
            }
            $q->whereHas('propertyAttribute', function($q) use($attr, $args){
                $q->whereHas('subtype', function($q) use($args){
                    $q->whereHas('type', function($q) use($args){
                        if(!empty($args['property_type']))
                        {
                            $q->where('property_types.name', $args['property_type']);
                        }
                    });
                    if(!empty($args['property_subtype']))
                    {
                        $q->where('property_subtypes.name', $args['property_subtype']);
                    }
                });

                if(isset($attr['parking']) && $attr['parking'] > 0)
                {
                    $q->where('garage_count', '>=', $attr['parking']);
                }
                if(isset($attr['baths']) && $attr['baths'] > 0)
                {
                    $q->where('bathroom_count', '>=', $attr['baths']);
                }
                if(isset($attr['beds']) && $attr['beds'] > 0)
                {
                    $q->where('bedroom_count', '>=', $attr['beds']);
                }
                if(isset($attr['floor_area']) && $attr['floor_area'] > 0)
                {
                    $q->where('floor_area', '>=', $attr['floor_area']);
                }
                if(isset($attr['lot_area']) && $attr['lot_area'] > 0)
                {
                    $q->where('lot_area', '>=', $attr['lot_area']);
                }
            });
        })->with(['property.propertyAttribute.subtype.type', 'category']);

        if ($dropIds !== null) {
            $listings = $listings->whereIn('listings.id', array_slice($dropIds, 0, 200));
        }

        if ($point !== null) {
            // Haversine on the property's JSON pin, in metres; plain trig so it runs on any MySQL/MariaDB.
            $lat = DB::raw("CAST(JSON_UNQUOTE(JSON_EXTRACT(near_p.geo_coordinates, '$.lat')) AS DECIMAL(11,8))");
            $lng = DB::raw("CAST(JSON_UNQUOTE(JSON_EXTRACT(near_p.geo_coordinates, '$.lng')) AS DECIMAL(11,8))");
            $listings = $listings
                ->join('properties as near_p', 'near_p.id', '=', 'listings.property_id')
                ->whereNotNull('near_p.geo_coordinates')
                ->select('listings.*')
                ->selectRaw(
                    "(6371000 * ACOS(LEAST(1, COS(RADIANS(?)) * COS(RADIANS({$lat->getValue(DB::connection()->getQueryGrammar())})) * COS(RADIANS({$lng->getValue(DB::connection()->getQueryGrammar())}) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS({$lat->getValue(DB::connection()->getQueryGrammar())}))))) as distance_m",
                    [$point['lat'], $point['lng'], $point['lat']]
                )
                ->having('distance_m', '<=', $radiusM)
                ->orderBy('distance_m')
                ->limit(8);
        } elseif ($dropIds !== null) {
            // Biggest cut first, as priceDropListingIds ordered them.
            $order = implode(',', array_map('intval', array_slice($dropIds, 0, 200)));
            $listings = $listings->orderByRaw("FIELD(listings.id, {$order})")->limit(8);
        } else {
            $listings = $listings->orderBy('created_at', 'DESC')->limit(5);
        }

        $listings = $listings->get();

        foreach ($listings as $l) {
            if ($point !== null) {
                $l->near_label = $point['label'];
            }
            $inquiredListings[] = $this->extractListing($l);
        }

        return $inquiredListings;
    }

    public function getAgentsByArguments($args): array
    {
        $inquiredAgents = [];
        // The area the user asked about: where they want the agent to work (listing_address), else
        // where the agent is based (agent_address). Both the match below and extractAgent's
        // `area_listings` use it.
        $area = trim((string) ($args['listing_address'] ?? '')) ?: trim((string) ($args['agent_address'] ?? ''));
        $this->areaWords = self::placeWords($area);
        // The label follows the expansion too: "CDO" is shown as "Cagayan de Oro".
        $this->areaLabel = self::PLACE_ALIASES[mb_strtolower(trim($area))] ?? $area;
        $propertyType = trim((string) ($args['property_type'] ?? ''));
        $nameWords = array_values(array_filter(explode(' ', (string) ($args['name'] ?? ''))));
        $email = trim((string) ($args['email'] ?? ''));

        // Who LISTS in the area, from the listings side in one pass — the agents with the most live
        // listings there. (An agent-by-agent subquery over every agent timed out on broad words such
        // as "general santos".) Agents BASED there are added so a newcomer with no listings yet still
        // appears. The counts double as `area_listings`, so extractAgent runs no query for them.
        $areaCounts = [];
        $basedIds = [];
        if ($this->areaWords !== []) {
            // Both lookups scan addresses with LIKE, which is the slow part on the production
            // database — so each area's answer is kept for ten minutes.
            $key = md5(implode('|', $this->areaWords).'|'.$propertyType);
            try {
                $areaCounts = \Illuminate\Support\Facades\Cache::remember("asa:area-agents:v1:{$key}", 600, function () use ($propertyType) {
                    $q = DB::table('listings')
                        ->join('properties', 'properties.id', '=', 'listings.property_id')
                        ->join('agents', fn ($j) => $j->on('agents.id', '=', 'listings.agent_id')->where('agents.status', 'active')->whereNull('agents.deleted_at'))
                        ->whereNull('listings.deleted_at')->whereNull('properties.deleted_at')
                        ->where('listings.visibility', 'public')
                        ->where(fn ($w) => $w->whereNull('listings.verification_status')->orWhere('listings.verification_status', '!=', 'flagged'));
                    foreach ($this->areaWords as $w) {
                        $q->where('properties.address', 'LIKE', "%{$w}%");
                    }
                    if ($propertyType !== '') {
                        $q->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
                            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
                            ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
                            ->where('property_types.name', $propertyType);
                    }

                    return $q->groupBy('listings.agent_id')
                        ->orderByDesc('n')
                        ->limit(40)
                        ->pluck(DB::raw('COUNT(*) as n'), 'listings.agent_id')
                        ->map(fn ($n) => (int) $n)
                        ->all();
                });
                $basedIds = \Illuminate\Support\Facades\Cache::remember("asa:area-based:v1:{$key}", 600, function () {
                    $q = DB::table('agents')->where('status', 'active')->whereNull('deleted_at');
                    foreach ($this->areaWords as $w) {
                        $q->where('address', 'LIKE', "%{$w}%");
                    }

                    return $q->orderByDesc('id')->limit(20)->pluck('id')->map(fn ($id) => (int) $id)->all();
                });
            } catch (\Throwable) {
                $areaCounts = [];
                $basedIds = [];
            }
        }

        $query = Agent::withCount('listings')
            // Only surface active agents in public AI search (their listings are
            // already gated by publiclyListed() in the eager-load below).
            ->where('status', 'active')
            ->with(['user', 'listings' => function ($q) {
                $q->publiclyListed()->with(['property.propertyAttribute.subtype.type', 'category'])
                    ->orderBy('clicks', 'DESC')
                    ->limit(6);
            }]);

        if ($this->areaWords !== []) {
            // Candidates by id only — never a LIKE over every agent while listings are being counted.
            $ids = array_values(array_unique(array_merge(array_keys($areaCounts), $basedIds)));
            if ($ids === []) {
                return [];
            }
            $query->whereIn('id', $ids);
        }
        foreach ($nameWords as $w) {
            $query->where(function ($sub) use ($w) {
                $sub->where('first_name', 'LIKE', "%{$w}%")
                    ->orWhere('middle_name', 'LIKE', "%{$w}%")
                    ->orWhere('last_name', 'LIKE', "%{$w}%");
            });
        }
        if ($email !== '') {
            $query->whereHas('user', fn ($u) => $u->where('email', 'LIKE', "%{$email}%"));
        }

        $agents = $query->having('listings_count', '>=', (int) ($args['listings_count'] ?? 0))
            ->orderBy('listings_count', 'DESC')
            ->limit(25)
            ->get();

        $this->natconByEmail = $this->natconAwards($agents->map(fn ($a) => $a->user->email ?? '')->all());

        foreach ($agents as $a) {
            $card = $this->extractAgent($a, $this->areaWords !== [] ? ($areaCounts[$a->id] ?? 0) : null);
            $inquiredAgents[] = $card;
        }

        // Proven performance first: live listings in the asked area weigh most, then how they answer
        // inquiries, then stars and awards. The model picks from the top ten of this order.
        usort($inquiredAgents, fn (array $x, array $y) => self::agentScore($y) <=> self::agentScore($x));

        // Variety at the top: agents within 10% of the best score are equally "best", so the one
        // shown first rotates by day rather than the same name topping every search in an area.
        if (count($inquiredAgents) > 1) {
            $top = self::agentScore($inquiredAgents[0]);
            $tied = [];
            foreach ($inquiredAgents as $i => $a) {
                if ($top > 0 && self::agentScore($a) >= $top * 0.9) {
                    $tied[] = $i;
                }
            }
            if (count($tied) > 1) {
                $pick = $tied[(int) now()->format('z') % count($tied)];
                if ($pick !== 0) {
                    $first = $inquiredAgents[$pick];
                    array_splice($inquiredAgents, $pick, 1);
                    array_unshift($inquiredAgents, $first);
                }
            }
        }

        return array_slice($inquiredAgents, 0, 10);
    }

    /**
     * The cards for given agents, in the given order — the valuation panel's "list it with one of
     * these" — with their live listings in the area and the NATCON badge, as the agent search draws them.
     *
     * @param  array<int, int>  $agentIds
     * @param  array<int, int>  $areaCounts  agent id → live listings in the area
     * @return array<int, array<string, mixed>>
     */
    public function agentCards(array $agentIds, array $areaCounts, string $areaLabel): array
    {
        $agentIds = array_values(array_unique(array_map('intval', $agentIds)));
        if ($agentIds === []) {
            return [];
        }
        try {
            $agents = Agent::withCount('listings')
                ->where('status', 'active')
                ->whereIn('id', $agentIds)
                ->with(['user', 'listings' => function ($q) {
                    $q->publiclyListed()->with(['property.propertyAttribute.subtype.type', 'category'])
                        ->orderBy('clicks', 'DESC')
                        ->limit(6);
                }])
                ->get()
                ->keyBy('id');
            $this->areaLabel = $areaLabel;
            $this->natconByEmail = $this->natconAwards($agents->map(fn ($a) => $a->user->email ?? '')->all());
            $cards = [];
            foreach ($agentIds as $id) {
                $a = $agents->get($id);
                if ($a !== null) {
                    $cards[] = $this->extractAgent($a, (int) ($areaCounts[$id] ?? 0));
                }
            }

            return $cards;
        } catch (\Throwable) {
            return [];
        }
    }

    /** A ranking score from an agent's facts; higher is better. Tuned to put area presence first. */
    private static function agentScore(array $a): float
    {
        // Presence in the asked area dominates; everything else separates agents with similar presence.
        $score = 6.0 * (float) ($a['area_listings'] ?? 0) + 0.1 * (float) ($a['listings_count'] ?? 0);
        if (($a['rating'] ?? null) !== null) {
            $score += 4.0 * (float) $a['rating']['avg'] + min(10, (int) $a['rating']['count']);
        }
        if (($a['response'] ?? null) !== null) {
            // One answered inquiry is not a track record: the response facts count in proportion to the sample, up to ten.
            $weight = min(10, (int) ($a['response']['sample'] ?? 0)) / 10;
            $score += $weight * (0.15 * (float) ($a['response']['within_1h_pct'] ?? 0) - 0.15 * (float) ($a['response']['unanswered_pct'] ?? 0));
        }
        $score += 5.0 * count($a['awards'] ?? []);
        // A NATCON awardee year is the company's own top-performer recognition; two years count, more is the same signal.
        $score += 8.0 * min(2, count($a['natcon'] ?? []));

        return $score;
    }
}