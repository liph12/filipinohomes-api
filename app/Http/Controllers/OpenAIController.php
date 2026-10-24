<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\OpenAI\CommandService;
use App\Services\OpenAI\CacheService;
use App\Services\OpenAI\DataLayerService;
use App\Services\OpenAI\ListingCommandService;
use App\Services\OpenAI\PriceCommandService;
use App\Services\OpenAI\DealCommandService;
use App\Services\OpenAI\AreaCommandService;
use App\Models\Barangay;
use Illuminate\Support\Facades\Http;
use App\Models\AiSearchLog;
use Illuminate\Support\Facades\Auth;

class OpenAIController extends Controller
{
    private $sqService;
    private $cacheService;
    private $dataService;
    private $listingService;
    private $priceService;
    private $dealService;
    private $areaService;

    public function __construct(CommandService $s, CacheService $c, DataLayerService $d, ListingCommandService $l, PriceCommandService $p, DealCommandService $deal, AreaCommandService $area)
    {
        $this->sqService = $s;
        $this->cacheService = $c;
        $this->dataService = $d;
        $this->listingService = $l;
        $this->priceService = $p;
        $this->dealService = $deal;
        $this->areaService = $area;
    }

    public function getDailyLimit(Request $request)
    {
        $data = $this->cacheService->dailyLimit($request);

        return response()->json($data);
    }

    public function getDailyLimitCreate(Request $request)
    {
        $data = $this->cacheService->dailyLimit($request, 'create');

        return response()->json($data);
    }

    public function getDailyLimitCreateText(Request $request)
    {
        $data = $this->cacheService->dailyLimit($request, 'create_text');

        return response()->json($data);
    }

    public function getCachedMessages(Request $request)
    {
        $cachedMessages = $this->cacheService->getDailyMessages($request);

        return response()->json($cachedMessages);
    }

    public function clearCachedMessages(Request $request)
    {
        $this->cacheService->clearMessages($request);

        return response()->json(['message' => 'Messaged cleared!']);
    }

