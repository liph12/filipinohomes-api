<?php

namespace App\Http\Controllers;

use App\Http\Resources\FhoneListingResource;
use App\Models\Listing;
use Illuminate\Http\Request;

/**
 * GET /fh-one/listings — the open listings API for fhone (our own app).
 *
 * No token of any kind (no guest token, no login): anyone can call it, so it is rate-limited
 * per IP in routes/api.php. Returns EVERY listing that isn't deleted — any visibility (public or
 * not), any status, any audit result — newest first, paginated.
 *
 * "Deleted" = the listing is soft-deleted, its property is soft-deleted, or its property's
 * status is 'deleted' (how the rest of the app treats a removed listing).
 *
 * Query: page, per_page (1–100, default 20), and optional filters:
 *   status=active,sold,…      property status (comma-separated)
 *   visibility=public,private listing visibility (comma-separated)
 *   updated_since=2026-11-01  only listings changed on/after this date (for incremental syncs)
 */
class FhoneListingController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'status' => ['nullable', 'string', 'regex:/^[a-z_]+(,[a-z_]+)*$/'],
            'visibility' => ['nullable', 'string', 'regex:/^[a-z_]+(,[a-z_]+)*$/'],
            'updated_since' => 'nullable|date',
        ]);

        $listings = Listing::query()
            // whereHas applies Property's own soft-delete scope too.
            ->whereHas('property', function ($q) use ($data) {
                $q->where('status', '!=', 'deleted');
                if (isset($data['status'])) {
                    $q->whereIn('status', explode(',', $data['status']));
                }
            })
            ->when(isset($data['visibility']), fn ($q) => $q->whereIn('visibility', explode(',', $data['visibility'])))
            ->when(isset($data['updated_since']), fn ($q) => $q->where('updated_at', '>=', $data['updated_since']))
            ->with([
                'property.propertyAttribute.subtype.type',
                'property.furnishing',
                'category',
                'agent.user',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 20), ['*'], 'page', (int) ($data['page'] ?? 1))
            ->appends($request->only(['per_page', 'status', 'visibility', 'updated_since']));

        return FhoneListingResource::collection($listings);
    }
}
