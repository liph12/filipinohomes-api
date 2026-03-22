<?php

namespace App\Http\Controllers;

use App\Http\Resources\ListingResourceCollection;
use Illuminate\Http\Request;
use App\Services\OpenAI\InquiryService;
use App\Models\Listing;
use App\Models\Agent;

class OpenAIController extends Controller
{
    private $sqService;

    public function __construct(InquiryService $s)
    {
        $this->sqService = $s;
    }

    public function getDailyLimit(Request $request)
    {
        $deviceId = $request->input('device_id') ?? 'unknown';
        $ip = $request->ip();
        $identifier = $deviceId . '|' . $ip;

        $dailyKey = 'daily_requests_' . $identifier;
        $dailyLimit = 100; // same as your daily limit

        $currentCount = cache()->get($dailyKey, 0);
        $remaining = max($dailyLimit - $currentCount, 0);

        return response()->json([
            'daily_limit' => $dailyLimit,
            'used' => $currentCount,
            'remaining' => $remaining,
        ]);
    }

    public function streamChat(Request $request)
    {
        $deviceId = $request->input('device_id') ?? 'unknown';
        $ip = $request->ip();
    
        $identifier = $deviceId . '|' . $ip;
    
        $attemptsKey = 'spam_attempts_' . $identifier;
        $blockedKey = 'blocked_' . $identifier;
        $cooldownKey = 'cooldown_' . $identifier;
        $dailyKey = 'daily_requests_' . $identifier;
        $dailyLimit = 100;

        if (cache()->has($blockedKey)) {
            return response()->json([
                'error' => 'You are temporarily blocked'
            ], 403);
        }


        if (!cache()->has($dailyKey)) {
            cache()->put($dailyKey, 0, now()->endOfDay());
        }

        $dailyCount = cache()->increment($dailyKey);

        if ($dailyCount > $dailyLimit) {
            return response()->json([
                'error' => 'Daily limit reached. Please try again tomorrow.'
            ], 429);
        }

        if (cache()->has($cooldownKey)) {

            $attempts = cache()->increment($attemptsKey);

            if ($attempts === 1) {
                cache()->put($attemptsKey, 1, now()->addMinutes(10));
            }
    
            if ($attempts > 5) {
                cache()->put($blockedKey, true, now()->addMinutes(10));
    
                return response()->json([
                    'error' => 'You are temporarily blocked'
                ], 403);
            }
    
            return response()->json([
                'error' => 'Too fast. Please slow down.'
            ], 429);
        }
    
        cache()->put($cooldownKey, true, now()->addSeconds(3));
    
        $thread = $request->messages;
        $classification = $this->sqService->classifyMessage($thread);
        $isNormal = $classification === "normal";
    
        if (!$isNormal) {
            if ($classification === 'listing') {
                return $this->sqService->replySearchingStream($thread);
            }
    
            if ($classification === 'agent') {
                return $this->sqService->replySearchingAgentStream($thread);
            }
        }
    
        return $this->sqService->replyNormal($thread);
    }

