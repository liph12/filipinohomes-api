<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\OpenAI\CommandService;
use App\Services\OpenAI\CacheService;
use App\Services\OpenAI\DataLayerService;
use App\Services\OpenAI\ListingCommandService;
use Illuminate\Support\Facades\Http;
use App\Models\AiSearchLog;
use Illuminate\Support\Facades\Auth;

class OpenAIController extends Controller
{
    private $sqService;
    private $cacheService;
    private $dataService;
    private $listingService;

    public function __construct(CommandService $s, CacheService $c, DataLayerService $d, ListingCommandService $l)
    {
        $this->sqService = $s;
        $this->cacheService = $c;
        $this->dataService = $d;
        $this->listingService = $l;
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
        $classification = $this->sqService->classifyMessage($thread);
        $isNormal = $classification === "normal";
    
        if (!$isNormal) {
            if ($classification === 'listing') {
                return $this->sqService->replySearchingStream($thread, $request);
            }
    
            if ($classification === 'agent') {
                return $this->sqService->replySearchingAgentStream($thread, $request);
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

    public function analyzeListingTitle(Request $request)
    {
        $title = $request->input('title', '');

        if (strlen(trim($title)) < 3) {
            return response()->json(['error' => 'Title is too short to analyze.'], 422);
        }

        $user = Auth::guard('sanctum')->user();
        if (!$user || optional($user->role)->name !== 'admin') {
            $limitResponse = $this->cacheService->updateDailyLimit($request, 'create');
            if ($limitResponse->getStatusCode() !== 200) {
                return $limitResponse;
            }
        }

        try {
            $context = $request->only([
                'property_type', 'property_subtype', 'category',
                'project_name', 'project_location',
                'bedrooms', 'bathrooms', 'floor_area', 'lot_area',
                'description',
            ]);
            $result = $this->listingService->analyzeTitle($title, $context);

            return response()->json($result);
        } catch (\OpenAI\Exceptions\RateLimitException $e) {
            return response()->json([
                'message' => 'AI service is temporarily busy. Please try again in a moment.',
            ], 429);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to analyze title. Please try again.',
            ], 500);
        }
    }

    public function classifyListingPhotos(Request $request)
    {
        $photos = $request->input('photos', []);

        $user = Auth::guard('sanctum')->user();
        if (!$user || optional($user->role)->name !== 'admin') {
            $limitResponse = $this->cacheService->updateDailyLimit($request, 'create');
            if ($limitResponse->getStatusCode() !== 200) {
                return $limitResponse;
            }
        }

        $classifications = $this->listingService->classifyImages($photos);

        return response()->json($classifications);
    }
}
