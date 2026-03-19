<?php

namespace App\Http\Controllers;

use App\Http\Resources\ListingResourceCollection;
use Illuminate\Http\Request;
use App\Services\OpenAI\InquiryService;
use App\Models\Listing;

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
        $agentName = $l['agent']['first_name']." ".$l['agent']['last_name'];
        $userData = $l['agent']['user'];

        return [
            'id' => $l['id'],
            'name' => $l['name'],
            'photo' => $l['featured_photo'],
            'link' => "https://filipinohomes.com/listings/" . $l['slug'],
            'propertyName' => $l['property']['name'] ?? "",
            'address' => $l['property']['address'] ?? "",
            'description' => $l['property']['description'] ?? "",
            'furnishing' => $l['property']['furnishing']['name'] ?? "",
            'category' => $l['category']['name'] ?? "",
            'price' => $l['price'] ?? 0,
            'agent' => $agentName,
            'agentAvatar' => $userData['avatar'],
            'agentMobile' => $userData['mobile_no'],
            'agentEmail' => $userData['email']
        ];
    }

    public function streamMessageRequest(Request $request)
    {
        $message = $request->message;

        return $this->sqService->streamMessage($message);
    }

    public function searchListings(Request $request)
    {
        $thread = $request->messages;
        $params = $this->sqService->parsePropertyQuery($thread);
        $args = $params['arguments'];
        $address = $args['address'];
        $attr = $args['attributes'];

        $listings = Listing::whereHas('category', function($q) use($args){
            $q->where('name', $args['category']);
        })->whereHas('property', function($q) use($args, $address, $attr){                
            $q->where('address', 'LIKE', '%'.$address.'%')
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
        })->limit(3)->get();

        $listingArray = (new ListingResourceCollection($listings))->toArray(request());
        $inquiredListings = [];
        
        foreach ($listingArray as $l) {            
            $inquiredListings[] = $this->extractListing($l);
        }

        $suggestedListings = $this->sqService->suggestedListing($thread, $listingArray);

        if(!empty($suggestedListings['suggested']))
        {
            $suggested = null;
            $others = [];

            foreach($listingArray as $l)
            {
                if($l['id'] === $suggestedListings['suggested'])
                {
                    $suggested = $this->extractListing($l);
                }
            }
    
            foreach($suggestedListings['others'] as $key)
            {
                foreach($listingArray as $l)
                {
                    if($l['id'] === $key)
                    {
                        $others[] = $this->extractListing($l);
                    }
                }
            }
    
            return response()->json([
                'message' => $suggestedListings['message'],
                'suggested' => $suggested,
                'others' => $others
            ]);
        }

        return response()->json($suggestedListings);
    }
}
