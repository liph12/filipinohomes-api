<?php

namespace App\Http\Controllers;

use App\Http\Resources\ListingResourceCollection;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Illuminate\Http\Request;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Support\Facades\DB;
class ListingController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum')->except(['index', 'show']);
        $this->middleware(RoleMiddleware::class . ':agent,admin')->only(['store']);
    }

    public function index(Request $request)
    {
        $user = null;
        if ($request->bearerToken()) {
            $user = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken())
                ?->tokenable;
        }

        $perPage = $request->get('per_page', 10);
        $sortBy  = $request->get('sort_by', 'featured');

        $query = Listing::visibleTo($user);

        switch ($sortBy) {
            case 'most-viewed':
                $query->orderBy('clicks', 'desc');
                break;
            case 'newest':
                $query->orderBy('created_at', 'desc');
                break;
            case 'price-low':
                $query->orderBy('price', 'asc');
                break;
            case 'price-high':
                $query->orderBy('price', 'desc');
                break;
            case 'sqm-low':
            case 'sqm-high':
                $direction = $sortBy === 'sqm-low' ? 'ASC' : 'DESC';
                $query->leftJoin('properties', 'listings.property_id', '=', 'properties.id')
                    ->leftJoin('property_attributes', 'properties.property_attribute_id', '=', 'property_attributes.id')
                    ->orderByRaw("GREATEST(COALESCE(property_attributes.lot_area, 0), COALESCE(property_attributes.floor_area, 0)) {$direction}")
                    ->select('listings.*');
                break;
            case 'featured':
            default:
                $query->orderBy('is_featured', 'desc')->orderBy('clicks', 'desc');
                break;
        }

        return new ListingResourceCollection($query->paginate($perPage));
    }

    public function myListings(Request $request)
    {
        $user = $request->user();

        if ($user->role->name === 'agent') {
            $listings = Listing::where('agent_id', $user->agent->id)->paginate(10);
        } else {
            $listings = Listing::paginate(10);
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

    public function show(Request $request, $id)
    {
        $listing = Listing::findOrFail($id);

        $this->authorize('view', $listing);

        return new ListingResource($listing);
    }

    // public function store(Request $request)
    // {
    //     $user = $request->user();

    //     // Only agents can create, admins will create if needed
    //     $agent = $user->agent ?? null;
    //     if (!$agent && $user->role->name === 'agent') {
    //         return response()->json(['message' => 'Agent profile not found for this user.'], 403);
    //     }

    //     $validated = $request->validate([
    //         'code'           => 'required|string|max:255',
    //         'status'         => 'required|string|max:255',
    //         'name'           => 'required|string|max:255',
    //         'slug'           => 'nullable|string|max:255',
    //         'price'          => 'required|numeric|min:0',
    //         'featured_photo' => 'nullable|string|max:255',
    //         'is_featured'    => 'sometimes|boolean',
    //         'clicks'         => 'nullable|integer|min:0',
    //         'property_id'    => 'required|integer|exists:properties,id',
    //         'category_id'    => 'required|integer|exists:categories,id',
    //     ]);

    //     // Agents are forced to their own agent_id, admins can choose
    //     if ($user->role->name === 'agent') {
    //         $validated['agent_id'] = $agent->id;
    //     }

    //     $listing = Listing::updateOrCreate(
    //         ['id' => $request->id ?? null],
    //         $validated
    //     );

    //     return new ListingResource($listing);
    // }

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

    public function subtypeCounts()
    {
        $user = null;
        if (request()->bearerToken()) {
            $user = \Laravel\Sanctum\PersonalAccessToken::findToken(request()->bearerToken())
                ?->tokenable;
        }

        $counts = DB::table('listings')
            ->join('properties', 'listings.property_id', '=', 'properties.id')
            ->join('property_attributes', 'properties.property_attribute_id', '=', 'property_attributes.id')
            ->join('property_subtypes', 'property_attributes.property_subtype_id', '=', 'property_subtypes.id')
            ->when(!$user, fn($q) => $q->where('listings.visibility', 'public'))
            ->select('property_subtypes.name', DB::raw('count(*) as count'))
            ->groupBy('property_subtypes.name')
            ->get()
            ->pluck('count', 'name');

        $total = $counts->sum();

        return response()->json([
            'counts' => $counts,
            'total' => $total,
        ]);
    }
}