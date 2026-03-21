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

    public function streamChat(Request $request)
    {
        $thread = $request->messages;
        $classification = $this->sqService->classifyMessage($thread);
        $isNormal = $classification === "normal";

        if(!$isNormal)
        {
            return $this->sqService->replySearchingStream($thread);
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
            'agent' => $agentName,
            'agentAvatar' => $userData->avatar ?? null,
            'agentMobile' => $userData->mobile_no ?? null,
            'agentEmail' => $userData->email ?? null
        ];
    }

    public function streamMessageRequest(Request $request)
    {
        $message = $request->message;

        return $this->sqService->streamMessage($message);
    }

    public function searchAgents(Request $request)
    {
        $address = $request->address;
        $agents = Agent::withCount('listings')->where([
            ['address', 'LIKE', '%'.$address.'%']
        ])->orderBy('listings_count','DESC')->limit(5)->get();
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
            $q->where('name', $args['category']);
        });

        if($attr['price_min'] > 0 || $attr['price_max'] > 0)
        {
            if($attr['price_min'] > $attr['price_max'])
            {
                $listings = $listings->whereBetween('price', [$attr['price_max'], $attr['price_min']]);
            }else{
                $listings = $listings->whereBetween('price', [$attr['price_min'], $attr['price_max']]);
            }
        }
        
        $listings = $listings->whereHas('property', function($q) use($args, $address, $attr){    
            $word = strtolower($args['query_word']);
            $address = strtolower($args['address']);
            $addr = explode(' ', $address);

            $q->where(function ($q) use ($addr) {
                foreach ($addr as $w) {
                    $q->where(function ($sub) use ($w) {
                        $sub->where('address', 'LIKE', "%{$w}%");
                    });
                }
            })->where('description', 'LIKE', "%{$word}%")
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
        }, 'category'])->orderBy('clicks', 'DESC')->limit(3)->get();
        
        foreach ($listings as $l) {            
            $inquiredListings[] = $this->extractListing($l);
        }

        // return response()->json([
        //     'args' => $args,
        //     'res' => $inquiredListings,
        // ]);

        $suggestedListings = $this->sqService->suggestedListing($thread, $inquiredListings);

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
