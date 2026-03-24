<?php

namespace App\Http\Controllers;

use App\Http\Resources\ListingResourceCollection;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Illuminate\Http\Request;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Models\PropertyType;

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
                'agent' => function ($q) {
                    $q->withCount('listings');
                }
            ])
            ->filter($request)
            ->sorted($request->input('sort_by', 'featured'))
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

        $query = Listing::where('agent_id', $user->agent->id);

        // ── Search (applied to everything) ───────────────────────────────────
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('listings.name', 'like', "%{$search}%")  // ← prefix with table
                    ->orWhere('listings.code', 'like', "%{$search}%")  // ← prefix with table
                    ->orWhereHas('property', fn($sub) => $sub->where('address', 'like', "%{$search}%"));
            });
        }

        $status     = $request->input('status');
        $visibility = $request->input('visibility');
        $category   = $request->input('category');

        // ── Helper to apply a filter to a query clone ─────────────────────────
        $applyStatus = function ($q) use ($status) {
            if (!$status) return $q;
            if ($status === 'active') return $q->active();
            return $q->whereHas('property', fn($sub) => $sub->where('status', $status));
        };

        $applyVisibility = function ($q) use ($visibility) {
            if (!$visibility) return $q;
            return $q->where('visibility', $visibility);
        };

        $applyCategory = function ($q) use ($category) {
            if (!$category) return $q;
            return $q->whereHas('category', fn($sub) => $sub->where('name', $category));
        };

        // ── Status counts: respect visibility + category filters, NOT status ──
        $statusBase = $applyCategory($applyVisibility(clone $query));
        $statusCounts = [
            'active' => (clone $statusBase)->active()->count(),
            'rented' => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'rented'))->count(),
            'sold'   => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'sold'))->count(),
            'leased' => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'leased'))->count(),
        ];

        // ── Visibility counts: respect status + category filters, NOT visibility ──
        $visibilityBase = $applyCategory($applyStatus(clone $query));
        $visibilityCounts = (clone $visibilityBase)
            ->selectRaw('visibility, COUNT(*) as count')
            ->groupBy('visibility')
            ->pluck('count', 'visibility')
            ->toArray();

        // ── Category counts: respect status + visibility filters, NOT category ──
        $categoryBase = $applyStatus($applyVisibility(clone $query));
        $categoryCounts = (clone $categoryBase)
            ->selectRaw('categories.name as category_name, COUNT(*) as count')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->groupBy('categories.id', 'categories.name')
            ->pluck('count', 'category_name')
            ->toArray();

        // ── Apply ALL filters for pagination ─────────────────────────────────
        $query = $applyStatus($query);
        $query = $applyVisibility($query);
        $query = $applyCategory($query);

        $listings = $query->orderBy('created_at', 'desc')
            ->paginate($request->query('per_page', 10));

        return (new ListingResourceCollection($listings))->additional([
            'counts' => [
                'status'     => $statusCounts,
                'visibility' => $visibilityCounts,
                'category'   => $categoryCounts,
            ],
        ]);
    }

    public function allListings (Request $request)
    {
        $user = $request->user();
        if ($user->role->name !== 'admin') abort(403);
        $query = Listing::query(); 

        // ── Search (applied to everything) ───────────────────────────────────
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('listings.name', 'like', "%{$search}%")  // ← prefix with table
                    ->orWhere('listings.code', 'like', "%{$search}%")  // ← prefix with table
                    ->orWhereHas('property', fn($sub) => $sub->where('address', 'like', "%{$search}%"));
            });
        }

        $status     = $request->input('status');
        $visibility = $request->input('visibility');
        $category   = $request->input('category');

        // ── Helper to apply a filter to a query clone ─────────────────────────
        $applyStatus = function ($q) use ($status) {
            if (!$status) return $q;
            if ($status === 'active') return $q->active();
            return $q->whereHas('property', fn($sub) => $sub->where('status', $status));
        };

        $applyVisibility = function ($q) use ($visibility) {
            if (!$visibility) return $q;
            return $q->where('visibility', $visibility);
        };

        $applyCategory = function ($q) use ($category) {
            if (!$category) return $q;
            return $q->whereHas('category', fn($sub) => $sub->where('name', $category));
        };

        // ── Status counts: respect visibility + category filters, NOT status ──
        $statusBase = $applyCategory($applyVisibility(clone $query));
        $statusCounts = [
            'active' => (clone $statusBase)->active()->count(),
            'rented' => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'rented'))->count(),
            'sold'   => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'sold'))->count(),
            'leased' => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'leased'))->count(),
        ];

        // ── Visibility counts: respect status + category filters, NOT visibility ──
        $visibilityBase = $applyCategory($applyStatus(clone $query));
        $visibilityCounts = (clone $visibilityBase)
            ->selectRaw('visibility, COUNT(*) as count')
            ->groupBy('visibility')
            ->pluck('count', 'visibility')
            ->toArray();

        // ── Category counts: respect status + visibility filters, NOT category ──
        $categoryBase = $applyStatus($applyVisibility(clone $query));
        $categoryCounts = (clone $categoryBase)
            ->selectRaw('categories.name as category_name, COUNT(*) as count')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->groupBy('categories.id', 'categories.name')
            ->pluck('count', 'category_name')
            ->toArray();

        // ── Apply ALL filters for pagination ─────────────────────────────────
        $query = $applyStatus($query);
        $query = $applyVisibility($query);
        $query = $applyCategory($query);

        $listings = $query->orderBy('created_at', 'desc')
            ->paginate($request->query('per_page', 10));

        return (new ListingResourceCollection($listings))->additional([
            'counts' => [
                'status'     => $statusCounts,
                'visibility' => $visibilityCounts,
                'category'   => $categoryCounts,
            ],
        ]);
    }

    private function getMonths()
    {
        $months = [];
    
        $start = Carbon::now()->startOfYear();
    
        for ($i = 0; $i < 12; $i++) {
            $months[] = $start->copy()->addMonths($i)->format('Y-m');
        }
    
        return $months;
    }

    public function getListingCategoryStatistics($start, $end)
    {
        $propertyTypes = PropertyType::with([
            'subTypes' => function ($q) use ($start, $end) {
                $q->withCount([
                    'attributes as total_listings' => function ($q) use ($start, $end) {
                        $q->whereBetween('created_at', [$start, $end]);
                    }
                ]);
            }
        ])->get();
    
        return $propertyTypes;
    }

    public function createAnnualStatistics($month)
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = Carbon::createFromFormat('Y-m', $month)->endOfMonth();
    
        $baseQuery = Listing::query()
            ->whereBetween('created_at', [$start, $end]);
        $agentCount = \App\Models\Agent::whereBetween('member_since', [$start, $end])->count();
    
        $statistics['active'] = (clone $baseQuery)->active()->count();
        $statistics['total']  = (clone $baseQuery)->count();
        $statistics['views']  = (int) (clone $baseQuery)->sum('clicks');
    
        $statistics['inquiries'] = \App\Models\ListingInquiry::whereIn(
            'listing_id',
            (clone $baseQuery)->select('id')
        )->count();
    
        $statistics['rented'] = (clone $baseQuery)->rented()->count();
        $statistics['sold']   = (clone $baseQuery)->sold()->count();
        $statistics['leased'] = (clone $baseQuery)->leased()->count();
        $statistics['agent'] = $agentCount;
        $statistics['properties'] = $this->getListingCategoryStatistics($start, $end);
    
        return $statistics;
    }

    public function dashboard(Request $request)
    {   
        $user = $request->user();
        $isAdmin = $user->role->name === 'admin';
        $annualDates = $this->getMonths();
        $annualStatistics = [];
        $statistics = [
            'active'    => 0,
            'total'     => 0,
            'rented'    => 0,
            'sold'      => 0,
            'inquiries' => 0,
            'views'     => 0,
            'agents'    => 0,
        ];

        if($isAdmin)
        {
            foreach($annualDates as $d)
            {
                $annualStatistics[] = [
                    'date' => $d,
                    'statistics' => $statistics
                ];
            }
    
            foreach($annualStatistics as $key => $s)
            {
                $annualStatistics[$key]['statistics'] = $this->createAnnualStatistics($s['date']);
            }
    
            return response()->json($annualStatistics);
        }
        
        $baseQuery = Listing::withCount('inQuiries')->where('agent_id', $user->agent->id);
        $statistics['active'] = (clone $baseQuery)->active()->count();
        $statistics['total']  = (clone $baseQuery)->count();
        $statistics['views']  = (int) (clone $baseQuery)->sum('clicks');
    
        $statistics['inquiries'] = \App\Models\ListingInquiry::whereIn(
            'listing_id',
            (clone $baseQuery)->select('id')
        )->count();
    
        $statistics['rented'] = (clone $baseQuery)->rented()->count();
        $statistics['sold']   = (clone $baseQuery)->sold()->count();
        $statistics['leased'] = (clone $baseQuery)->leased()->count();
        $statistics['agent'] = 1;

        return response()->json($annualStatistics);
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
                'agent' => function ($q) {
                    $q->withCount('listings');
                }
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
        DB::transaction(function () use ($listing) {
            // Delete listing first (soft-delete if model uses SoftDeletes)
            if ($listing->exists) {
                $listing->delete();
            }

            // Then delete the related property (if any)
            $property = $listing->property;
            if ($property && $property->exists) {
                $property->delete();
            }

            // Finally delete the related property attribute (if any)
            $propertyAttribute = $property->propertyAttribute ?? null;
            if ($propertyAttribute && $propertyAttribute->exists) {
                $propertyAttribute->delete();
            }
        });

        return response()->json(['message' => 'Listing and related rows soft-deleted: listings, properties, property_attributes']);
    }
}
