<?php

namespace App\Http\Controllers;

use App\Http\Resources\ExternalListingResource;
use App\Models\Agent;
use App\Models\Listing;
use App\Services\Listing\PartnerFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner feed of our listings for other websites (X-FH-Agent-Token, same as
 * GET /fh-agent/{email}). WHAT goes out is controlled from System Settings →
 * Partner Listings Feed (PartnerFeedService); partners can only narrow it.
 */
class ExternalListingController extends Controller
{
    public function __construct(private PartnerFeedService $feed) {}

    /**
     * GET /fh-agent/listings?page=&per_page=&verification=&status=
     *
     * Public listings of active agents whose audit result and property status
     * are switched on in the feed settings (see PartnerFeedService::query()
     * for the ATS rule). `verification` and `status` (comma-separated, e.g.
     * status=sold,rented) narrow within what the settings allow; asking for
     * something that is switched off simply returns nothing for it.
     */
    public function index(Request $request)
    {
        $settings = $this->feed->feed('listings');
        if (! $settings['enabled']) {
            // "error" key, not "message" — the web's axios interceptor treats
            // a message-keyed 401 as a dead session; keep the convention.
            return response()->json(['error' => 'The listings feed is currently turned off.'], 503);
        }

        $audit = implode('|', array_keys(PartnerFeedService::AUDIT));
        $statuses = implode('|', array_keys(PartnerFeedService::STATUSES));
        $data = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'verification' => ['nullable', 'string', "regex:/^({$audit})(,({$audit}))*$/"],
            'status' => ['nullable', 'string', "regex:/^({$statuses})(,({$statuses}))*$/"],
        ]);

        $allowedAudit = isset($data['verification'])
            ? array_values(array_intersect($settings['audit'], explode(',', $data['verification'])))
            : $settings['audit'];
        $allowedStatuses = isset($data['status'])
            ? array_values(array_intersect($settings['statuses'], explode(',', $data['status'])))
            : $settings['statuses'];

        $listings = $this->feed->query($allowedAudit, $allowedStatuses)
            ->with([
                'property.propertyAttribute.subtype.type',
                'property.furnishing',
                'category',
                'agent.user',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 20), ['*'], 'page', (int) ($data['page'] ?? 1))
            ->appends($request->only(['per_page', 'verification', 'status']));

        return ExternalListingResource::collection($listings);
    }

    /**
     * GET /fh-agent/listings/match?email=&code=
     *
     * For the Leuterio Realty site: is listing {code} owned by the agent with this email? The email
     * matches either the agent's Filipinohomes account email or their LR email (agents.lr_email), case-
     * insensitively. If it does, the listing comes back in the same shape as the feed, whatever its
     * status, visibility, ATS or audit result (deleted listings excluded). The agent-lookup switch in
     * System Settings → Partner Listings Feed still turns it off. Otherwise 404 with `matched: false`
     * and a `reason`:
     *   agent_not_found    no agent has that email (FH or LR);
     *   listing_not_found  no listing has that code;
     *   not_owner          the listing belongs to another agent.
     */
    public function match(Request $request)
    {
        $settings = $this->feed->feed('agent_lookup');
        if (! $settings['enabled']) {
            return response()->json(['error' => 'The agent lookup is currently turned off.'], 503);
        }

        $data = $request->validate([
            'email' => 'required|string|max:255',
            'code' => 'required|string|max:100',
        ]);
        $found = $this->findOwned($data['email'], $data['code']);
        if (! is_array($found)) {
            return $found;
        }
        [$listing, $matchedBy] = $found;

        $listing->load([
            'property.propertyAttribute.subtype.type',
            'property.furnishing',
            'category',
            'agent.user',
        ]);

        return (new ExternalListingResource($listing))->additional([
            'matched' => true,
            'matched_by' => $matchedBy,
        ]);
    }

    /**
     * PATCH /lr/listings/status (X-LR-Token) — the Leuterio Realty site changes a listing's status:
     * active, sold, rented or leased. Same ownership check as match() (the email must be the owning
     * agent's FH or LR email), then the same update the agent dashboard makes
     * (ListingController::updateStatus), recorded in the audit log as coming from the LR website.
     * status_change_date defaults to today. Returns the listing as match() does.
     */
    public function updateStatus(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|string|max:255',
            'code' => 'required|string|max:100',
            'status' => 'required|in:active,sold,rented,leased',
            'status_change_date' => 'nullable|date',
            'status_remark' => 'nullable|string|max:1000',
        ]);

        $found = $this->findOwned($data['email'], $data['code']);
        if (! is_array($found)) {
            return $found;
        }
        [$listing, $matchedBy] = $found;

        $property = $listing->property;
        if (! $property) {
            return response()->json(['matched' => true, 'error' => 'This listing has no property record to update.'], 409);
        }
        $previous = $property->status;
        $property->auditSource = 'status_change';
        $property->auditDescription = sprintf('Leuterio Realty website (%s) changed %s status to %s', strtolower(trim($data['email'])), $listing->name, $data['status']);
        $property->update([
            'status' => $data['status'],
            'status_change_date' => $data['status_change_date'] ?? now()->toDateString(),
            'status_remark' => $data['status_remark'] ?? null,
        ]);

        $listing->load([
            'property.propertyAttribute.subtype.type',
            'property.furnishing',
            'category',
            'agent.user',
        ]);

        return (new ExternalListingResource($listing))->additional([
            'matched' => true,
            'matched_by' => $matchedBy,
            'previous_status' => $previous,
        ]);
    }

    /**
     * [listing, 'fh_email' | 'lr_email'] when the agent with this email (FH account email or
     * agents.lr_email, case-insensitive) owns the listing with this code; otherwise the 404 response to
     * return. The same address can be one agent's FH email and another's LR email, so
     * both are collected.
     */
    /** @return array{0: Listing, 1: string}|JsonResponse */
    private function findOwned(string $email, string $code): array|JsonResponse
    {
        $email = strtolower(trim($email));
        $byFh = Agent::query()->whereHas('user', fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email]))->pluck('id')->all();
        $byLr = Agent::query()->whereRaw('LOWER(lr_email) = ?', [$email])->pluck('id')->all();
        if (! $byFh && ! $byLr) {
            return $this->noMatch('agent_not_found', 'No agent has this email (Filipinohomes or LR).');
        }

        $listing = Listing::query()->where('code', trim($code))->first();
        if (! $listing) {
            return $this->noMatch('listing_not_found', 'No listing has this code.');
        }
        $owner = (int) $listing->agent_id;
        if (! in_array($owner, [...$byFh, ...$byLr], true)) {
            return $this->noMatch('not_owner', 'This listing belongs to a different agent.');
        }

        return [$listing, in_array($owner, $byFh, true) ? 'fh_email' : 'lr_email'];
    }

    private function noMatch(string $reason, string $message)
    {
        return response()->json(['matched' => false, 'reason' => $reason, 'message' => $message], 404);
    }
}
