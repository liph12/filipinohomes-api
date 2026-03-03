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
        $this->middleware('auth:sanctum')->except(['index', 'show', 'subtypeCounts']);
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

        $query = Listing::visibleTo($user)
            ->with(['property.propertyAttribute.subtype', 'category', 'agent']);

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('listings.name', 'like', "%{$search}%")
                    ->orWhereHas('property', function ($sub) use ($search) {
                        $sub->where('address', 'like', "%{$search}%");
                    });
            });
        }

        // Categories
        if ($categories = $request->get('categories')) {
            $cats = is_array($categories) ? $categories : explode(',', $categories);
            $query->whereHas('category', fn($q) => $q->whereIn('name', $cats));
        }

        // Subtypes
        if ($subtypes = $request->get('subtypes')) {
            $ids = is_array($subtypes) ? $subtypes : explode(',', $subtypes);
            $query->whereHas('property.propertyAttribute.subtype', fn($q) => $q->whereIn('id', $ids));
        }

        // Price
        if ($priceMin = $request->get('price_min')) {
            $query->where('listings.price', '>=', $priceMin);
        }
        if ($priceMax = $request->get('price_max')) {
            $query->where('listings.price', '<=', $priceMax);
        }

        if ($request->filled('sqm_min')) {
            $query->whereHas('property.propertyAttribute', function ($q) use ($request) {
                $q->whereRaw(
                    "GREATEST(COALESCE(lot_area,0), COALESCE(floor_area,0)) >= ?",
                    [$request->sqm_min]
                );
            });
        }

        if ($request->filled('sqm_max')) {
            $query->whereHas('property.propertyAttribute', function ($q) use ($request) {
                $q->whereRaw(
                    "GREATEST(COALESCE(lot_area,0), COALESCE(floor_area,0)) <= ?",
                    [$request->sqm_max]
                );
            });
        }

        // Beds
        if ($request->filled('beds')) {
            $beds = (int) $request->get('beds');
            $bedsCondition = $request->get('beds_condition', 'equal');
            $query->whereHas('property.propertyAttribute', function ($q) use ($beds, $bedsCondition) {
                if ($bedsCondition === 'plus') {
                    $q->where('bedroom_count', '>=', $beds);
                } elseif ($bedsCondition === 'minus') {
                    $q->where('bedroom_count', '<=', $beds); // ✅ includes 0
                } else {
                    $q->where('bedroom_count', '=', $beds);
                }
            });
        }

        // Baths
        if ($request->filled('baths')) {
            $baths = (int) $request->get('baths');
            $bathsCondition = $request->get('baths_condition', 'equal');
            $query->whereHas('property.propertyAttribute', function ($q) use ($baths, $bathsCondition) {
                if ($bathsCondition === 'plus') {
                    $q->where('bathroom_count', '>=', $baths);
                } elseif ($bathsCondition === 'minus') {
                    $q->where('bathroom_count', '<=', $baths); // ✅ includes 0
                } else {
                    $q->where('bathroom_count', '=', $baths);
                }
            });
        }
        // Furnishings
        if ($furnishings = $request->get('furnishings')) {
            $ids = is_array($furnishings) ? $furnishings : explode(',', $furnishings);
            $query->whereHas('property', fn($q) => $q->whereIn('furnishing_id', $ids));
        }

        // Amenities (stored inside properties.amenities as JSON)
        if ($amenities = $request->get('amenities')) {
            $names = is_array($amenities) ? $amenities : explode(',', $amenities);

            $query->whereHas('property', function ($q) use ($names) {
                foreach ($names as $name) {
                    $q->whereJsonContains('amenities', $name);
                }
            });
        }

        // Sort
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
                $query->leftJoin('property', 'listings.property_id', '=', 'property.id')
                    ->leftJoin('propertyAttribute ', 'property.propertyAttribute ', '=', 'propertyAttribute.id')
                    ->orderByRaw("GREATEST(COALESCE(propertyAttribute .lot_area, 0), COALESCE(propertyAttribute.floor_area, 0)) {$direction}")
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

        if ($user->role->name === 'admin') {
            $listings = Listing::paginate(10);
        } elseif ($user->role->name === 'agent') {
            $listings = Listing::where('agent_id', $user->agent->id)->paginate(10);
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

    public function subtypeCounts(Request $request)
    {
        $user = null;

        if ($request->bearerToken()) {
            $user = \Laravel\Sanctum\PersonalAccessToken::findToken(
                $request->bearerToken()
            )?->tokenable;
        }

        $query = DB::table('listings')
            ->join('properties', 'listings.property_id', '=', 'properties.id')
            ->join('property_attributes', 'properties.property_attribute_id', '=', 'property_attributes.id')
            ->join('property_subtypes', 'property_attributes.property_subtype_id', '=', 'property_subtypes.id')
            ->join('property_types', 'property_subtypes.property_type_id', '=', 'property_types.id')
            ->join('categories', 'listings.category_id', '=', 'categories.id')
            ->leftJoin('furnishings', 'properties.furnishing_id', '=', 'furnishings.id')
            ->when(!$user, fn($q) => $q->where('listings.visibility', 'public'));

        // Search
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('listings.name', 'like', "%{$search}%")
                    ->orWhere('properties.address', 'like', "%{$search}%");
            });
        }

        // Categories — by name, supports comma-separated or array
        if ($categories = $request->get('categories')) {
            $cats = is_array($categories) ? $categories : explode(',', $categories);
            $query->whereIn('categories.name', $cats);
        }

        // Subtypes — by ID (matching index())
        if ($subtypes = $request->get('subtypes')) {
            $ids = is_array($subtypes) ? $subtypes : explode(',', $subtypes);
            $query->whereIn('property_subtypes.id', $ids);
        }

        // Price
        if ($priceMin = $request->get('price_min')) {
            $query->where('listings.price', '>=', $priceMin);
        }
        if ($priceMax = $request->get('price_max')) {
            $query->where('listings.price', '<=', $priceMax);
        }

        // SQM — matching GREATEST(lot_area, floor_area) logic
        if ($request->filled('sqm_min')) {
            $query->whereRaw(
                "GREATEST(COALESCE(property_attributes.lot_area, 0), COALESCE(property_attributes.floor_area, 0)) >= ?",
                [$request->sqm_min]
            );
        }
        if ($request->filled('sqm_max')) {
            $query->whereRaw(
                "GREATEST(COALESCE(property_attributes.lot_area, 0), COALESCE(property_attributes.floor_area, 0)) <= ?",
                [$request->sqm_max]
            );
        }

        if ($request->filled('beds') || $request->get('beds') === '0') {
            $beds = (int) $request->get('beds');
            $bedsCondition = $request->get('beds_condition', 'equal');
            $query->where('property_attributes.bedroom_count', match ($bedsCondition) {
                'plus'  => '>=',
                'minus' => '<=',
                default => '=',
            }, $beds);
        }

        if ($request->filled('baths') || $request->get('baths') === '0') {
            $baths = (int) $request->get('baths');
            $bathsCondition = $request->get('baths_condition', 'equal');
            $query->where('property_attributes.bathroom_count', match ($bathsCondition) {
                'plus'  => '>=',
                'minus' => '<=',
                default => '=',
            }, $baths);
        }
        // Furnishings — by ID (matching index())
        if ($furnishings = $request->get('furnishings')) {
            $ids = is_array($furnishings) ? $furnishings : explode(',', $furnishings);
            $query->whereIn('properties.furnishing_id', $ids);
        }

        // Amenities filter (stored in properties.amenities as JSON)
        if ($amenities = $request->get('amenities')) {
            $names = is_array($amenities) ? $amenities : explode(',', $amenities);

            foreach ($names as $name) {
                $query->whereJsonContains('properties.amenities', $name);
            }
        }

        $counts = $query
            ->select('property_subtypes.name', DB::raw('COUNT(DISTINCT listings.id) as count'))
            ->groupBy('property_subtypes.name')
            ->pluck('count', 'property_subtypes.name');

        return response()->json([
            'counts' => $counts,
            'total'  => $counts->sum(),
        ]);
    }
}
