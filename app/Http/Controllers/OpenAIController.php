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
use App\Services\OpenAI\CompareCommandService;
use App\Services\OpenAI\ProjectCommandService;
use App\Services\OpenAI\LeadService;
use App\Services\OpenAI\AffordCommandService;
use App\Services\OpenAI\AsaQuestionLog;
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
    private $compareService;
    private $projectService;
    private $leadService;
    private $affordService;

    public function __construct(CommandService $s, CacheService $c, DataLayerService $d, ListingCommandService $l, PriceCommandService $p, DealCommandService $deal, AreaCommandService $area, CompareCommandService $compare, ProjectCommandService $project, LeadService $lead, AffordCommandService $afford)
    {
        $this->affordService = $afford;
        $this->sqService = $s;
        $this->cacheService = $c;
        $this->dataService = $d;
        $this->listingService = $l;
        $this->priceService = $p;
        $this->dealService = $deal;
        $this->areaService = $area;
        $this->compareService = $compare;
        $this->projectService = $project;
        $this->leadService = $lead;
    }

    /**
     * "Ask the agent" / "Book a viewing" from a card: a lead for the listing's agent with the
     * conversation attached. Guest-token route; the device id scopes the thread like the chat.
     */
    public function lead(Request $request)
    {
        $data = $request->validate([
            'listing_id' => 'required|integer',
            'name' => 'required|string|max:120',
            'email' => 'nullable|email|max:255',
            'mobile' => 'nullable|string|max:40',
            'kind' => 'required|string|in:viewing,question',
            'preferred_at' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:1000',
            'device_id' => 'nullable|string|max:120',
        ]);
        if (empty($data['email']) && empty($data['mobile'])) {
            return response()->json(['message' => 'Leave a mobile number or an email so the agent can reach you.'], 422);
        }
        $data += ['email' => null, 'mobile' => null, 'preferred_at' => null, 'note' => null];

        return response()->json($this->leadService->create($request, $data), 201);
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

    /** "Forget my preferences" — the remembered buyer profile only; the chat stays. */
    public function clearProfile(Request $request)
    {
        $this->cacheService->clearProfile($request);

        return response()->json(['message' => 'Preferences forgotten.']);
    }

    public function clearCachedMessages(Request $request)
    {
        $this->cacheService->clearProfile($request);
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

        // Card buttons speak in listing ids and are routed on them — no classifier, no model:
        // "Compare listing #12 and listing #34" (two or more ids) or "Is this a good deal? listing #123".
        $last = collect($thread)->where('role', 'user')->last();
        $question = is_array($last) ? (string) ($last['content'] ?? '') : '';
        if (is_array($last) && preg_match_all('/\blisting\s*#\s*(\d+)/i', $question, $mm) && $mm[1] !== []) {
            $ids = array_values(array_unique(array_map('intval', $mm[1])));
            if (count($ids) >= 2) {
                AsaQuestionLog::start($request, $question, 'compare');

                return $this->compareService->replyStream($ids, $thread, $request);
            }
            AsaQuestionLog::start($request, $question, 'deal');

            return $this->dealService->replyStream($ids[0], $thread, $request);
        }

        $classification = $this->sqService->classifyMessage($thread);
        AsaQuestionLog::start($request, $question, $classification);
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

            // "Tell me about Avida Towers Riala" / "Riala vs 38 Park Avenue" — a development, from its listings.
            if ($classification === 'project') {
                return $this->projectService->replyStream($thread, $request);
            }

            // "What can I afford at 25k a month?" — a budget turned into a price ceiling, by arithmetic.
            if ($classification === 'afford') {
                return $this->affordService->replyStream($thread, $request);
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
        $place = trim((string) ($args['listing_address'] ?? '')) ?: trim((string) ($args['agent_address'] ?? ''));

        if ($inquiredAgents === []) {
            AsaQuestionLog::outcomeLatest($request, 'agent', 'no_results', $place);
            $where = $place !== '' ? " in **{$place}**" : '';

            return response()->json([
                'message' => "I couldn't find an agent{$where} with live listings yet. Try the nearest city, or tell me the kind of property and I'll look wider.",
                'suggested' => null,
                'others' => [],
                'follow_up' => null,
            ]);
        }

        // The cards and the write-up come straight from the ranked facts — no second model call, so
        // the answer lands in a second or two instead of ten. The ranking already decided the order.
        $suggested = $inquiredAgents[0];
        $others = array_slice($inquiredAgents, 1, 4);
        $areaLabel = $suggested['area_label'] ?? $place;
        $facts = array_filter([
            ($suggested['area_listings'] ?? 0) > 0 ? sprintf('**%d live %s in %s**', $suggested['area_listings'], $suggested['area_listings'] === 1 ? 'listing' : 'listings', $areaLabel) : sprintf('**%d live listings**', $suggested['listings_count']),
            isset($suggested['response']['median_minutes']) && $suggested['response']['median_minutes'] !== null ? ($suggested['response']['median_minutes'] < 60 ? sprintf('replies in about %d minutes', $suggested['response']['median_minutes']) : sprintf('replies in about %d hours', (int) round($suggested['response']['median_minutes'] / 60))) : null,
            ! empty($suggested['rating']) ? sprintf('%s★ from %d %s', number_format($suggested['rating']['avg'], 1), $suggested['rating']['count'], $suggested['rating']['count'] === 1 ? 'review' : 'reviews') : null,
            ! empty($suggested['natcon']) ? sprintf('NATCON %s awardee', implode(' · ', array_unique(array_map(fn ($n) => (string) $n['year'], $suggested['natcon'])))) : null,
            ! empty($suggested['years_active']) ? sprintf('%d years with Filipino Homes', $suggested['years_active']) : null,
        ]);
        $message = sprintf('**%s** is the strongest match%s: %s.', $suggested['name'], $areaLabel ? " for {$areaLabel}" : '', implode(', ', $facts));
        if ($others !== []) {
            $message .= ' Also worth a look: '.implode(', ', array_map(fn ($a) => sprintf('**%s** (%d%s)', $a['name'], ($a['area_listings'] ?? 0) > 0 ? $a['area_listings'] : $a['listings_count'], ($a['area_listings'] ?? 0) > 0 ? ' there' : ' listings'), $others)).'.';
        }
        $followUp = sprintf('Tap **Ask the agent** on a card, or say "show me %s listings" to see what they have.', $suggested['name']);

        $this->cacheService->appendMessages($request, [
            ['id' => (string) \Illuminate\Support\Str::uuid(), 'role' => 'assistant', 'content' => $message],
            ['id' => (string) \Illuminate\Support\Str::uuid(), 'role' => 'assistant', 'content' => null, 'metaData' => ['agent' => ['suggested' => $suggested, 'others' => $others]]],
            ['id' => (string) \Illuminate\Support\Str::uuid(), 'role' => 'assistant', 'content' => $followUp],
        ]);
        AsaQuestionLog::outcomeLatest($request, 'agent', 'answered', $place);

        return response()->json([
            'message' => $message,
            'suggested' => $suggested,
            'others' => $others,
            'follow_up' => $followUp,
        ]);
    }

    public function searchListings(Request $request)
    {
        $thread = $request->messages;
        $params = $this->sqService->parsePropertyQuery($thread);
        // What this search left unsaid comes from the buyer's remembered preferences; what it said
        // is remembered for next time. The reply carries the profile so the page can show it.
        $applied = $this->cacheService->applyProfile($request, $params['arguments']);
        $args = $applied['args'];
        $profile = $applied['profile'];
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
    
            AsaQuestionLog::outcomeLatest($request, 'listing', 'answered', (string) ($args['address'] ?? ''));

            return response()->json([
                'profile' => $profile,
                'message' => $suggestedListings['message'],
                'suggested' => $suggested,
                'others' => $others,
                'follow_up' => $suggestedListings['follow_up'],
            ]);
        }

        AsaQuestionLog::outcomeLatest($request, 'listing', 'no_results', (string) ($args['address'] ?? ''));

        return response()->json(['profile' => $profile] + $suggestedListings);
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