    private function extractListing($l)
    {
        $agentName = $l->agent->first_name . " " . $l->agent->last_name;
        $userData = $l->agent->user;
        $attributes = $l->property->propertyAttribute;
        $subType = $attributes->subtype;
        
        return [
            'id' => $l->id,
            'name' => $l->name,
            'photo' => $l->featured_photo,
            'link' => "https://filipinohomes.com/listings/" . $l->slug,
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

    public function extractAgent($a)
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
        
        return [
            'id' => $a->id,
            'name' => $agentName,
            'avatar' => $a->user->avatar,
            'mobile' => $a->user->mobile_no,
            'email' => $a->user->email,
            'address' => $a->address,
            'listings_count' => $a->listings_count,
            'top_listings' => $topListings
        ];
    }

    public function streamMessageRequest(Request $request)
    {
        $message = $request->message;

        return $this->sqService->streamMessage($message);
    }

    public function searchAgents(Request $request)
    {
        $thread = $request->messages;
        $params = $this->sqService->parseAgentQuery($thread);
        $args = $params['arguments'];
        $inquiredAgents = [];

        $agents = Agent::withCount('listings')->where(function ($q) use ($args) {
            $extName = explode(' ', $args['name']);
            $extAddress = explode(' ', $args['address']);

            foreach ($extName as $w) {
                $q->where(function ($sub) use ($w) {
                    $sub->where('first_name', 'LIKE', "%{$w}%")
                    ->orWhere('middle_name', 'LIKE', "%{$w}%")
                    ->orWhere('last_name', 'LIKE', "%{$w}%");
                });
            }
            foreach ($extAddress as $w) {
                $q->where(function ($sub) use ($w) {
                    $sub->where('address', 'LIKE', "%{$w}%");
                });
            }
            $q->with(['user' => function($q) use($args){
                $q->where('email', 'LIKE', "%{$args['email']}%");
            }]);
        })->having('listings_count', '>=', $args['listings_count'])
        ->with(['listings' => function($q){
            $q->with(['property' => function($q){
                $q->with(['propertyAttribute.subtype.type', 'furnishing']);
            }, 'category'])->orderBy('clicks', 'DESC')->limit(5);
        }])
        ->orderBy('listings_count','DESC')->limit(10)->get();

        foreach($agents as $a)
        {
            $inquiredAgents[] = $this->extractAgent($a);
        }

        $suggestedAgents = $this->sqService->suggestedAgents($thread, $inquiredAgents);

        if(isset($suggestedAgents['suggested']) && !empty($suggestedAgents['suggested']))
        {
            $suggested = null;
            $others = [];

            foreach($inquiredAgents as $l)
            {
                if($l['id'] === $suggestedAgents['suggested'])
                {
                    $suggested = $l;
                }
            }

            if(isset($suggestedAgents['others']))
            {
                foreach($suggestedAgents['others'] as $key)
                {
                    foreach($inquiredAgents as $l)
                    {
                        if($l['id'] === $key)
                        {
                            $others[] = $l;
                        }
                    }
                }
            }
    
            return response()->json([
                'message' => $suggestedAgents['message'],
                'suggested' => $suggested,
                'others' => $others,
                'follow_up' => $suggestedAgents['follow_up'],
            ]);
        }

        return response()->json($suggestedAgents);
    }

    public function searchListings(Request $request)
    {
        $thread = $request->messages;
        $params = $this->sqService->parsePropertyQuery($thread);
        $args = $params['arguments'];
        $address = $args['address'];
        $attr = $args['attributes'];
        $inquiredListings = [];

        $listings = Listing::whereHas('category', function($q) use($args){
            if($args['category'] !== "")
            {
                $q->where('name', $args['category']);
            }
        });

        if($args['date_from'] !== "" && $args["date_to"] !== "")
        {
            $listings = $listings->whereBetween('created_at', [$args['date_from'], $args['date_to']]);
        }

        if($attr['price_min'] > 0 || $attr['price_max'] > 0)
        {
            if($attr['price_min'] > $attr['price_max'])
            {
                $listings = $listings->whereBetween('price', [$attr['price_max'], $attr['price_min']]);
            }else{
                $listings = $listings->whereBetween('price', [$attr['price_min'], $attr['price_max']]);
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
        })->whereHas('property', function($q) use($args, $address, $attr){    
            $words = strtolower($args['query_words']);
            $queries = explode(' ', $words);
            $addr = explode(' ', $address);

            $q->where(function ($q) use ($addr) {
                foreach ($addr as $w) {
                    $q->where(function ($sub) use ($w) {
                        $sub->where('address', 'LIKE', "%{$w}%");
                    });
                }
            })->where(function ($q) use ($queries) {
                foreach ($queries as $w) {
                    $q->where(function ($sub) use ($w) {
                        $sub->where('description', 'LIKE', "%{$w}%");
                    });
                }
            })
            ->whereHas('propertyAttribute', function($q) use($attr, $args){
                $q->whereHas('subtype', function($q) use($args){
                    $q->whereHas('type', function($q) use($args){
                        if(!empty($args['property_type']))
                        {
                            $q->where('name', $args['property_type']);
                        }
                    });
                    if(!empty($args['property_subtype']))
                    {
                        $q->where('name', $args['property_subtype']);
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
        })->with(['property' => function($q){
            $q->with(['propertyAttribute.subtype.type', 'furnishing']);
        }, 'category'])->orderBy('clicks', 'DESC')->limit(5)->get();
        
        foreach ($listings as $l) {            
            $inquiredListings[] = $this->extractListing($l);
        }

        // return response()->json([
        //     'args' => $args,
        //     'res' => $inquiredListings,
        // ]);

        $suggestedListings = $this->sqService->suggestedListings($thread, $inquiredListings);

        if(isset($suggestedListings['suggested']) && !empty($suggestedListings['suggested']))
        {
            $suggested = null;
            $others = [];

            foreach($inquiredListings as $l)
            {
                if($l['id'] === $suggestedListings['suggested'])
                {
                    $suggested = $l;
                }
            }

            if(isset($suggestedListings['others']))
            {
                foreach($suggestedListings['others'] as $key)
                {
                    foreach($inquiredListings as $l)
                    {
                        if($l['id'] === $key)
                        {
                            $others[] = $l;
                        }
                    }
                }
            }
    
            return response()->json([
                'message' => $suggestedListings['message'],
                'suggested' => $suggested,
                'others' => $others,
                'follow_up' => $suggestedListings['follow_up'],
            ]);
        }

        return response()->json($suggestedListings);
    }
}
