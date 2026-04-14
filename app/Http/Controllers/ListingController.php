<?php

namespace App\Http\Controllers;

use App\Http\Resources\ListingResourceCollection;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Illuminate\Http\Request;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\PropertySubtype;
use App\Models\Property;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;    

class ListingController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum')->except(['index', 'show', 'subtypeCounts', 'featured', 'listingsByLocation', 'resolveByKeywordsAndSlug', 'listingsByLocationAll', 'listingByCityAll']);
        $this->middleware(RoleMiddleware::class . ':agent,admin')->only(['store']);
        $this->middleware(RoleMiddleware::class . ':admin')->only(['updateIsFeatured']);
    }

    public function listingsByLocation(Request $request)
    {
        $search = $request->input("search");
        if (str_contains($search, '-')) {
            $terms = explode('-', $search);
        } else {
            $terms = explode(' ', $search);
        }
    
        $locations = Property::select(
                'properties.address_id',
                'properties.address',
                'barangays.name as barangay',
                'cities.name as city',
                'provinces.name as province',
                DB::raw('COUNT(*) as total_properties')
            )->whereHas('publicListing')
            ->join('barangays', 'barangays.id', '=', 'properties.address_id')
            ->join('cities', 'cities.id', '=', 'barangays.city_id')
            ->join('provinces', 'provinces.id', '=', 'cities.province_id')
            ->where(function ($q) use ($terms) {
                foreach ($terms as $w) {
                    $q->where('properties.address', 'LIKE', "%{$w}%");
                }
            })
            ->groupBy(
                'properties.address_id',
                'properties.address',
                'barangays.name',
                'cities.name',
                'provinces.name'
            )
            ->orderByDesc('total_properties')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'barangay_id'      => $row->address_id,
                'address'          => $row->address,
                'label'            => "{$row->barangay}, {$row->city}, {$row->province}",
                'total_properties' => $row->total_properties,
            ]);
    
        return response()->json($locations);
    }

    public function listingsByLocationAll()
    {    
        $locations = Property::select(
                'properties.address_id',
                'properties.address',
                'barangays.name as barangay',
                'cities.name as city',
                'provinces.name as province',
                DB::raw('COUNT(*) as total_properties')
            )->whereHas('publicListing')
            ->join('barangays', 'barangays.id', '=', 'properties.address_id')
            ->join('cities', 'cities.id', '=', 'barangays.city_id')
            ->join('provinces', 'provinces.id', '=', 'cities.province_id')
            ->groupBy(
                'properties.address_id',
                'properties.address',
                'barangays.name',
                'cities.name',
                'provinces.name'
            )
            ->orderByDesc('total_properties')
            ->get()
            ->map(fn($row) => [
                'barangay_id'      => $row->address_id,
                'address'          => $row->address,
                'label'            => "{$row->barangay}, {$row->city}, {$row->province}",
                'total_properties' => $row->total_properties,
            ]);
    
        return response()->json($locations);
    }

    public function listingByCityAll()
    {    
        $cities = Property::select(
                'cities.name as city',
                'provinces.name as province',
            )->whereHas('publicListing')
            ->join('barangays', 'barangays.id', '=', 'properties.address_id')
            ->join('cities', 'cities.id', '=', 'barangays.city_id')
            ->join('provinces', 'provinces.id', '=', 'cities.province_id')
            ->groupBy('cities.id')
            ->get();
    
        return response()->json($cities);
    }
    
    public function index(Request $request): ListingResourceCollection
    {
        $listings = Listing::where('visibility', 'public')
        ->with([
            'property.propertyAttribute.subtype',
            'property.nearbyFacility',
            'category',
            'agent' => function ($q) {
                $q->withCount('listings');
            }
        ])
        ->withCount([
            'property as subtype_count' => function ($q) {
                $q->whereHas('propertyAttribute.subtype');
            }
        ])
        ->filter($request)
        ->orderByDesc('updated_at')
        ->orderByDesc('subtype_count')
        ->paginate(12);

        return new ListingResourceCollection($listings);
    }

    public function resolveByKeywordsAndSlug(Request $request)
    {   
        $listing = null;
        if($slug = $request->input('slug'))
        {
            $currListing = Listing::where('slug', $slug)->first();

            if($currListing)
            {
                $deviceId = $request->input('device_id');
                $cacheKey = "listing_{$currListing->id}_clicked_by_{$deviceId}";
            
                if (!Cache::has($cacheKey)) {
                    $currListing->timestamps = false;
                    $currListing->increment('clicks');
                    $currListing->timestamps = true;
                    Cache::put($cacheKey, true, now()->addDay());
                }
    
                $listing = Listing::where('slug', $slug)->where('visibility', 'public')
                ->with([
                    'property.propertyAttribute.subtype',
                    'property.nearbyFacility',
                    'category',
                    'agent' => function ($q) {
                        $q->withCount('listings');
                    }
                ])
                ->withCount([
                    'property as subtype_count' => function ($q) {
                        $q->whereHas('propertyAttribute.subtype');
                    }
                ])->first();
            }
        }

        return [
            'property' => $listing === null ? null : new ListingResource($listing),
            'resource' => $this->index($request),
        ];
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
        $featured   = $request->input('featured'); // '1' | '0' | 'true' | 'false' | 'all' | null

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

        $applyFeatured = function ($q) use ($featured) {
            if ($featured === null || $featured === '' || strtolower((string)$featured) === 'all') return $q;
            $normalized = strtolower((string) $featured);
            $bool = null;
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                $bool = true;
            } elseif (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                $bool = false;
            } else {
                $parsed = filter_var($featured, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed !== null) $bool = $parsed;
            }
            if ($bool === true)  return $q->where('listings.is_featured', 1);
            if ($bool === false) return $q->where(function($qq){
                $qq->where('listings.is_featured', 0)->orWhereNull('listings.is_featured');
            });
            return $q;
        };

        // ── Status counts: respect visibility + category filters, NOT status ──
        $statusBase = $applyFeatured($applyCategory($applyVisibility(clone $query)));
        $statusCounts = [
            'active' => (clone $statusBase)->active()->count(),
            'rented' => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'rented'))->count(),
            'sold'   => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'sold'))->count(),
            'leased' => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'leased'))->count(),
        ];

        // ── Visibility counts: respect status + category filters, NOT visibility ──
        $visibilityBase = $applyFeatured($applyCategory($applyStatus(clone $query)));
        $visibilityCounts = (clone $visibilityBase)
            ->selectRaw('visibility, COUNT(*) as count')
            ->groupBy('visibility')
            ->pluck('count', 'visibility')
            ->toArray();

        // ── Category counts: respect status + visibility filters, NOT category ──
        $categoryBase = $applyFeatured($applyStatus($applyVisibility(clone $query)));
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
        $query = $applyFeatured($query);

        $listings = $query
            ->with([
                'property.propertyAttribute.subtype',
                'property.nearbyFacility',
                'category',
                'agent' => function ($q) {
                    $q->withCount('listings');
                }
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(12);

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
        $featured   = $request->input('featured'); // '1' | '0' | 'true' | 'false' | 'all' | null

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

        $applyFeatured = function ($q) use ($featured) {
            if ($featured === null || $featured === '' || strtolower((string)$featured) === 'all') return $q;
            $normalized = strtolower((string) $featured);
            $bool = null;
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                $bool = true;
            } elseif (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                $bool = false;
            } else {
                $parsed = filter_var($featured, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed !== null) $bool = $parsed;
            }
            if ($bool === true)  return $q->where('listings.is_featured', 1);
            if ($bool === false) return $q->where(function($qq){
                $qq->where('listings.is_featured', 0)->orWhereNull('listings.is_featured');
            });
            return $q;
        };

        // ── Status counts: respect visibility + category filters, NOT status ──
        $statusBase = $applyFeatured($applyCategory($applyVisibility(clone $query)));
        $statusCounts = [
            'active' => (clone $statusBase)->active()->count(),
            'rented' => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'rented'))->count(),
            'sold'   => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'sold'))->count(),
            'leased' => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'leased'))->count(),
        ];

        // ── Visibility counts: respect status + category filters, NOT visibility ──
        $visibilityBase = $applyFeatured($applyCategory($applyStatus(clone $query)));
        $visibilityCounts = (clone $visibilityBase)
            ->selectRaw('visibility, COUNT(*) as count')
            ->groupBy('visibility')
            ->pluck('count', 'visibility')
            ->toArray();

        // ── Category counts: respect status + visibility filters, NOT category ──
        $categoryBase = $applyFeatured($applyStatus($applyVisibility(clone $query)));
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
        $query = $applyFeatured($query);

        $listings = $query
            ->with([
                'property.propertyAttribute.subtype',
                'property.nearbyFacility',
                'category',
                'agent' => function ($q) {
                    $q->withCount('listings');
                }
            ])
            ->orderBy('created_at', 'desc')
            ->paginate($request->query('per_page', 10));

        return (new ListingResourceCollection($listings))->additional([
            'counts' => [
                'status'     => $statusCounts,
                'visibility' => $visibilityCounts,
                'category'   => $categoryCounts,
            ],
        ]);
    }

    public function createPropertyStatistics($start, $end, $typeId)
    {    
        $baseQuery = Listing::whereHas('property.propertyAttribute', function($q) use($typeId){
            $q->where('property_subtype_id', $typeId);
        })->whereBetween('created_at', [$start, $end]);

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
    
        return $statistics;
    }

    public function propertyStatistics($start, $end)
    {
        $subTypes = PropertySubtype::get();
        $statistics = [];
        $totals = [
            'active'    => 0,
            'total'     => 0,
            'rented'    => 0,
            'sold'      => 0,
            'leased'    => 0,
            'inquiries' => 0,
            'views'     => 0,
        ];


        foreach($subTypes as $st)
        {
            $stat = $this->createPropertyStatistics($start, $end, $st->id);
            $statistics[] = [
                'type' => $st->type->name,
                'subType' => $st->name,
                'statistics' => $stat
            ];

            foreach ($totals as $key => $value) {
                $totals[$key] += $stat[$key] ?? 0;
            }
        }

        return [
            'data' => $statistics,
            'totals' => $totals
        ];
    }

    public function dashboard(Request $request)
    {   
        $user = $request->user();
        $isAdmin = $user->role->name === 'admin';
        $start = date('2020-01-01');
        $end = date('Y-m-d');
        $statistics = [
            'active'    => 0,
            'total'     => 0,
            'rented'    => 0,
            'sold'      => 0,
            'inquiries' => 0,
            'views'     => 0,
            'agents'    => 0,
        ];

        if(isset($request->date_start) && isset($request->date_start))
        {
            $start = $request->date_start;
            $end = $request->date_end;
        }

        if($isAdmin)
        {
            $agentCount = \App\Models\Agent::whereBetween('member_since', [$start, $end])->count();
            $propertyStatistics = $this->propertyStatistics($start, $end);

            return [
                'agents' => $agentCount,
                'properties' => $propertyStatistics
            ];
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

        return response()->json($statistics);
    }

public function dashboardStatusByDate(Request $request): JsonResponse
{
    $validated = $request->validate([
        'date_start'  => 'nullable|date',
        'date_end'    => 'nullable|date|after_or_equal:date_start',
        'granularity' => 'nullable|in:day,month,year',
    ]);

    $user    = $request->user();
    $isAdmin = Gate::forUser($user)->allows('view-all-dashboard');
    $start   = $validated['date_start'] ?? now()->startOfYear()->toDateString();
    $end     = $validated['date_end']   ?? now()->toDateString();
    $gran    = $validated['granularity'] ?? 'day';
    $statuses = ['rented', 'sold', 'leased'];

    $emptyResponse = fn() => response()->json([
        'data'   => [],
        'totals' => array_fill_keys($statuses, 0),
        'meta'   => ['granularity' => $gran, 'from' => $start, 'to' => $end],
    ]);

    if (!$isAdmin) {
        $user->loadMissing('agent');
        $agentId = $user->agent?->id;
        if (!$agentId) return $emptyResponse();
    }

    if ($gran === 'month') {
        $dateExpr   = DB::raw("DATE_FORMAT(properties.status_change_date, '%Y-%m-01') as date");
        $groupByRaw = "DATE_FORMAT(properties.status_change_date, '%Y-%m-01'), properties.status";
    } elseif ($gran === 'year') {
        $dateExpr   = DB::raw("DATE_FORMAT(properties.status_change_date, '%Y-01-01') as date");
        $groupByRaw = "DATE_FORMAT(properties.status_change_date, '%Y-01-01'), properties.status";
    } else {
        $dateExpr   = DB::raw('DATE(properties.status_change_date) as date');
        $groupByRaw = "DATE(properties.status_change_date), properties.status";
    }

    $cacheKey = 'dashboard_status:' . ($isAdmin ? 'admin' : "agent_{$agentId}") . ":{$user->id}:{$gran}:{$start}:{$end}";

    $rows = Cache::remember($cacheKey, now()->addMinutes(15), function () use (
        $dateExpr, $groupByRaw, $statuses, $start, $end, $isAdmin, $agentId
    ) {
        $query = DB::table('properties')
            ->select([$dateExpr, 'properties.status', DB::raw('COUNT(*) as count')])
            ->whereIn('properties.status', $statuses)
            ->whereNotNull('properties.status_change_date')
            ->whereBetween(DB::raw('DATE(properties.status_change_date)'), [$start, $end]);

        if (!$isAdmin) {
            $query->join('listings', 'listings.property_id', '=', 'properties.id')
                  ->where('listings.agent_id', $agentId);
        }

        return $query
            ->groupByRaw($groupByRaw)
            ->orderByRaw($groupByRaw)
            ->get();
    });

    $byDate = [];
    $totals = array_fill_keys($statuses, 0);

    foreach ($rows as $row) {
        $date   = (string) $row->date;
        $status = (string) $row->status;
        $count  = (int)    $row->count;

        $byDate[$date] ??= array_merge(['date' => $date], array_fill_keys($statuses, 0), ['total' => 0]);
        $byDate[$date][$status] += $count;
        $byDate[$date]['total'] += $count;
        $totals[$status]        += $count;
    }

    return response()->json([
        'data'   => array_values($byDate),
        'totals' => $totals,
        'meta'   => ['granularity' => $gran, 'from' => $start, 'to' => $end],
    ]);
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
            'status'              => 'required|in:active,rented,sold,leased',
            'status_change_date'  => 'required|date',
            'status_remark'       => 'nullable|string',
        ]);

        $listing->property->update($data);

        return response()->json([
            'status'             => $listing->property->status,
            'status_change_date' => $listing->property->status_change_date,
            'status_remark'      => $listing->property->status_remark,
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
                'property.nearbyFacility',
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
                'property.nearbyFacility',
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