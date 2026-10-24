<?php

namespace App\Http\Controllers;

use App\Http\Resources\ExternalListingResource;
use App\Models\Listing;
use Illuminate\Http\Request;

/**
 * Partner feed of every audited public listing (X-FH-Agent-Token, same as
 * GET /fh-agent/{email}).
 */
class ExternalListingController extends Controller
{
    /** Audit results a partner may receive. */
    private const VERIFIED = ['fully_verified', 'verified'];

    /** Property statuses in the feed: on the market, plus closed deals. */
    private const STATUSES = ['active', 'sold', 'rented', 'leased'];

    /**
     * GET /fh-agent/listings?page=&per_page=&verification=&status=
     *
     * Public listings of active agents (publiclyListed()) whose audit is
     * fully_verified or verified, and whose property is active (ATS
     * approved) or a closed deal — sold, rented or leased (ATS approved or
     * expired).
     * `verification` narrows to one audit result; `status` to one or more
     * statuses (comma-separated, e.g. status=sold,rented).
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'page'         => 'nullable|integer|min:1',
            'per_page'     => 'nullable|integer|min:1|max:100',
            'verification' => 'nullable|in:'.implode(',', self::VERIFIED),
            'status'       => ['nullable', 'string', 'regex:/^('.implode('|', self::STATUSES).')(,('.implode('|', self::STATUSES).'))*$/'],
        ]);

        $statuses = isset($data['verification']) ? [$data['verification']] : self::VERIFIED;
        $propertyStatuses = isset($data['status']) ? array_unique(explode(',', $data['status'])) : self::STATUSES;

        $listings = Listing::publiclyListed()
            ->whereIn('verification_status', $statuses)
            // On the market: ATS must be approved. Closed deals: approved or
            // expired — the authority to sell naturally lapses after a sale;
            // rejected / pending never qualify.
            ->whereHas('property', fn ($q) => $q
                ->whereIn('status', $propertyStatuses)
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('status', 'active')->where('ats_status', 'approve'))
                    ->orWhere(fn ($q) => $q->where('status', '!=', 'active')->whereIn('ats_status', ['approve', 'expired']))))
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
}