    public function streamChat(Request $request)
    {
        $thread = $request->messages;
        $newMessages = $request->input('messages', []);
        $limitResponse = $this->cacheService->updateDailyLimit($request);

        if ($limitResponse->getStatusCode() !== 200) {
            return $limitResponse;
        }

        $this->cacheService->appendMessages($request, $newMessages);

        // "Is this a good deal? listing #123" — the card's button. Deterministic: no classifier, no model.
        $last = collect($thread)->where('role', 'user')->last();
        if (is_array($last) && preg_match('/\blisting\s*#\s*(\d+)/i', (string) ($last['content'] ?? ''), $m)) {
            return $this->dealService->replyStream((int) $m[1], $thread, $request);
        }

        $classification = $this->sqService->classifyMessage($thread);
        $isNormal = $classification === "normal";
    
        if (!$isNormal) {
            if ($classification === 'listing') {
                return $this->sqService->replySearchingStream($thread, $request);
            }
    
            if ($classification === 'agent') {
                return $this->sqService->replySearchingAgentStream($thread, $request);
            }

            // "How much is a studio in Lahug?" — the Price Guide, in chat (numbers from our listings, never the model).
            if ($classification === 'price') {
                return $this->priceService->replyStream($thread, $request);
            }

            // "Tell me about Lahug" — the area at a glance, from our listings.
            if ($classification === 'area') {
                return $this->areaService->replyStream($thread, $request);
            }
        }
    
        return $this->sqService->replyNormal($thread, $request);
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
        $inquiredAgents = $this->dataService->getAgentsByArguments($args);

        // return response()->json([
        //     'args' => $args,
        //     'res' => $inquiredAgents,
        // ]);

        $suggestedAgents = $this->sqService->suggestedAgents($thread, $inquiredAgents, $request);

        if(isset($suggestedAgents['suggested']) && !empty($suggestedAgents['suggested']))
        {
            $suggested = null;
            $others = [];

            foreach($inquiredAgents as $l)
            {
                if((string) $l['id'] === (string) $suggestedAgents['suggested'])
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
                        if((string) $l['id'] === (string) $key)
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
        $inquiredListings = $this->dataService->getListingsByArguments($args);

        // return response()->json([
        //     'args' => $args,
        //     'res' => $inquiredListings,
        // ]);

        $suggestedListings = $this->sqService->suggestedListings($thread, $inquiredListings, $request);

        if(isset($suggestedListings['suggested']) && !empty($suggestedListings['suggested']))
        {
            $suggested = null;
            $others = [];

            foreach($inquiredListings as $l)
            {
                if((string) $l['id'] === (string) $suggestedListings['suggested'])
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
                        if((string) $l['id'] === (string) $key)
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

    public function parseListingQuery(Request $request)
    {
        $limitResponse = $this->cacheService->updateDailyLimit($request);

        if ($limitResponse->getStatusCode() !== 200) {
            return $limitResponse;
        }

        $userId = Auth::user()->id;
        $userLog = AiSearchLog::where('user_id', $userId)->first();
        $mon = date('Y-m');

        if($userLog)
        {
            if($mon === $userLog->month)
            {
                $searches = $userLog->searches;
                $searches[] = $request->q;
                $userLog->searches = $searches;
                $userLog->save();
            }
        }else{
            $log = [
                'user_id' => Auth::user()->id,
                'searches' => [$request->q],
                'month' => $mon,
            ];
            AiSearchLog::create($log);
        }

        $data = $this->listingService->parseListingQuery($request->q ?? "");

        return response()->json($data);
    }

    /**
     * Unified "Improve Title" endpoint. Replaces the separate analyze-title
     * and suggest-titles endpoints — the frontend calls this with an optional
     * `title` and the service picks the right mode based on word count.
     *
     * Response shape (always):
     *   { suggestions: string[], mode: 'analyze'|'generate', score?: number, feedback?: string }
     */
    public function improveListingTitle(Request $request)
    {
        $limitResponse = $this->cacheService->updateDailyLimit($request, 'create_text');
        if ($limitResponse->getStatusCode() !== 200) {
            return $limitResponse;
        }

        try {
            $title = $request->input('title', '');
            $context = $request->only([
                'category', 'property_type', 'property_subtype',
                'project_name', 'project_location',
                'bedrooms', 'bathrooms', 'parking',
                'floor_area', 'lot_area',
                'price', 'furnishing',
                'amenities',
                'address', 'address_id', 'barangay', 'city', 'province',
                'nearby_facilities',
                'photo_keywords',
                'description',
                'preferred_intent',
                'preferred_keywords',
            ]);

            // Resolve structured location from address_id (the barangay FK)
            // when the frontend can't supply names directly. The wizard's
            // formData stores only the barangay ID, so without this fallback
            // the title scorer would only get the raw address string and the
            // location_strength axis would underscore by ~22 points.
            $addressId = $request->input('address_id');
            if ($addressId && (empty($context['barangay']) || empty($context['city']) || empty($context['province']))) {
                $barangay = Barangay::with('city.province')->find($addressId);
                if ($barangay) {
                    if (empty($context['barangay']) && $barangay->name) $context['barangay'] = $barangay->name;
                    if (empty($context['city']) && $barangay->city?->name) $context['city'] = $barangay->city->name;
                    if (empty($context['province']) && $barangay->city?->province?->name) $context['province'] = $barangay->city->province->name;
                }
            }

            $result = $this->listingService->improveTitle($title, $context);

            if (!$result || empty($result['suggestions'])) {
                return response()->json(['message' => 'Failed to generate title suggestions.'], 502);
            }

            return response()->json($result);
        } catch (\OpenAI\Exceptions\RateLimitException $e) {
            return response()->json([
                'message' => 'AI service is temporarily busy. Please try again in a moment.',
            ], 429);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to improve title. Please try again.',
            ], 500);
        }
    }

    public function classifyListingPhotos(Request $request)
    {
        $photos = $request->input('photos', []);

        $limitResponse = $this->cacheService->updateDailyLimit($request, 'create');
        if ($limitResponse->getStatusCode() !== 200) {
            return $limitResponse;
        }

        try {
            $classifications = $this->listingService->classifyImages($photos);
            return response()->json($classifications);
        } catch (\OpenAI\Exceptions\RateLimitException $e) {
            return response()->json([
                'message' => 'AI service is temporarily busy. Please try again in a moment.',
            ], 429);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to classify photos. Please try again.',
            ], 500);
        }
    }
}
