<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Services\Listing\ListingFeedSwitch;
use App\Services\Listing\ListingPriceRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The "your price looks wrong" prompt for agents. A listing whose price cannot be an asking price (a rent typed as
 * 25 for ₱25,000) shows "Price on request" until its agent either corrects the price or, when it is only unusual,
 * confirms it. Nothing here changes a number on its own.
 *
 *   GET  /my-listings/price-issues          my public listings that show "Price on request", with why and what to do
 *   POST /listings/{listing}/price/confirm  "this price is right" (only for an unusual price, for exactly this figure)
 *   POST /listings/{listing}/price/correct  "the price is X" (an ordinary price edit, which does not send a Verified
 *                                           listing back to review: this is a repair, not a re-pricing)
 */
class ListingPriceController extends Controller
{
    public function issues(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = ($user->role->name ?? null) === 'admin';
        $agentId = $isAdmin && $request->filled('agent_id') ? (int) $request->input('agent_id') : (int) ($user->agent->id ?? 0);
        if ($agentId < 1) {
            return response()->json(['data' => [], 'meta' => ['count' => 0]]);
        }

        [$ok, $bindings] = ListingPriceRules::believableSql(ListingFeedSwitch::approvedPrices());

        $listings = Listing::query()
            ->where('agent_id', $agentId)->where('visibility', 'public')
            ->whereRaw("NOT {$ok}", $bindings)
            ->with(['category:id,name', 'property.propertyAttribute.subtype.type'])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->get();

        $data = $listings->map(function (Listing $l) {
            $verdict = $this->verdict($l);

            return [
                'id' => $l->id,
                'code' => $l->code,
                'slug' => $l->slug,
                'name' => $l->name,
                'price' => $l->price,
                'category' => $l->category?->name,
                'price_reason' => $verdict['reason'],
                'price_suggested' => $verdict['suggested'],
                'price_confirmable' => $verdict['confirmable'],
            ];
        })->values();

        return response()->json(['data' => $data, 'meta' => ['count' => $data->count()]]);
    }

    public function confirm(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('update', $listing);
        $request->validate(['price' => 'required|numeric']);

        $verdict = $this->verdict($listing);
        if ($verdict['status'] === 'ok') {
            return response()->json(['message' => 'This price already shows as it is.', 'price_status' => 'ok']);
        }
        if (abs((float) $request->input('price') - (float) $listing->price) >= 0.005) {
            return response()->json(['message' => 'The price changed. Check it and confirm again.'], 422);
        }
        if (! $verdict['confirmable']) {
            return response()->json([
                'message' => 'This price cannot be confirmed as it is. Enter the full price instead.',
                'price_reason' => $verdict['reason'],
                'price_suggested' => $verdict['suggested'],
            ], 422);
        }

        if (! ListingFeedSwitch::approve($listing->id, (float) $listing->price)) {
            return response()->json(['message' => 'This price cannot be confirmed right now. Enter the full price instead.'], 422);
        }
        try {
            Log::info('listing.price.confirmed', ['listing_id' => $listing->id, 'price' => (float) $listing->price, 'by' => $request->user()?->id]);
        } catch (\Throwable) {
            // The confirmation is stored; a log that cannot be written must not turn it into an error.
        }

        return response()->json(['message' => 'Price confirmed.', 'price_status' => 'ok']);
    }

    public function correct(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('update', $listing);
        $validated = $request->validate(['price' => 'required|numeric|gt:0']);
        $new = (float) $validated['price'];

        // Only a flagged price is a repair that may skip re-review. On a price that already shows normally this
        // would be a plain price change, which sends a Verified listing back to review through the edit form.
        if ($this->verdict($listing)['status'] === 'ok') {
            return response()->json([
                'message' => 'This price is not flagged. To change it, edit the listing.',
                'price_status' => 'ok',
            ], 422);
        }

        $listing->loadMissing(['category:id,name', 'property.propertyAttribute.subtype.type']);
        $attributes = $listing->property?->propertyAttribute;
        $verdict = ListingPriceRules::classify($new, $listing->category?->name, $attributes?->subtype?->type?->name, $attributes?->lot_area);
        if ($verdict['status'] !== 'ok') {
            return response()->json([
                'message' => 'That price still looks off. Enter the full price.',
                'price_reason' => $verdict['reason'],
                'price_suggested' => $verdict['suggested'],
                'price_confirmable' => $verdict['confirmable'],
            ], 422);
        }

        $actor = $request->user()?->name ?? 'Someone';
        $listing->auditSource = 'price_prompt';
        $listing->auditDescription = sprintf('%s corrected the price of %s', $actor, $listing->name);
        Listing::withoutPriceReview(fn () => $listing->update(['price' => $new]));
        ListingFeedSwitch::revoke($listing->id); // an old confirmation was for the old figure

        return response()->json(['message' => 'Price updated.', 'price' => $listing->fresh()->price, 'price_status' => 'ok']);
    }

    /** @return array{status: string, reason: string|null, suggested: float|null, confirmable: bool} */
    private function verdict(Listing $listing): array
    {
        $listing->loadMissing(['category:id,name', 'property.propertyAttribute.subtype.type']);
        $attributes = $listing->property?->propertyAttribute;
        $approved = ListingFeedSwitch::approvedPrices();
        $confirmed = isset($approved[$listing->id]) && abs($approved[$listing->id] - (float) $listing->price) < 0.005;

        return ListingPriceRules::classify($listing->price, $listing->category?->name, $attributes?->subtype?->type?->name, $attributes?->lot_area, $confirmed);
    }
}
