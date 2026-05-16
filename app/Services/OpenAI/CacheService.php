<?php

namespace App\Services\OpenAI;

use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Services\OpenAI\DataLayerService;
use Illuminate\Support\Str;

class CacheService extends DataLayerService
{
    public function __construct()
    {
        // to do
    }
    
    public function updateDailyLimit(Request $request, $type = 'user')
    {
        $deviceId = $request->input('device_id') ?? 'unknown';
        $ip = $request->ip();
    
        $guestIdentifier = 'guest_' . $deviceId . '|' . $ip;
        $user = Auth::guard('sanctum')->user();
        $guestLimit = config('openai.guest_limit');
        $authLimit = match ($type) {
            'create'      => config('openai.auth_limit_create'),
            'create_text' => config('openai.auth_limit_create_text'),
            default       => config('openai.auth_limit'),
        };
    
        if ($user) {
            $identifier = $type.'_' . $user->id;
            $dailyLimit = $authLimit;
    
            $userKey = 'daily_requests_' . $identifier;
            $guestKey = 'daily_requests_' . $guestIdentifier;
    
            if (!cache()->has($userKey) && cache()->has($guestKey)) {
                $guestCount = cache()->get($guestKey, 0);
                cache()->put($userKey, $guestCount, now()->endOfDay());
                cache()->forget($guestKey);
            }
    
            $dailyKey = $userKey;
        } else {
            $identifier = $guestIdentifier;
            $dailyLimit = $guestLimit;
            $dailyKey = 'daily_requests_' . $identifier;
        }
    
        $blockedKey = 'blocked_' . $identifier;
        $cooldownKey = 'cooldown_' . $identifier;
        $attemptsKey = 'spam_attempts_' . $identifier;
    
        if (cache()->has($blockedKey)) {
            return response()->json([
                'status' => 'blocked',
                'message' => 'You are temporarily blocked due to spam.',
            ], 403);
        }
    
        if (!cache()->has($dailyKey)) {
            cache()->put($dailyKey, 0, now()->endOfDay());
        }

        $dailyCount = cache()->increment($dailyKey);

        // Admins bypass every limit (daily cap, cooldown, spam-block). Counter
        // still increments so the UI shows their real usage; the `unlimited`
        // flag tells the frontend the cap doesn't apply.
        if ($this->isAdmin($request)) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Request allowed (admin bypass).',
                'limit' => $dailyLimit,
                'used' => $dailyCount,
                'remaining' => max(0, $dailyLimit - $dailyCount),
                'unlimited' => true,
            ], 200);
        }

        if ($dailyCount > $dailyLimit) {
            return response()->json([
                'status' => 'limit_exceeded',
                'message' => 'Daily request limit reached.',
                'limit' => $dailyLimit,
                'used' => $dailyCount,
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
                    'status' => 'blocked',
                    'message' => 'Too many rapid requests. You are temporarily blocked.',
                ], 403);
            }
    
            return response()->json([
                'status' => 'cooldown',
                'message' => 'You are sending requests too fast.',
                'retry_after_seconds' => 3,
                'attempts' => $attempts,
            ], 429);
        }
    
        cache()->put($cooldownKey, true, now()->addSeconds(3));
    
        return response()->json([
            'status' => 'ok',
            'message' => 'Request allowed.',
            'limit' => $dailyLimit,
            'used' => $dailyCount,
            'remaining' => max(0, $dailyLimit - $dailyCount),
        ], 200);
    }

    public function dailyLimit(Request $request, $type = 'user')
    {
        $guestLimit = config('openai.guest_limit');
        $authLimit = match ($type) {
            'create'      => config('openai.auth_limit_create'),
            'create_text' => config('openai.auth_limit_create_text'),
            default       => config('openai.auth_limit'),
        };

        $user = Auth::guard('sanctum')->user();
    
        if ($user) {
            $identifier = $type.'_' . $user->id;
            $dailyLimit = $authLimit;
        } else {
            $deviceId = $request->input('device_id') ?? 'unknown';
            $ip = $request->ip();
            $identifier = 'guest_' . $deviceId . '|' . $ip;
            $dailyLimit = $guestLimit;
        }
    
        $dailyKey = 'daily_requests_' . $identifier;

        $currentCount = cache()->get($dailyKey, 0);
        $remaining = max($dailyLimit - $currentCount, 0);
        $isAdmin = $this->isAdmin($request);

        return [
            'limit' => $dailyLimit,
            // Admins see their real usage past the cap; everyone else gets clamped
            // so the pill doesn't display values like "12/5".
            'used' => $isAdmin ? $currentCount : ($currentCount > $dailyLimit ? $dailyLimit : $currentCount),
            'remaining' => $remaining,
            'unlimited' => $isAdmin,
        ];
    }

    /**
     * Resolve the request user (via Sanctum guard, default guard, or by parsing
     * the Bearer token from `personal_access_tokens` for routes outside
     * `auth:sanctum` middleware) and check if they're an admin.
     */
    private function isAdmin(Request $request): bool
    {
        $user = $request->user('sanctum') ?? $request->user();
        if (!$user && $token = $request->bearerToken()) {
            $user = \Laravel\Sanctum\PersonalAccessToken::findToken($token)?->tokenable;
        }
        return $user?->role?->name === 'admin';
    }

    public function getDailyMessages(Request $request)
    {
        $deviceId = $request->input('device_id') ?? 'unknown';
        $user = Auth::guard('sanctum')->user();
        $identifier = $user ? 'user_'.$user->id : 'guest_'.$deviceId;
    
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');
        $sessionKey = 'chat_session_'.$identifier.'_'.$today;
        $sessionKeyYesterday = 'chat_session_'.$identifier.'_'.$yesterday;

        cache()->forget($sessionKeyYesterday);
    
        $chatData = cache()->get($sessionKey, [
            'messages' => [],
            'listings' => [],
            'agents' => [],
        ]);
    
        $listingMap = collect($chatData['listings'])->keyBy('id');
        $agentMap = collect($chatData['agents'])->keyBy('id');
    
        $messages = collect($chatData['messages'])->map(function ($msg) use ($listingMap, $agentMap) {
    
            // Hydrate listings
            if (isset($msg['metaData']['listing'])) {
                $listingMeta = $msg['metaData']['listing'];
    
                $msg['metaData']['listing'] = [
                    'suggested' => isset($listingMeta['suggested']['id'])
                        ? $listingMap->get($listingMeta['suggested']['id'])
                        : null,
    
                    'others' => isset($listingMeta['others'])
                        ? collect($listingMeta['others'])
                            ->map(fn ($l) => $listingMap->get($l['id']))
                            ->filter()
                            ->values()
                            ->toArray()
                        : [],
                ];
            }
    
            // Hydrate agents
            if (isset($msg['metaData']['agent'])) {
                $agentMeta = $msg['metaData']['agent'];
    
                $msg['metaData']['agent'] = [
                    'suggested' => isset($agentMeta['suggested']['id'])
                        ? $agentMap->get($agentMeta['suggested']['id'])
                        : null,
    
                    'others' => isset($agentMeta['others'])
                        ? collect($agentMeta['others'])
                            ->map(fn ($a) => $agentMap->get($a['id']))
                            ->filter()
                            ->values()
                            ->toArray()
                        : [],
                ];
            }
    
            return $msg;
        })->toArray();
    
        return $messages;
    }

    public function clearMessages(Request $request)
    {
        $deviceId = $request->input('device_id') ?? 'unknown';
        $user = Auth::guard('sanctum')->user();
        $identifier = $user ? 'user_'.$user->id : 'guest_'.$deviceId;
    
        $today = now()->format('Y-m-d');
        $sessionKey = 'chat_session_'.$identifier.'_'.$today;

        cache()->forget($sessionKey);
    }

    public function appendMessages(Request $request, $newMessages)
    {
        $deviceId = $request->input('device_id') ?? 'unknown';
        $user = Auth::guard('sanctum')->user();
        $identifier = $user ? 'user_'.$user->id : 'guest_'.$deviceId;
    
        $today = now()->format('Y-m-d');
        $sessionKey = 'chat_session_'.$identifier.'_'.$today;
    
        // Get existing daily chat
        $chatData = cache()->get($sessionKey, [
            'messages' => [],
            'listings' => [], // store full listing objects
            'agents' => [],   // store full agent objects
        ]);
        $existingMessageIds = collect($chatData['messages'])->pluck('id')->toArray();
         // array of Message objects
    
        foreach ($newMessages as $msg) {

            if (!isset($msg['id'])) {
                $msg['id'] = (string) Str::uuid();
            }

            if (!isset($msg['id']) || in_array($msg['id'], $existingMessageIds)) {
                continue; // skip invalid or duplicate
            }
    
            // Fetch full listings if metaData has IDs
            if (isset($msg['metaData']['listing'])) {
                $listingIds = [];
    
                if (isset($msg['metaData']['listing']['suggested']['id'])) {
                    $listingIds[] = $msg['metaData']['listing']['suggested']['id'];
                }
                if (isset($msg['metaData']['listing']['others'])) {
                    $listingIds = array_merge(
                        $listingIds,
                        array_column($msg['metaData']['listing']['others'], 'id')
                    );
                }
    
                // Get listings from DB
                $listings = \App\Models\Listing::whereIn('id', $listingIds)->get();
    
                // Merge new listings into cache, avoid duplicates
                $existingListingIds = collect($chatData['listings'])->pluck('id')->toArray();
                foreach ($listings as $l) {
                    if (!in_array($l->id, $existingListingIds)) {
                        $chatData['listings'][] = $this->extractListing($l);
                    }
                }
            }
    
            if (isset($msg['metaData']['agent'])) {
                $agentIds = [];
    
                if (isset($msg['metaData']['agent']['suggested']['id'])) {
                    $agentIds[] = $msg['metaData']['agent']['suggested']['id'];
                }
                if (isset($msg['metaData']['agent']['others'])) {
                    $agentIds = array_merge(
                        $agentIds,
                        array_column($msg['metaData']['agent']['others'], 'id')
                    );
                }
    
                $agents = \App\Models\Agent::withCount('listings')->with(['listings' => function($q) {
                    $q->with('property.propertyAttribute.subtype.type')
                      ->orderBy('clicks', 'DESC')
                      ->limit(10);
                }])->whereIn('id', $agentIds)->get();
    
                $existingAgentIds = collect($chatData['agents'])->pluck('id')->toArray();
                foreach ($agents as $a) {
                    if (!in_array($a->id, $existingAgentIds)) {
                        $chatData['agents'][] = $this->extractAgent($a);
                    }
                }
            }
    
            $chatData['messages'][] = $msg;
        }
    
        $chatData['messages'] = array_slice($chatData['messages'], -100);
    
        cache()->put($sessionKey, $chatData, now()->endOfDay());
    
        return [
            'success' => true,
            'totalMessages' => count($chatData['messages']),
            'totalListings' => count($chatData['listings']),
            'totalAgents' => count($chatData['agents']),
        ];
    }
}