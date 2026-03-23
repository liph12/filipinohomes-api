<?php

namespace App\Services\OpenAI;

use App\Models\Agent;
use App\Models\Listing;

class DataLayerService
{
    public function __construct()
    {
        // to do
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

    public function getListingsByArguments($args): array
    {
        $address = $args['address'];
        $attr = $args['attributes'];
        $inquiredListings = [];
        $words = strtolower($args['query_words']);
        $array_words = explode(' ', $words);

        $listings = Listing::public()->whereHas('category', function($q) use($args){
            if($args['category'] !== "")
            {
                $q->where('name', $args['category']);
            }
        })->where(function ($q) use ($array_words) {
            $q->where(function ($sub) use ($array_words) {
                foreach ($array_words as $w) {
                    $lower = strtolower($w);
                    $sub->where('name', 'LIKE', "%{$lower}%");
                }
            });
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
        })->whereHas('property', function($q) use($args, $address, $attr, $array_words){    
            $addr = explode(' ', $address);

            $q->where(function ($q) use ($addr) {
                foreach ($addr as $w) {
                    $q->where(function ($sub) use ($w) {
                        $sub->where('address', 'LIKE', "%{$w}%");
                    });
                }
            })->where(function ($q) use ($array_words) {
                foreach ($array_words as $w) {
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
        })->with(['property.propertyAttribute.subtype.type', 'category'])->orderBy('created_at', 'DESC')->limit(5)->get();
        
        foreach ($listings as $l) {            
            $inquiredListings[] = $this->extractListing($l);
        }

        return $inquiredListings;
    }

    public function getAgentsByArguments($args): array
    {
        $inquiredAgents = [];
        $agents = Agent::withCount('listings')
        ->with(['listings' => function($q) {
            $q->public()->with('property.propertyAttribute.subtype.type')
              ->orderBy('clicks', 'DESC')
              ->limit(10);
        }])
        ->where(function ($q) use ($args) {
            $extName = explode(' ', $args['name']);
            $extAddr = explode(' ', $args['agent_address']);

            foreach ($extName as $w) {
                $q->where(function ($sub) use ($w) {
                    $sub->where('first_name', 'LIKE', "%{$w}%")
                    ->orWhere('middle_name', 'LIKE', "%{$w}%")
                    ->orWhere('last_name', 'LIKE', "%{$w}%");
                });
            }
            foreach ($extAddr as $w) {
                $q->where(function ($sub) use ($w) {
                    $sub->where('address', 'LIKE', "%{$w}%");
                });
            }
            $q->with(['user' => function($q) use($args){
                $q->where('email', 'LIKE', "%{$args['email']}%");
            }]);
        })
        ->having('listings_count', '>=', $args['listings_count'])
        ->orderBy('listings_count','DESC')
        ->limit(10)
        ->get();

        foreach($agents as $a)
        {
            $inquiredAgents[] = $this->extractAgent($a);
        }

        return $inquiredAgents;
    }
}