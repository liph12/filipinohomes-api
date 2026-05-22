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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\ListingFlaggedMailer;
use App\Mail\ListingVerifiedMailer;
use App\Services\Listing\ListingInsightsService;
use App\Services\TeamLeadershipService;
use Illuminate\Support\Facades\Event;
use OwenIt\Auditing\Events\AuditCustom;

class ListingController extends Controller
{
    private function dashboardBucketLabel(string $bucketStart, string $granularity): string
    {
        return match ($granularity) {
            'year' => substr($bucketStart, 0, 4),
            'month' => date('M Y', strtotime($bucketStart)),
            default => date('M j, Y', strtotime($bucketStart)),
        };
    }

    public function __construct()
    {
        $this->middleware('auth:sanctum')->except(['index', 'show', 'subtypeCounts', 'featured', 'listingsByLocation', 'resolveByKeywordsAndSlug', 'listingsByLocationAll', 'listingByCityAll', 'sitemapSearchLocations']);
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

    public function sitemapSearchLocations(Request $request)
    {
        $perPage = (int) $request->input('per_page', 1000);
        $minTotal = max(1, (int) $request->input('min_total', 1));

        $locations = Property::select(
            'properties.address',
            DB::raw('COUNT(*) as total_properties')
        )->whereHas('publicListing')
            ->join('barangays', 'barangays.id', '=', 'properties.address_id')
            ->join('cities', 'cities.id', '=', 'barangays.city_id')
            ->join('provinces', 'provinces.id', '=', 'cities.province_id')
            ->groupBy('properties.address')
            ->having('total_properties', '>=', $minTotal)
            ->orderByDesc('total_properties')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($locations->items())->map(fn($row) => [
                'address' => $row->address,
                'slug'    => \Illuminate\Support\Str::slug($row->address),
                'total'   => $row->total_properties,
            ]),
            'meta' => [
                'current_page' => $locations->currentPage(),
                'last_page'    => $locations->lastPage(),
                'total'        => $locations->total(),
            ],
        ]);
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
        if ($slug = $request->input('slug')) {
            $currListing = Listing::whereRaw('LOWER(slug) = ?', [strtolower($slug)])->first();

            if ($currListing) {
                $deviceId = $request->input('device_id');
                $cacheKey = "listing_{$currListing->id}_clicked_by_{$deviceId}";

                if (!Cache::has($cacheKey)) {
                    $currListing->timestamps = false;
                    $currListing->increment('clicks');
                    $currListing->timestamps = true;
                    Cache::put($cacheKey, true, now()->addDay());
                }

                $listing = Listing::whereRaw('LOWER(slug) = ?', [strtolower($slug)])->where('visibility', 'public')
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

        // Serialize the paginated index() result through ->response() so we
        // get both data + Laravel's default paginator meta (current_page,
        // last_page, per_page, total). Embedding the ResourceCollection
        // directly drops the meta and only exposes the items array.
        $indexResponse = $this->index($request)->response()->getData(true);
        $indexMeta = $indexResponse['meta'] ?? [];

        return [
            'property' => $listing === null ? null : new ListingResource($listing),
            'resource' => $indexResponse['data'] ?? [],
            'meta' => [
                'page'      => $indexMeta['current_page'] ?? 1,
                'per_page'  => $indexMeta['per_page'] ?? 12,
                'total'     => $indexMeta['total'] ?? 0,
                'last_page' => $indexMeta['last_page'] ?? 1,
            ],
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
        $atsStatus  = $request->input('ats_status'); // 'pending' | 'approved' | 'expired'

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
            if ($bool === false) return $q->where(function ($qq) {
                $qq->where('listings.is_featured', 0)->orWhereNull('listings.is_featured');
            });
            return $q;
        };

        // ATS status filter maps 'approved' -> 'approve' in DB
        $applyAtsStatus = function ($q) use ($atsStatus) {
            if (!$atsStatus || strtolower((string)$atsStatus) === 'all') return $q;
            $dbVal = strtolower((string)$atsStatus) === 'approved' ? 'approve' : strtolower((string)$atsStatus);
            return $q->whereHas('property', fn($sub) => $sub->where('ats_status', $dbVal));
        };

        // ── Status counts: respect visibility + category filters, NOT status ──
        $statusBase = $applyAtsStatus($applyFeatured($applyCategory($applyVisibility(clone $query))));
        $statusCounts = [
            'active' => (clone $statusBase)->active()->count(),
            'rented' => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'rented'))->count(),
            'sold'   => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'sold'))->count(),
            'leased' => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'leased'))->count(),
        ];

        // ── Visibility counts: respect status + category filters, NOT visibility ──
        $visibilityBase = $applyAtsStatus($applyFeatured($applyCategory($applyStatus(clone $query))));
        $visibilityCounts = (clone $visibilityBase)
            ->selectRaw('visibility, COUNT(*) as count')
            ->groupBy('visibility')
            ->pluck('count', 'visibility')
            ->toArray();
        // Featured count: respects status/category/ats filters, not featured/visibility
        $visibilityCounts['featured'] = $applyAtsStatus($applyCategory($applyStatus(clone $query)))
            ->where('listings.is_featured', 1)->count();

        // ── Category counts: respect status + visibility filters, NOT category ──
        $categoryBase = $applyAtsStatus($applyFeatured($applyStatus($applyVisibility(clone $query))));
        $categoryCountsRaw = (clone $categoryBase)
            ->selectRaw('categories.name as category_name, COUNT(*) as count')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->groupBy('categories.id', 'categories.name')
            ->pluck('count', 'category_name')
            ->toArray();
        // Always include all categories (even those with 0 for this agent/filter)
        $allCategoryNames = \App\Models\Category::orderBy('name')->pluck('name');
        $categoryCounts = [];
        foreach ($allCategoryNames as $catName) {
            $categoryCounts[$catName] = $categoryCountsRaw[$catName] ?? 0;
        }

        // ── ATS counts: respect status + visibility + category filters, NOT ats_status ──
        $atsBase   = $applyFeatured($applyCategory($applyVisibility($applyStatus(clone $query))));
        $atsCounts = [
            'pending'  => (clone $atsBase)->whereHas('property', fn($q) => $q->where('ats_status', 'pending'))->count(),
            'approved' => (clone $atsBase)->whereHas('property', fn($q) => $q->where('ats_status', 'approve'))->count(),
            'expired'  => (clone $atsBase)->whereHas('property', fn($q) => $q->where('ats_status', 'expired'))->count(),
            'rejected' => (clone $atsBase)->whereHas('property', fn($q) => $q->where('ats_status', 'rejected'))->count(),
        ];

        // ── Apply ALL filters for pagination ─────────────────────────────────
        $query = $applyStatus($query);
        $query = $applyVisibility($query);
        $query = $applyCategory($query);
        $query = $applyFeatured($query);
        $query = $applyAtsStatus($query);
        $atsStatus = $request->input('ats_status');
        $applyAtsStatus = function ($q) use ($atsStatus) {
            if (!$atsStatus) return $q;
            return $q->whereHas('property', fn($sub) => $sub->where('ats_status', $atsStatus === 'approved' ? 'approve' : $atsStatus));
        };
        $query = $applyAtsStatus($query);

        $perPage = min((int) $request->input('per_page', 12), 500);

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
            ->paginate($perPage);

        return (new ListingResourceCollection($listings))->additional([
            'counts' => [
                'status'     => $statusCounts,
                'visibility' => $visibilityCounts,
                'category'   => $categoryCounts,
                'ats'        => $atsCounts,
            ],
        ]);
    }

    public function allListings(Request $request)
    {
        $user = $request->user();

        // Admin sees every listing. A team leader (agent role + is_leader on
        // some team) sees only listings owned by their own team — their own
        // listings included. Anyone else is rejected.
        $isAdmin = $user->role->name === 'admin';
        $ledAgentIds = [];
        if (!$isAdmin) {
            $ledAgentIds = app(TeamLeadershipService::class)->getLedAgentIds($user->id);
            if (empty($ledAgentIds)) abort(403);
        }

        $query = Listing::query();
        if (!$isAdmin) {
            $query->whereIn('agent_id', $ledAgentIds);
        }

        // Optional single-agent filter. Drives the "View Listings" dialog
        // launched from the Agents directory — admins can scope to any
        // agent, team leaders only to agents they lead. Anyone else is
        // already blocked by the abort(403) above.
        if ($request->filled('agent_id')) {
            $aid = (int) $request->input('agent_id');
            if (!$isAdmin && !in_array($aid, $ledAgentIds, true)) {
                abort(403);
            }
            $query->where('agent_id', $aid);
        }

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
        $subtypes   = $request->input('subtypes'); // array or comma-separated ids

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
            if ($bool === false) return $q->where(function ($qq) {
                $qq->where('listings.is_featured', 0)->orWhereNull('listings.is_featured');
            });
            return $q;
        };

        // Subtype filter via ids (comma-separated or array)
        $applySubtypes = function ($q) use ($subtypes) {
            if (!$subtypes) return $q;
            $ids = is_array($subtypes) ? $subtypes : explode(',', (string)$subtypes);
            $ids = array_filter(array_map('intval', $ids));
            if (empty($ids)) return $q;
            return $q->whereHas('property.propertyAttribute.subtype', fn($sub) => $sub->whereIn('id', $ids));
        };

        // ── Status counts: respect visibility + category filters, NOT status ──
        $statusBase = $applySubtypes($applyFeatured($applyCategory($applyVisibility(clone $query))));
        $statusCounts = [
            'active' => (clone $statusBase)->active()->count(),
            'rented' => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'rented'))->count(),
            'sold'   => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'sold'))->count(),
            'leased' => (clone $statusBase)->whereHas('property', fn($q) => $q->where('status', 'leased'))->count(),
        ];

        // ── Visibility counts: respect status + category filters, NOT visibility ──
        $visibilityBase = $applySubtypes($applyFeatured($applyCategory($applyStatus(clone $query))));
        $visibilityCounts = (clone $visibilityBase)
            ->selectRaw('visibility, COUNT(*) as count')
            ->groupBy('visibility')
            ->pluck('count', 'visibility')
            ->toArray();
        // Featured count: respects status/category/subtypes filters, not featured/visibility
        $visibilityCounts['featured'] = $applySubtypes($applyCategory($applyStatus(clone $query)))
            ->where('listings.is_featured', 1)->count();

        // ── Category counts: respect status + visibility filters, NOT category ──
        $categoryBase = $applySubtypes($applyFeatured($applyStatus($applyVisibility(clone $query))));
        $categoryCounts = (clone $categoryBase)
            ->selectRaw('categories.name as category_name, COUNT(*) as count')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->groupBy('categories.id', 'categories.name')
            ->pluck('count', 'category_name')
            ->toArray();

        // ── ATS counts: respect status + visibility + category filters, NOT ats_status ──
        $atsBase   = $applySubtypes($applyFeatured($applyCategory($applyVisibility($applyStatus(clone $query)))));
        $atsCounts = [
            'pending'  => (clone $atsBase)->whereHas('property', fn($q) => $q->where('ats_status', 'pending'))->count(),
            'approved' => (clone $atsBase)->whereHas('property', fn($q) => $q->where('ats_status', 'approve'))->count(),
            'expired'  => (clone $atsBase)->whereHas('property', fn($q) => $q->where('ats_status', 'expired'))->count(),
            'rejected' => (clone $atsBase)->whereHas('property', fn($q) => $q->where('ats_status', 'rejected'))->count(),
        ];

        // ── Verification status filter ────────────────────────────────────────
        $verificationStatus = $request->input('verification_status');
        $applyVerification = function ($q) use ($verificationStatus) {
            if (!$verificationStatus) return $q;
            if ($verificationStatus === 'unverified') {
                return $q->whereNull('verification_status');
            }
            return $q->where('verification_status', $verificationStatus);
        };

        // ── Verification counts (independent of verification_status filter) ──
        $verificationBase = $applySubtypes($applyFeatured($applyCategory($applyVisibility($applyStatus(clone $query)))));
        $verificationCounts = [
            'unverified'     => (clone $verificationBase)->whereNull('verification_status')->count(),
            'verified'       => (clone $verificationBase)->where('verification_status', 'verified')->count(),
            'fully_verified' => (clone $verificationBase)->where('verification_status', 'fully_verified')->count(),
            'flagged'        => (clone $verificationBase)->where('verification_status', 'flagged')->count(),
            'pending_review' => (clone $verificationBase)->where('verification_status', 'pending_review')->count(),
        ];

        // ── Apply ALL filters for pagination ─────────────────────────────────
        $query = $applyStatus($query);
        $query = $applyVisibility($query);
        $query = $applyCategory($query);
        $query = $applyFeatured($query);
        $query = $applySubtypes($query);
        $query = $applyVerification($query);
        // Date-from filter (audit queue: April 2026+)
        if ($dateFrom = $request->input('date_from')) {
            $query->where('listings.created_at', '>=', $dateFrom . ' 00:00:00');
        }
        $atsStatus = $request->input('ats_status');
        $applyAtsStatus = function ($q) use ($atsStatus) {
            if (!$atsStatus) return $q;
            return $q->whereHas('property', fn($sub) => $sub->where('ats_status', $atsStatus === 'approved' ? 'approve' : $atsStatus));
        };
        $query = $applyAtsStatus($query);

        $totalViews = (int) (clone $query)->sum('clicks');

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
            ->paginate($request->query('per_page', 12));

        return (new ListingResourceCollection($listings))->additional([
            'counts' => [
                'status'       => $statusCounts,
                'visibility'   => $visibilityCounts,
                'category'     => $categoryCounts,
                'ats'          => $atsCounts,
                'views'        => $totalViews,
                'verification' => $verificationCounts,
            ],
        ]);
    }

    public function createPropertyStatistics($start, $end, $typeId)
    {
        $baseQuery = Listing::whereHas('property.propertyAttribute', function ($q) use ($typeId) {
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


        foreach ($subTypes as $st) {
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

        if (isset($request->date_start) && isset($request->date_start)) {
            $start = $request->date_start;
            $end = $request->date_end;
        }

        if ($isAdmin) {
            $agentCount = \App\Models\Agent::whereBetween('member_since', [$start, $end])->count();
            $propertyStatistics = $this->propertyStatistics($start, $end);

            return [
                'agents' => $agentCount,
                'properties' => $propertyStatistics,
                'private_listings' => Listing::where('visibility', 'private')->count(),
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

        $statistics['rented']           = (clone $baseQuery)->rented()->count();
        $statistics['sold']             = (clone $baseQuery)->sold()->count();
        $statistics['leased']           = (clone $baseQuery)->leased()->count();
        $statistics['private_listings'] = (clone $baseQuery)->where('visibility', 'private')->count();
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
        $user->loadMissing('role', 'agent');
        $isAdmin = $user->role?->name === 'admin';
        $agentId = $user->agent?->id;
        $start   = $validated['date_start'] ?? now()->startOfYear()->toDateString();
        $end     = $validated['date_end']   ?? now()->toDateString();
        $gran    = $validated['granularity'] ?? 'day';
        $statuses = ['rented', 'sold', 'leased'];

        $emptyResponse = fn() => response()->json([
            'data'   => [],
            'totals' => array_merge(array_fill_keys($statuses, 0), ['total' => 0]),
            'meta'   => ['granularity' => $gran, 'from' => $start, 'to' => $end],
        ]);

        if (!$isAdmin) {
            if (!$agentId) return $emptyResponse();
        }

        $dateExpr   = DB::raw('properties.status_change_date as date');
        $groupByRaw = 'properties.status_change_date, properties.status';

        $rows = (function () use (
            $dateExpr,
            $groupByRaw,
            $statuses,
            $start,
            $end,
            $isAdmin,
            $agentId
        ) {
            $query = Property::query()
                ->select([$dateExpr, 'properties.status', DB::raw('COUNT(*) as count')])
                ->whereIn('properties.status', $statuses)
                ->whereNotNull('properties.status_change_date')
                ->whereBetween('properties.status_change_date', [$start, $end]);

            if (!$isAdmin) {
                $query->whereHas('listings', fn($sub) => $sub->where('agent_id', $agentId));
            }

            return $query
                ->groupByRaw($groupByRaw)
                ->orderByRaw($groupByRaw)
                ->get();
        })();

        $byDate = [];
        $totals = array_fill_keys($statuses, 0);

        foreach ($rows as $row) {
            $bucketStart   = match ($gran) {
                'year' => substr((string) $row->date, 0, 4) . '-01-01',
                'month' => substr((string) $row->date, 0, 7) . '-01',
                default => (string) $row->date,
            };
            $status = (string) $row->status;
            $count  = (int)    $row->count;

            $byDate[$bucketStart] ??= array_merge([
                'bucket_start' => $bucketStart,
                'bucket_label' => $this->dashboardBucketLabel($bucketStart, $gran),
            ], array_fill_keys($statuses, 0), ['total' => 0]);
            $byDate[$bucketStart][$status] += $count;
            $byDate[$bucketStart]['total'] += $count;
            $totals[$status]               += $count;
        }

        return response()->json([
            'data'   => array_values(array_map(function ($bucket) use ($statuses) {
                $counts = ['total' => $bucket['total']];
                foreach ($statuses as $status) {
                    $counts[$status] = $bucket[$status];
                }

                return [
                    'bucket_start' => $bucket['bucket_start'],
                    'bucket_label' => $bucket['bucket_label'],
                    'counts'       => $counts,
                ];
            }, $byDate)),
            'totals' => array_merge($totals, ['total' => array_sum($totals)]),
            'meta'   => ['granularity' => $gran, 'from' => $start, 'to' => $end],
        ]);
    }

    public function updateVisibility(Request $request, Listing $listing)
    {
        $this->authorize('update', $listing);

        $request->validate([
            'visibility' => 'required|in:public,private',
        ]);

        $listing->auditSource = 'visibility_toggle';
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

        $listing->property->auditSource = 'status_change';
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

        $listing->auditSource = 'featured_toggle';
        $listing->update(['is_featured' => $data['is_featured']]);

        $listing = $listing->fresh();

        return response()->json([
            'is_featured'    => (bool) $listing->is_featured,
        ]);
    }

    public function updateVerification(Request $request, Listing $listing)
    {
        $user = $request->user();
        // Admins audit anything. Team leaders audit listings owned by anyone
        // on their team (their own listings included). Everyone else is
        // rejected. Pattern mirrors ConversationPolicy::moderate().
        $isAdmin = $user->role->name === 'admin';
        if (!$isAdmin) {
            $ledAgentIds = app(TeamLeadershipService::class)->getLedAgentIds($user->id);
            if (empty($ledAgentIds) || !in_array((int) $listing->agent_id, $ledAgentIds, true)) {
                abort(403);
            }
        }

        $validated = $request->validate([
            'verification_status' => 'nullable|in:verified,fully_verified,flagged,pending_review',
            'audit_notes'         => 'nullable|string|max:2000',
            'audit_checklist'     => 'nullable|array',
            'edited_fields'       => 'nullable|array',
        ]);

        $previousVerification = $listing->verification_status;

        // Skip the default 'updated' audit — we'll fire a custom 'audited' one
        // below so audit decisions surface as their own event under
        // category=listings_audit instead of just another listing update.
        $listing->updateQuietly([
            'verification_status' => $validated['verification_status'] ?? null,
            'audit_notes'         => $validated['audit_notes'] ?? null,
            'audit_checklist'     => $validated['audit_checklist'] ?? null,
            'audit_edited_fields' => $validated['edited_fields'] ?? null,
            'audited_by'          => $request->user()->id,
            'audited_at'          => now(),
        ]);

        $listing->auditEvent             = 'audited';
        $listing->isCustomEvent          = true;
        $listing->auditCategoryOverride  = 'listings_audit';
        $listing->auditSource            = 'audit_modal';
        $listing->auditDescription       = "Audit: {$previousVerification} → "
            . ($validated['verification_status'] ?? 'cleared');
        $listing->auditCustomOld         = ['verification_status' => $previousVerification];
        $listing->auditCustomNew         = [
            'verification_status' => $validated['verification_status'] ?? null,
            'audit_notes'         => $validated['audit_notes'] ?? null,
            'audit_checklist'     => $validated['audit_checklist'] ?? null,
            'edited_fields'       => $validated['edited_fields'] ?? null,
        ];
        // owen-it v14's AuditCustom takes the Auditable in its constructor —
        // it must be dispatched as an instance, not via the (class, payload)
        // form, otherwise the listener gets the model instead of the event.
        Event::dispatch(new AuditCustom($listing));

        // Notify agent by email for flagged (action required) or verified
        // (congrats + list what's still needed for Fully Verified). Fully-
        // verified status does not email — there's nothing else for the agent
        // to act on once everything is checked.
        $status = $validated['verification_status'] ?? null;
        $emailSent = false;
        if ($status === 'flagged' || $status === 'verified') {
            try {
                $listing->load('agent.user.role', 'property.propertyAttribute.subtype.type');
                $agentUser = optional($listing->agent)->user;
                // Property type drives the amenities row in the email blade.
                // Land has no amenities, so we hide that line from the checklist.
                $typeName = strtolower((string) optional(optional(optional(
                    optional($listing->property)->propertyAttribute
                )->subtype)->type)->name);
                $isLand = $typeName === 'land';

                if ($agentUser && $agentUser->email) {
                    $roleSegment = optional($agentUser->role)->name === 'admin' ? 'admin' : 'agent';
                    $listingUrl = 'https://filipinohomes.com/' . $roleSegment . '/create-listing'
                        . '?edit=' . $listing->id;

                    $mailable = $status === 'flagged'
                        ? new ListingFlaggedMailer(
                            agentName:      $agentUser->name ?? 'Agent',
                            listingTitle:   $listing->name,
                            listingCode:    $listing->code,
                            auditNotes:     $validated['audit_notes'] ?? '',
                            auditChecklist: $validated['audit_checklist'] ?? null,
                            listingUrl:     $listingUrl,
                            editedFields:   $validated['edited_fields'] ?? null,
                            isLand:         $isLand,
                        )
                        : new ListingVerifiedMailer(
                            agentName:      $agentUser->name ?? 'Agent',
                            listingTitle:   $listing->name,
                            listingCode:    $listing->code,
                            auditNotes:     $validated['audit_notes'] ?? '',
                            auditChecklist: $validated['audit_checklist'] ?? null,
                            listingUrl:     $listingUrl,
                            isLand:         $isLand,
                        );

                    Mail::to($agentUser->email)->send($mailable);
                    $emailSent = true;
                    // Positive log so we can confirm sends in storage/logs/laravel.log
                    // without checking the inbox. Pairs with the warning below.
                    Log::info('Listing verification email sent', [
                        'listing_id' => $listing->id,
                        'status'     => $status,
                        'to'         => $agentUser->email,
                        'is_land'    => $isLand,
                    ]);
                } else {
                    Log::warning('Listing verification email skipped — no agent user/email', [
                        'listing_id'    => $listing->id,
                        'status'        => $status,
                        'has_agent'     => (bool) $listing->agent,
                        'has_user'      => (bool) $agentUser,
                    ]);
                }
            } catch (\Throwable $e) {
                // Non-fatal — audit status was saved, mail failure should not roll it back
                Log::warning('Listing verification email failed', [
                    'listing_id' => $listing->id,
                    'status'     => $status,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['data' => $listing->fresh(), 'email_sent' => $emailSent]);
    }

    /**
     * Audit activity feed. Surfaces listings that have any kind of
     * audit-related change recorded — agent edits on a flagged listing
     * (agent_edited_fields), admin edits during audit (audit_edited_fields),
     * or a completed audit decision (audited_at). Sorted by the most recent
     * of those timestamps so the top of the list is "what just happened".
     *
     * Visibility:
     *   - Admin: every listing's activity.
     *   - Team leader (agent role + is_leader=true): only their team's
     *     activity (own listings + every team member's listings).
     *   - Anyone else: 403.
     */
    public function auditFeed(Request $request)
    {
        $user = $request->user();

        $isAdmin = $user->role->name === 'admin';
        $ledAgentIds = [];
        if (!$isAdmin) {
            $ledAgentIds = app(TeamLeadershipService::class)->getLedAgentIds($user->id);
            if (empty($ledAgentIds)) abort(403);
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min(100, $perPage));

        $query = Listing::query()
            ->where(function ($q) {
                $q->whereNotNull('agent_edited_fields')
                  ->orWhereNotNull('audit_edited_fields')
                  ->orWhereNotNull('audited_at');
            })
            // Most-recent activity first. Either field can be null so we
            // coalesce to an ancient date before taking the greatest.
            ->orderByRaw("GREATEST(COALESCE(re_submitted_at, '1970-01-01'), COALESCE(audited_at, '1970-01-01')) DESC")
            ->with([
                'agent.user',
                'property.propertyAttribute.subtype.type',
                'category',
                'auditedBy',
            ]);

        if (!$isAdmin) {
            $query->whereIn('agent_id', $ledAgentIds);
        }

        return new ListingResourceCollection($query->paginate($perPage));
    }

    public function show(string $slug)
    {
        $listing = Listing::whereRaw('LOWER(slug) = ?', [strtolower($slug)])
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
            ->where(function ($q) {
                $q->whereNull('featured_until')->orWhere('featured_until', '>=', now());
            })
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

        $listing->auditSource = 'quick_edit';
        $listing->update($validated);

        return new ListingResource($listing);
    }

    public function destroy(Request $request, Listing $listing)
    {
        $this->authorize('delete', $listing);

        $listing->auditSource = 'listings_table';

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

    /**
     * Listing Insights — province breakdown. Mirrors ProjectController::byProvince
     * but counts ALL listings (project + standalone) and uses listing-row counts.
     */
    public function insightsByProvince(Request $request, ListingInsightsService $insights): JsonResponse
    {
        if (($request->user()->role->name ?? null) !== 'admin') {
            abort(403);
        }

        $sortBy = (string) $request->query('sort_by', 'city_count');
        return response()->json($insights->provinceBreakdown($sortBy));
    }

    /**
     * Listing Insights — status breakdown. One row per properties.status with
     * category mix + top provinces.
     */
    public function insightsByStatus(Request $request, ListingInsightsService $insights): JsonResponse
    {
        if (($request->user()->role->name ?? null) !== 'admin') {
            abort(403);
        }

        $sortBy = (string) $request->query('sort_by', 'priority');
        return response()->json($insights->statusBreakdown($sortBy));
    }

    /**
     * Listing Insights — paginated listings for a single status. Used by the
     * "Listings by Status" drawer drill-down.
     */
    public function insightsListingsForStatus(Request $request, ListingInsightsService $insights, string $status): JsonResponse
    {
        if (($request->user()->role->name ?? null) !== 'admin') {
            abort(403);
        }

        return response()->json($insights->listingsForStatus($status, [
            'page'        => (int) $request->query('page', 1),
            'per_page'    => (int) $request->query('per_page', 20),
            'category'    => (string) $request->query('category', ''),
            'visibility'  => (string) $request->query('visibility', ''),
            'province_id' => $request->query('province_id') !== null
                ? (int) $request->query('province_id')
                : null,
        ]));
    }

    /**
     * Per-listing audit history feed. Returns every audit row written
     * against this listing (created/updated/audited/resubmitted/deleted),
     * newest first, so the audit modal can render a scrollable timeline
     * of remarks, checklist changes, verification flips, and edited fields.
     *
     * Visibility rules mirror updateVerification():
     *   - Admin → anything.
     *   - Team leader → only listings owned by anyone on their team.
     *   - Everyone else → 403.
     */
    public function activity(Request $request, Listing $listing)
    {
        $user = $request->user();
        $isAdmin = $user->role->name === 'admin';
        if (!$isAdmin) {
            $ledAgentIds = app(TeamLeadershipService::class)->getLedAgentIds($user->id);
            if (empty($ledAgentIds) || !in_array((int) $listing->agent_id, $ledAgentIds, true)) {
                abort(403);
            }
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 50)));

        return response()->json(
            \App\Models\Audit::query()
                ->where('auditable_type', \App\Models\Listing::class)
                ->where('auditable_id', $listing->id)
                ->latest('id')
                ->paginate($perPage)
        );
    }
}
