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

    public function __construct(private readonly PriceGuideService $priceGuide, private readonly LandmarkLocator $landmarks)
    {
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

    protected function extractAgent($a)
    {
        $agentName = $a->first_name . " " . $a->last_name;
        $listings = $a->listings;
        $topListings = [];

        foreach($listings as $l)
        {
            $listing = $this->extractListing($l);
            $topListings[] = [
                'type' => $listing['propertyType'],
                'subType' => $listing['propertySubType'],
                'views' => $listing['views'],
                'category' => $listing['category']
            ];
        }
        
        // Proven performance, from our own records. Null where there is no data yet — never a guess.
        $reviews = (int) ($a->total_reviews ?? 0);
        $sample = (int) ($a->response_sample_size ?? 0);
        $medianSec = $a->median_first_response_seconds;
        $awards = \Illuminate\Support\Facades\DB::table('agent_awards')->where('agent_id', $a->id)->orderByDesc('granted_at')->limit(3)->get(['title', 'tier'])
            ->map(fn ($w) => ['title' => (string) $w->title, 'tier' => $w->tier === null ? null : (string) $w->tier])->values()->all();
        $areaListings = null;
        if ($this->areaWords !== []) {
            $words = $this->areaWords;
            $areaListings = (int) Listing::publiclyListed()->where('agent_id', $a->id)
                ->whereHas('property', function ($q) use ($words) {
                    foreach ($words as $w) {
                        $q->where('address', 'LIKE', "%{$w}%");
                    }
                })->count();
        }

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
        ];
    }

    public function getListingsByArguments($args): array
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

        $listings = Listing::publiclyListed()->whereHas('category', function($q) use($args){
            if($args['category'] !== "")
            {
                $q->where('categories.name', $args['category']);
            }
        })->where(function ($q) use ($array_words) {
            $q->where(function ($sub) use ($array_words) {
                foreach ($array_words as $w) {
                    $lower = strtolower($w);
                    $sub->where('listings.name', 'LIKE', "%{$lower}%");
                }
            });
        });

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
        })->whereHas('property', function($q) use($args, $address, $attr, $array_words, $point){    
            $addr = explode(' ', $address);

            // Measuring from a known point, the pin decides the place — the typed address need not
            // name it (a listing 400 m from IT Park may say "Apas", not "Lahug").
            if ($point === null) {
                $q->where(function ($q) use ($addr) {
                    foreach ($addr as $w) {
                        $q->where(function ($sub) use ($w) {
                            $sub->where('properties.address', 'LIKE', "%{$w}%");
                        });
                    }
                });
            }
            $q->where(function ($q) use ($array_words) {
                foreach ($array_words as $w) {
                    $q->where(function ($sub) use ($w) {
                        $sub->where('properties.description', 'LIKE', "%{$w}%");
                    });
                }
            })
            ->whereHas('propertyAttribute', function($q) use($attr, $args){
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
        $this->areaWords = array_values(array_filter(explode(' ', mb_strtolower($area))));
        $this->areaLabel = $area;
        $propertyType = trim((string) ($args['property_type'] ?? ''));

        $agents = Agent::withCount('listings')
        // Only surface active agents in public AI search (their listings are
        // already gated by publiclyListed() in the eager-load below).
        ->where('status', 'active')
        ->with(['listings' => function($q) {
            $q->publiclyListed()->with('property.propertyAttribute.subtype.type')
              ->orderBy('clicks', 'DESC')
              ->limit(10);
        }])
        ->where(function ($q) use ($args, $propertyType) {
            $extName = array_filter(explode(' ', (string) $args['name']));
            $extAddr = array_filter(explode(' ', (string) $args['agent_address']));
            $listAddr = array_filter(explode(' ', (string) ($args['listing_address'] ?? '')));

            foreach ($extName as $w) {
                $q->where(function ($sub) use ($w) {
                    $sub->where('first_name', 'LIKE', "%{$w}%")
                    ->orWhere('middle_name', 'LIKE', "%{$w}%")
                    ->orWhere('last_name', 'LIKE', "%{$w}%");
                });
            }
            // An agent "for Lahug" is one BASED there or one who LISTS there — the second is the one
            // buyers actually want, and the old address-only match missed them.
            $areaWords = $listAddr !== [] ? $listAddr : $extAddr;
            if ($areaWords !== []) {
                $q->where(function ($or) use ($areaWords, $propertyType) {
                    $or->where(function ($based) use ($areaWords) {
                        foreach ($areaWords as $w) {
                            $based->where('address', 'LIKE', "%{$w}%");
                        }
                    })->orWhereHas('listings', function ($l) use ($areaWords, $propertyType) {
                        $l->publiclyListed()->whereHas('property', function ($p) use ($areaWords, $propertyType) {
                            foreach ($areaWords as $w) {
                                $p->where('address', 'LIKE', "%{$w}%");
                            }
                            if ($propertyType !== '') {
                                $p->whereHas('propertyAttribute.subtype.type', fn ($t) => $t->where('name', $propertyType));
                            }
                        });
                    });
                });
            }
            $q->with(['user' => function($q) use($args){
                $q->where('email', 'LIKE', "%{$args['email']}%");
            }]);
        })
        ->having('listings_count', '>=', $args['listings_count'])
        ->orderBy('listings_count','DESC')
        ->limit(25)
        ->get();

        foreach($agents as $a)
        {
            $inquiredAgents[] = $this->extractAgent($a);
        }

        // Proven performance first: live listings in the asked area weigh most, then how they answer
        // inquiries, then stars and awards. The model picks from the top ten of this order.
        usort($inquiredAgents, fn (array $x, array $y) => self::agentScore($y) <=> self::agentScore($x));

        return array_slice($inquiredAgents, 0, 10);
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

        return $score;
    }
}