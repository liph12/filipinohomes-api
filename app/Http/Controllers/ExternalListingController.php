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

    /**
     * GET /fh-agent/listings?page=&per_page=&verification=
     *
     * Public, active, ATS-approved listings of active agents (the same
     * gates as the by-email endpoint and publiclyListed()) whose audit is
     * fully_verified or verified. `verification` narrows to one of the two.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'page'         => 'nullable|integer|min:1',
            'per_page'     => 'nullable|integer|min:1|max:100',
            'verification' => 'nullable|in:'.implode(',', self::VERIFIED),
        ]);

        $statuses = isset($data['verification']) ? [$data['verification']] : self::VERIFIED;

        $listings = Listing::publiclyListed()
            ->whereIn('verification_status', $statuses)
            ->whereHas('property', fn ($q) => $q
                ->where('status', 'active')
                ->where('ats_status', 'approve'))
            ->with([
                'property.propertyAttribute.subtype.type',
                'property.furnishing',
                'category',
                'agent.user',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 20), ['*'], 'page', (int) ($data['page'] ?? 1))
            ->appends($request->only(['per_page', 'verification']));

        return ExternalListingResource::collection($listings);
    }
}
