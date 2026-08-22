<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Services\SponsorCaptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI thank-you captions for the sponsor poster tool.
 *
 * Admin-only (gated at the route). Takes a sponsor name + tier, answers with
 * 3 ready-to-post captions grounded in the ACTIVE event row — the year, the
 * dates, the venue all come from natcon_events, so next year's captions are
 * right the moment the new event row goes live.
 */
class SponsorCaptionController extends Controller
{
    public function __construct(private SponsorCaptionService $captions) {}

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sponsor_name' => 'required|string|min:2|max:120',
            // Only the four real sponsorship levels. `library` (the admin's
            // upload pool on Sponsor::ALL_TIERS) is deliberately NOT accepted:
            // it is not a level anyone can be thanked for being.
            'tier'         => 'required|in:star,copresentor,major,minor',
            'about'        => 'nullable|string|max:500',
        ]);

        $event = NatconEvent::active();
        if (! $event) {
            return response()->json(['message' => 'No active NATCON event.'], 404);
        }

        // Deliberately NO CacheService::updateDailyLimit() gate here: that
        // helper short-circuits admins to unlimited (CacheService ~line 68,
        // "Admins bypass every limit"), and this route only exists inside the
        // RoleMiddleware admin group — so a quota check would be dead code.
        // Protection is the role middleware plus the route's throttle.
        try {
            $result = $this->captions->generate(
                $event,
                $data['sponsor_name'],
                $data['tier'],
                $data['about'] ?? null,
            );

            if (! $result) {
                return response()->json(['message' => 'Failed to generate captions.'], 502);
            }

            return response()->json($result);
        } catch (\OpenAI\Exceptions\RateLimitException $e) {
            return response()->json([
                'message' => 'AI service is temporarily busy. Please try again in a moment.',
            ], 429);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate captions. Please try again.',
            ], 500);
        }
    }
}
