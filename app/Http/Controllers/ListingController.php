<?php

namespace App\Http\Controllers;

use App\Http\Resources\ListingResourceCollection;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Illuminate\Http\Request;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ListingController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum')->except(['index', 'show', 'subtypeCounts', 'featured']);
        $this->middleware(RoleMiddleware::class . ':agent,admin')->only(['store']);
        $this->middleware(RoleMiddleware::class . ':admin')->only(['updateIsFeatured']);
    }   

    public function index(Request $request): ListingResourceCollection
    {
        Log::info('Listing index user: ', ['token' => $request->bearerToken()]);
        $listings = Listing::where('visibility', 'public')
            ->with([
                'property.propertyAttribute.subtype',
                'category',
                'agent' => function ($q) { $q->withCount('listings'); }
            ])
            ->filter($request)
            ->sorted($request->get('sort_by', 'featured'))
            ->paginate($request->integer('per_page', 10));

        return new ListingResourceCollection($listings);
    }

    public function subtypeCounts(Request $request): JsonResponse
    {
        $counts = Listing::where('visibility', 'public')
            ->filter($request)
            ->join('properties', 'listings.property_id', '=', 'properties.id')
            ->join('property_attributes', 'properties.property_attribute_id', '=', 'property_attributes.id')
            ->join('property_subtypes', 'property_attributes.property_subtype_id', '=', 'property_subtypes.id')
            ->selectRaw('property_subtypes.name, COUNT(DISTINCT listings.id) as count')
            ->groupBy('property_subtypes.id', 'property_subtypes.name')
            ->pluck('count', 'property_subtypes.name');

        return response()->json([
            'counts' => $counts,
            'total'  => $counts->sum(),
        ]);
    }

    public function myListings(Request $request)
    {
        $user = $request->user();

        if ($user->role->name === 'admin') {
            $listings = Listing::orderBy('created_at', 'desc')->get();
        } elseif ($user->role->name === 'agent') {
            $listings = Listing::where('agent_id', $user->agent->id)
                ->orderBy('created_at', 'desc')
                ->get();
        } else {
            abort(403, 'Unauthorized.');
        }

        return new ListingResourceCollection($listings);
    }

    public function updateVisibility(Request $request, Listing $listing)
    {
        $this->authorize('update', $listing);

        $request->validate([
            'visibility' => 'required|in:public,private',
        ]);

        $listing->update(['visibility' => $request->visibility]);

        return response()->json(['visibility' => $listing->visibility]);
    }
    public function updateStatus(Request $request, Listing $listing)
    {
        $this->authorize('update', $listing);

        $data = $request->validate([
            'status' => 'required|in:active,rented,sold,leased',
        ]);

        $listing->property->update($data);

        return response()->json([
            'status' => $listing->property->status
        ]);
    }

    public function updateIsFeatured(Request $request, Listing $listing)
    {
        $this->authorize('update', $listing);

        $data = $request->validate([
            'is_featured' => 'required|boolean',
        ]);

        $listing->update(['is_featured' => $data['is_featured']]);

        $listing = $listing->fresh();

        return response()->json([
            'is_featured'    => (bool) $listing->is_featured,
        ]);
    }

    public function show(string $slug)
    {
        $listing = Listing::where('slug', $slug)
            ->with([
                'property.propertyAttribute.subtype.type',
                'property.furnishing',
                'category',
                'agent.user',
            ])
            ->firstOrFail();

        $user = auth('sanctum')->user();

        if ($listing->visibility !== 'public') {
            if (!$user || ($user->role->name !== 'admin' && $listing->agent_id !== ($user->agent->id ?? null))) {
                abort(403, 'This listing is private. Only the owner or admin can view it.');
            }
        }

        return new ListingResource($listing);
    }

    public function featured(Request $request): ListingResourceCollection
    {
        $listings = Listing::where('is_featured', true)
            ->where('visibility', 'public')
            ->with([
                'property.propertyAttribute.subtype',
                'category',
                'agent' => function ($q) { $q->withCount('listings'); }
            ])
            ->get();

        return new ListingResourceCollection($listings);
    }

    public function update(Request $request, Listing $listing)
    {
        $this->authorize('update', $listing);

        $validated = $request->validate([
            'code'           => 'sometimes|string|max:255',
            'status'         => 'sometimes|string|max:255',
            'name'           => 'sometimes|string|max:255',
            'slug'           => 'nullable|string|max:255',
            'price'          => 'sometimes|numeric|min:0',
            'featured_photo' => 'nullable|string|max:255',
            'is_featured'    => 'sometimes|boolean',
            'clicks'         => 'nullable|integer|min:0',
            'property_id'    => 'sometimes|integer|exists:properties,id',
            'category_id'    => 'sometimes|integer|exists:categories,id',
        ]);

        $listing->update($validated);

        return new ListingResource($listing);
    }

    public function destroy(Listing $listing)
    {
        $this->authorize('delete', $listing);

        $listing->delete();

        return response()->json(['message' => 'Listing deleted successfully']);
    }
}
