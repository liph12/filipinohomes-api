<?php

namespace App\Http\Controllers;

use App\Models\Visit;
use App\Support\ChannelMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VisitController extends Controller
{
    /**
     * Public acquisition ping. The web fires this once per session with the
     * referrer + utm params; we resolve a channel and store one row. Throttled
     * to one row per visitor per channel per day so retries / extra mounts don't inflate.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visitor_id' => 'nullable|string|max:64',
            'utm_source' => 'nullable|string|max:128',
            'utm_medium' => 'nullable|string|max:128',
            'utm_campaign' => 'nullable|string|max:191',
            'click_id' => 'nullable|string|max:32',
            'referrer' => 'nullable|string|max:512',
            'landing_path' => 'nullable|string|max:512',
            // Geo resolved client-side by ipinfo.io (same source as user_info).
            'country' => 'nullable|string|max:64',
            'region' => 'nullable|string|max:96',
            'city' => 'nullable|string|max:96',
        ]);

        $visitorId = $data['visitor_id'] ?? null;

        $channel = ChannelMatcher::resolve(
            $data['utm_source'] ?? null,
            $data['click_id'] ?? null,
            $data['referrer'] ?? null,
        );

        // One row per visitor per CHANNEL per day: a device that arrives from Google in the morning and
        // from ChatGPT in the afternoon is credited to both (the multi-source card shows the overlap),
        // while repeat arrivals from the same channel that day still count once.
        if ($visitorId) {
            $key = 'visit:'.$visitorId.':'.$channel.':'.now()->toDateString();
            if (! Cache::add($key, 1, now()->addDay())) {
                return response()->json(['ok' => true, 'deduped' => true]);
            }
        }

        Visit::create([
            'visitor_id' => $visitorId,
            'channel' => $channel,
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'referrer' => $data['referrer'] ?? null,
            'landing_path' => $data['landing_path'] ?? null,
            // Public route (no auth middleware), so $request->user() reads the empty session guard — resolve
            // the bearer token explicitly or logged-in clients and staff are stored as anonymous.
            'user_id' => auth('sanctum')->id(),
            'ip' => $request->ip(),
            'country' => $data['country'] ?? null,
            'region' => $data['region'] ?? null,
            'city' => $data['city'] ?? null,
        ]);

        return response()->json(['ok' => true, 'channel' => $channel]);
    }
}
