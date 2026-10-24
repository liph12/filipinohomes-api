<?php

namespace App\Http\Controllers;

use App\Http\Resources\ExternalListingResource;
use App\Services\Listing\PartnerFeedService;
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
            'page'         => 'nullable|integer|min:1',
            'per_page'     => 'nullable|integer|min:1|max:100',
            'verification' => ['nullable', 'string', "regex:/^({$audit})(,({$audit}))*$/"],
            'status'       => ['nullable', 'string', "regex:/^({$statuses})(,({$statuses}))*$/"],
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
}
