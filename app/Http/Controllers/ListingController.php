<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RoleMiddleware;
use App\Http\Resources\ListingResource;
use App\Http\Resources\ListingResourceCollection;
use App\Mail\ListingFlaggedMailer;
use App\Mail\ListingVerifiedMailer;
use App\Models\Agent;
use App\Models\Audit;
use App\Models\Category;
use App\Models\Listing;
use App\Models\ListingInquiry;
use App\Models\Property;
use App\Models\PropertySubtype;
use App\Models\User;
use App\Services\AuditMailService;
use App\Services\ExpoPushService;
use App\Services\Listing\ListingByCityService;
use App\Services\Listing\ListingByProvinceService;
use App\Services\Listing\ListingByStatusService;
use App\Services\Listing\ListingByTypeService;
use App\Services\Listing\ListingClusterService;
use App\Services\TeamLeadershipService;
use App\Support\IslandMap;
use App\Support\RegionMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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
        $this->middleware('auth:sanctum')->except(['index', 'show', 'featured', 'listingsByLocation', 'resolveByKeywordsAndSlug', 'listingsByLocationAll', 'listingByCityAll', 'sitemapSearchLocations']);
        $this->middleware(RoleMiddleware::class.':agent,admin')->only(['store']);
        $this->middleware(RoleMiddleware::class.':admin')->only(['updateIsFeatured']);
    }

    public function listingsByLocation(Request $request)
    {
        $search = $request->input('search');
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
            ->map(fn ($row) => [
                'barangay_id' => $row->address_id,
                'address' => $row->address,
                'label' => "{$row->barangay}, {$row->city}, {$row->province}",
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
            ->map(fn ($row) => [
                'barangay_id' => $row->address_id,
                'address' => $row->address,
                'label' => "{$row->barangay}, {$row->city}, {$row->province}",
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
            'data' => collect($locations->items())->map(fn ($row) => [
                'address' => $row->address,
                'slug' => Str::slug($row->address),
                'total' => $row->total_properties,
            ]),
            'meta' => [
                'current_page' => $locations->currentPage(),
                'last_page' => $locations->lastPage(),
                'total' => $locations->total(),
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
        // Public page size is a fixed 12 — the browse grid, homepage search,
        // and programmatic typed pages all depend on that fixed layout, so the
        // default path is left untouched. The listing-detail recommendation
        // rails (Similar / More-by-Type / Related) opt into a wider candidate
        // pool via `pool`, because they filter the result client-side (by type,
        // image presence, active status) and a 12-row pool can starve a rail.
        // Clamped to 60 so a crafted value can't blow up the query.
        $perPage = $request->filled('pool')
            ? max(1, min((int) $request->input('pool'), 60))
            : 12;

        $listings = Listing::publiclyListed()
            ->with([
                // Eager-load every relation the Resource touches so a page
                // hydrates in a fixed number of queries instead of N+1 per listing:
                // PropertyResource reads barangay→city→province + furnishing;
                // PropertySubtypeResource reads ->type; AgentResource reads ->user.
                'property.propertyAttribute.subtype.type',
                // nearbyFacility is whenLoaded() in PropertyResource and the browse
                // grid card never renders it — so it's loaded only on the detail
                // page (resolveByKeywordsAndSlug), not here. Saves one query + the
                // hasMany facility rows on every browse page.
                'property.barangay.city.province',
                'property.furnishing',
                'category',
                'agent' => function ($q) {
                    $q->withCount('listings');
                },
                // withCount/withMax feed AgentResource's last_login_at + login_count
                // without an N+1 per listing's agent.
                'agent.user' => fn ($q) => $q->withCount('loginLogs')->withMax('loginLogs', 'logged_in_at'),
            ])
            ->filter($request)
            ->orderByDesc('updated_at')
            ->paginate($perPage);

        return new ListingResourceCollection($listings);
    }

    public function resolveByKeywordsAndSlug(Request $request)
    {
        $listing = null;
        $found = null;
        if ($slug = $request->input('slug')) {
            // One hydrated lookup (was two: a bare lookup for click-tracking + a
            // public lookup for display). Eager-load the full Resource graph to
            // avoid the barangay/city/province + agent.user N+1 on the detail page.
            $found = Listing::whereRaw('LOWER(slug) = ?', [strtolower($slug)])
                ->with([
                    'property.propertyAttribute.subtype.type',
                    'property.nearbyFacility',
                    'property.barangay.city.province',
                    'property.furnishing',
                    // Parent project (when the property belongs to one) for the
                    // project card shown below the agent on the detail page.
                    'property.project' => fn ($q) => $q->withCount(['properties' => fn ($p) => $p->where('is_project', true)]),
                    'category',
                    'agent' => function ($q) {
                        $q->withCount('listings');
                    },
                    'agent.user' => fn ($q) => $q->withCount('loginLogs')->withMax('loginLogs', 'logged_in_at'),
                ])
                ->first();

            if ($found) {
                $deviceId = $request->input('device_id');
                $cacheKey = "listing_{$found->id}_clicked_by_{$deviceId}";

                if (! Cache::has($cacheKey)) {
                    $found->timestamps = false;
                    $found->increment('clicks');
                    $found->timestamps = true;
                    Cache::put($cacheKey, true, now()->addDay());
                }

                // Only public listings are exposed (matches the prior visibility guard).
                $listing = $found->visibility === 'public' ? $found : null;
            }
        }

        // When the slug matched no public listing, work out WHY so the frontend
        // can show a clear "no longer available" page (HTTP 200 + noindex) instead
        // of a bare 404 — a private row ($found, not public) vs a soft-deleted row.
        // A truly unknown slug stays reason=null (genuine 404 on the frontend).
        $reason = null;            // null | 'private' | 'deleted'
        $goneListing = null;       // { name, location } for the page heading
        if ($listing === null && ! empty($slug)) {
            $row = null;
            if ($found && $found->visibility !== 'public') {
                $reason = 'private';
                $row = $found;
            } else {
                $row = Listing::onlyTrashed()
                    ->whereRaw('LOWER(slug) = ?', [strtolower($slug)])
                    ->with(['property.barangay.city'])
                    ->first();
                if ($row) {
                    $reason = 'deleted';
                }
            }
            if ($reason && $row) {
                // name was already public when indexed; location is coarse (city).
                $goneListing = [
                    'name' => $row->name,
                    'location' => optional($row->property?->barangay?->city)->name,
                ];
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
            'reason' => $reason,
            'gone_listing' => $goneListing,
            'resource' => $indexResponse['data'] ?? [],
            'meta' => [
                'page' => $indexMeta['current_page'] ?? 1,
                'per_page' => $indexMeta['per_page'] ?? 12,
                'total' => $indexMeta['total'] ?? 0,
                'last_page' => $indexMeta['last_page'] ?? 1,
            ],
        ];
    }

    public function myListings(Request $request)
    {
        $user = $request->user();

        $query = Listing::where('agent_id', $user->agent->id);

        // Soft-deleted view: when `trashed=1`, return ONLY soft-deleted
        // listings. A soft-deleted listing's property/attribute are trashed
        // too (see destroy()), so the property `whereHas` clauses below must
        // opt those rows back in.
        // `removed=1` is a subset of the soft-deleted set: only listings the
        // photo-migration soft-deleted (photos_migrated_at + migration note
        // both set). It implies the trashed view.
        $removed = filter_var($request->input('removed'), FILTER_VALIDATE_BOOLEAN);
        $trashed = filter_var($request->input('trashed'), FILTER_VALIDATE_BOOLEAN) || $removed;
        if ($trashed) {
            $query->onlyTrashed();
        }
        if ($removed) {
            $query->whereNotNull('listings.photos_migration_note');
        } elseif ($trashed) {
            // Plain deleted view excludes the photo-migration "removed" subset
            // (those carry a migration note) so the two views never overlap.
            $query->whereNull('listings.photos_migration_note');
        }

        // whereHas('property', ...) helper that opts trashed rows back in
        // when viewing the deleted set.
        $propHas = function ($q, callable $cb) use ($trashed) {
            return $q->whereHas('property', function ($sub) use ($cb, $trashed) {
                if ($trashed) {
                    $sub->withTrashed();
                }
                $cb($sub);
            });
        };

        // ── Search (applied to everything) ───────────────────────────────────
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search, $trashed) {
                $q->where('listings.name', 'like', "%{$search}%")  // ← prefix with table
                    ->orWhere('listings.code', 'like', "%{$search}%")  // ← prefix with table
                    ->orWhereHas('property', function ($sub) use ($search, $trashed) {
                        if ($trashed) {
                            $sub->withTrashed();
                        }
                        $sub->where('address', 'like', "%{$search}%");
                    });
            });
        }

        $status = $request->input('status');
        $visibility = $request->input('visibility');
        $category = $request->input('category');
        $featured = $request->input('featured'); // '1' | '0' | 'true' | 'false' | 'all' | null
        $atsStatus = $request->input('ats_status'); // 'pending' | 'approved' | 'expired'
        $subtypes = $request->input('subtypes'); // array or comma-separated ids

        // ── Helper to apply a filter to a query clone ─────────────────────────
        $applyStatus = function ($q) use ($status, $propHas) {
            if (! $status) {
                return $q;
            }

            return $propHas($q, fn ($sub) => $sub->where('status', $status));
        };

        $applyVisibility = function ($q) use ($visibility) {
            if (! $visibility) {
                return $q;
            }

            return $q->where('visibility', $visibility);
        };

        $applyCategory = function ($q) use ($category) {
            if (! $category) {
                return $q;
            }

            return $q->whereHas('category', fn ($sub) => $sub->where('name', $category));
        };

        $applyFeatured = function ($q) use ($featured) {
            if ($featured === null || $featured === '' || strtolower((string) $featured) === 'all') {
                return $q;
            }
            $normalized = strtolower((string) $featured);
            $bool = null;
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                $bool = true;
            } elseif (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                $bool = false;
            } else {
                $parsed = filter_var($featured, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed !== null) {
                    $bool = $parsed;
                }
            }
            if ($bool === true) {
                return $q->where('listings.is_featured', 1);
            }
            if ($bool === false) {
                return $q->where(function ($qq) {
                    $qq->where('listings.is_featured', 0)->orWhereNull('listings.is_featured');
                });
            }

            return $q;
        };

        // ATS status filter maps 'approved' -> 'approve' in DB
        $applyAtsStatus = function ($q) use ($atsStatus, $propHas) {
            if (! $atsStatus || strtolower((string) $atsStatus) === 'all') {
                return $q;
            }
            $dbVal = strtolower((string) $atsStatus) === 'approved' ? 'approve' : strtolower((string) $atsStatus);

            return $propHas($q, fn ($sub) => $sub->where('ats_status', $dbVal));
        };

        // Subtype filter via ids (comma-separated or array)
        $applySubtypes = function ($q) use ($subtypes) {
            if (! $subtypes) {
                return $q;
            }
            $ids = is_array($subtypes) ? $subtypes : explode(',', (string) $subtypes);
            $ids = array_filter(array_map('intval', $ids));
            if (empty($ids)) {
                return $q;
            }

            return $q->whereHas('property.propertyAttribute.subtype', fn ($sub) => $sub->whereIn('id', $ids));
        };

        $verificationStatus = $request->input('verification_status');
        $applyVerification = function ($q) use ($verificationStatus) {
            if (! $verificationStatus) {
                return $q;
            }
            if ($verificationStatus === 'unverified') {
                return $q->whereNull('verification_status');
            }

            return $q->where('verification_status', $verificationStatus);
        };

        // ── Status counts: respect visibility + category filters, NOT status ──
        $statusBase = $applySubtypes($applyAtsStatus($applyFeatured($applyCategory($applyVisibility(clone $query)))));
        $statusCounts = [
            'active' => $propHas(clone $statusBase, fn ($q) => $q->where('status', 'active'))->count(),
            'rented' => $propHas(clone $statusBase, fn ($q) => $q->where('status', 'rented'))->count(),
            'sold' => $propHas(clone $statusBase, fn ($q) => $q->where('status', 'sold'))->count(),
            'leased' => $propHas(clone $statusBase, fn ($q) => $q->where('status', 'leased'))->count(),
        ];

        // ── Visibility counts: respect status + category filters, NOT visibility ──
        $visibilityBase = $applySubtypes($applyAtsStatus($applyFeatured($applyCategory($applyStatus(clone $query)))));
        $visibilityCounts = (clone $visibilityBase)
            ->selectRaw('visibility, COUNT(*) as count')
            ->groupBy('visibility')
            ->pluck('count', 'visibility')
            ->toArray();
        // Featured count: respects status/category/ats/subtypes filters, not featured/visibility
        $visibilityCounts['featured'] = $applySubtypes($applyAtsStatus($applyCategory($applyStatus(clone $query))))
            ->where('listings.is_featured', 1)->count();

        // ── Category counts: respect status + visibility filters, NOT category ──
        $categoryBase = $applySubtypes($applyAtsStatus($applyFeatured($applyStatus($applyVisibility(clone $query)))));
        $categoryCountsRaw = (clone $categoryBase)
            ->selectRaw('categories.name as category_name, COUNT(*) as count')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->groupBy('categories.id', 'categories.name')
            ->pluck('count', 'category_name')
            ->toArray();
        // Always include all categories (even those with 0 for this agent/filter)
        $allCategoryNames = Category::orderBy('name')->pluck('name');
        $categoryCounts = [];
        foreach ($allCategoryNames as $catName) {
            $categoryCounts[$catName] = $categoryCountsRaw[$catName] ?? 0;
        }

        // ── ATS counts: respect status + visibility + category + subtypes filters, NOT ats_status ──
        $atsBase = $applySubtypes($applyFeatured($applyCategory($applyVisibility($applyStatus(clone $query)))));
        $atsCounts = [
            'pending' => $propHas(clone $atsBase, fn ($q) => $q->where('ats_status', 'pending'))->count(),
            'approved' => $propHas(clone $atsBase, fn ($q) => $q->where('ats_status', 'approve'))->count(),
            'expired' => $propHas(clone $atsBase, fn ($q) => $q->where('ats_status', 'expired'))->count(),
            'rejected' => $propHas(clone $atsBase, fn ($q) => $q->where('ats_status', 'rejected'))->count(),
        ];

        // ── Verification counts: respect all other filters, NOT verification ──
        $verificationBase = $applySubtypes($applyAtsStatus($applyFeatured($applyCategory($applyVisibility($applyStatus(clone $query))))));
        $verificationCounts = [
            'unverified' => (clone $verificationBase)->whereNull('verification_status')->count(),
            'verified' => (clone $verificationBase)->where('verification_status', 'verified')->count(),
            'fully_verified' => (clone $verificationBase)->where('verification_status', 'fully_verified')->count(),
            'flagged' => (clone $verificationBase)->where('verification_status', 'flagged')->count(),
            'pending_review' => (clone $verificationBase)->where('verification_status', 'pending_review')->count(),
        ];

        // ── Apply ALL filters for pagination ─────────────────────────────────
        $query = $applyStatus($query);
        $query = $applyVisibility($query);
        $query = $applyCategory($query);
        $query = $applyFeatured($query);
        $query = $applyAtsStatus($query);
        $query = $applySubtypes($query);
        $query = $applyVerification($query);

        // Price range + minimum beds/baths (used by the chat "Share a listing"
        // picker's More-filters). Applied to the paginated result set only.
        $priceMin = $request->input('price_min');
        $priceMax = $request->input('price_max');
        $minBeds = $request->input('beds');
        $minBaths = $request->input('baths');
        if (is_numeric($priceMin)) {
            $query->where('listings.price', '>=', (float) $priceMin);
        }
        if (is_numeric($priceMax)) {
            $query->where('listings.price', '<=', (float) $priceMax);
        }
        if (is_numeric($minBeds) && (int) $minBeds > 0) {
            $query->whereHas('property.propertyAttribute', fn ($q) => $q->where('bedroom_count', '>=', (int) $minBeds));
        }
        if (is_numeric($minBaths) && (int) $minBaths > 0) {
            $query->whereHas('property.propertyAttribute', fn ($q) => $q->where('bathroom_count', '>=', (int) $minBaths));
        }

        $perPage = min((int) $request->input('per_page', 12), 500);

        // Removed view sorts by audited_at newest→oldest. Otherwise the user
        // picks the sort (default "inquiries" surfaces what needs attention
        // first: most inquiries → most views → newest created).
        // (inquiries_count is provided by the withCount below.)
        $sort = (string) $request->query('sort', 'inquiries');
        if ($removed) {
            $query->orderByDesc('listings.audited_at');
        } elseif ($sort === 'views') {
            $query
                ->orderByDesc('listings.clicks')
                ->orderByDesc('listings.created_at');
        } elseif ($sort === 'created') {
            $query->orderByDesc('listings.created_at');
        } else {
            $query
                ->orderByDesc('inquiries_count')
                ->orderByDesc('listings.clicks')
                ->orderByDesc('listings.created_at');
        }

        $listings = $query
            ->with([
                'property' => function ($q) use ($trashed) {
                    if ($trashed) {
                        $q->withTrashed();
                    }
                    $q->with([
                        'propertyAttribute' => function ($qa) use ($trashed) {
                            if ($trashed) {
                                $qa->withTrashed();
                            }
                            $qa->with('subtype');
                        },
                    ]);
                },
                'category',
                'agent' => function ($q) {
                    $q->withCount('listings');
                },
            ])
            ->withCount(['inquiryChats as inquiries_count' => function ($q) use ($user) {
                // Match the Inbox: don't count inquiries this viewer has
                // archived / trashed / purged (per-participant state on
                // conversation_users — see ChatController@index).
                $q->whereDoesntHave('activeConversation.users', function ($u) use ($user) {
                    $u->where('users.id', $user->id)
                        ->where(function ($w) {
                            $w->whereNotNull('conversation_users.archived_at')
                                ->orWhereNotNull('conversation_users.removed_at')
                                ->orWhereNotNull('conversation_users.purged_at');
                        });
                });
            }])
            ->paginate($perPage);

        return (new ListingResourceCollection($listings))->additional([
            'counts' => [
                'status' => $statusCounts,
                'visibility' => $visibilityCounts,
                'ats' => $atsCounts,
                'verification' => $verificationCounts,
            ],
        ]);
    }

    public function allListings(Request $request)
    {
        $user = $request->user();

        [$isAdmin, $ledAgentIds] = $this->resolveListingScope($request);
        [$query, $trashed, $propHas] = $this->scopedListingQuery($request, $isAdmin, $ledAgentIds);
        $removed = filter_var($request->input('removed'), FILTER_VALIDATE_BOOLEAN);
        // Map view drives the synced card list off the SAME endpoint, but its
        // stat cards come from a separate request — so the ~15 count clones +
        // SUM(clicks) below are pure waste on every map pan. skip_counts=1 lets
        // the map skip them and only run the paginated card query.
        $skipCounts = filter_var($request->input('skip_counts'), FILTER_VALIDATE_BOOLEAN);

        // Single source of every filter (also used by mapMarkers). The counts
        // below compose subsets of these closures; the paginated result applies
        // all of them.
        $c = $this->listingFilterClosures($request, $propHas);
        $applyStatus = $c['status'];
        $applyVisibility = $c['visibility'];
        $applyCategory = $c['category'];
        $applyFeatured = $c['featured'];
        $applySubtypes = $c['subtypes'];
        $applyVerification = $c['verification'];
        $applyAtsStatus = $c['ats'];

        $statusCounts = $visibilityCounts = $categoryCounts = $atsCounts = $verificationCounts = [];
        $totalViews = 0;

        if (! $skipCounts) {
            // ── Status counts: respect visibility + category filters, NOT status ──
            $statusBase = $applySubtypes($applyFeatured($applyCategory($applyVisibility(clone $query))));
            $statusCounts = [
                'active' => $propHas(clone $statusBase, fn ($q) => $q->where('status', 'active'))->count(),
                'rented' => $propHas(clone $statusBase, fn ($q) => $q->where('status', 'rented'))->count(),
                'sold' => $propHas(clone $statusBase, fn ($q) => $q->where('status', 'sold'))->count(),
                'leased' => $propHas(clone $statusBase, fn ($q) => $q->where('status', 'leased'))->count(),
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
            $atsBase = $applySubtypes($applyFeatured($applyCategory($applyVisibility($applyStatus(clone $query)))));
            $atsCounts = [
                'pending' => $propHas(clone $atsBase, fn ($q) => $q->where('ats_status', 'pending'))->count(),
                'approved' => $propHas(clone $atsBase, fn ($q) => $q->where('ats_status', 'approve'))->count(),
                'expired' => $propHas(clone $atsBase, fn ($q) => $q->where('ats_status', 'expired'))->count(),
                'rejected' => $propHas(clone $atsBase, fn ($q) => $q->where('ats_status', 'rejected'))->count(),
            ];

            // ── Verification counts (independent of verification_status filter) ──
            $verificationBase = $applySubtypes($applyFeatured($applyCategory($applyVisibility($applyStatus(clone $query)))));
            $verificationCounts = [
                'unverified' => (clone $verificationBase)->whereNull('verification_status')->count(),
                'verified' => (clone $verificationBase)->where('verification_status', 'verified')->count(),
                'fully_verified' => (clone $verificationBase)->where('verification_status', 'fully_verified')->count(),
                'flagged' => (clone $verificationBase)->where('verification_status', 'flagged')->count(),
                'pending_review' => (clone $verificationBase)->where('verification_status', 'pending_review')->count(),
            ];
        }

        // ── Apply ALL filters for pagination ─────────────────────────────────
        $query = $applyStatus($query);
        $query = $applyVisibility($query);
        $query = $applyCategory($query);
        $query = $applyFeatured($query);
        $query = $applySubtypes($query);
        $query = $applyVerification($query);
        $query = $c['date']($query);
        $query = $applyAtsStatus($query);
        // Price / beds / baths / area / furnishings (no-op when absent). Not
        // added to the stat-card count bases above — like date/geo, the counts
        // stay independent of these filters; only the list + map narrow.
        $query = $c['price']($query);
        $query = $c['beds']($query);
        $query = $c['baths']($query);
        $query = $c['area']($query);
        $query = $c['furnishings']($query);
        // Map view: filter to the current viewport bounds or a drawn polygon
        // boundary (no-op when those params are absent — list view unchanged).
        $this->applyGeoFilters($query, $request, $trashed);

        if (! $skipCounts) {
            $totalViews = (int) (clone $query)->sum('clicks');
        }

        // Removed view sorts by audited_at newest→oldest. Otherwise the user
        // picks the sort; default "inquiries" matches My Listings (most
        // inquiries → most views → newest created). inquiries_count comes from
        // the withCount below.
        $sort = (string) $request->query('sort', 'inquiries');
        if ($removed) {
            $query->orderByDesc('listings.audited_at');
        } elseif ($sort === 'views') {
            $query
                ->orderByDesc('listings.clicks')
                ->orderByDesc('listings.created_at');
        } elseif ($sort === 'created') {
            $query->orderByDesc('listings.created_at');
        } elseif ($sort === 'price_asc') {
            $query->orderBy('listings.price', 'asc')->orderByDesc('listings.created_at');
        } elseif ($sort === 'price_desc') {
            $query->orderByDesc('listings.price')->orderByDesc('listings.created_at');
        } else {
            $query
                ->orderByDesc('inquiries_count')
                ->orderByDesc('listings.clicks')
                ->orderByDesc('listings.created_at');
        }

        $listings = $query
            ->with([
                'property' => function ($q) use ($trashed) {
                    if ($trashed) {
                        $q->withTrashed();
                    }
                    $q->with([
                        'propertyAttribute' => function ($qa) use ($trashed) {
                            if ($trashed) {
                                $qa->withTrashed();
                            }
                            $qa->with('subtype');
                        },
                    ]);
                },
                'category',
                'agent' => function ($q) {
                    $q->withCount('listings');
                },
            ])
            ->withCount(['inquiryChats as inquiries_count' => function ($q) use ($user) {
                // Match the Inbox: exclude inquiries this viewer archived /
                // trashed / purged (per-participant conversation_users state).
                $q->whereDoesntHave('activeConversation.users', function ($u) use ($user) {
                    $u->where('users.id', $user->id)
                        ->where(function ($w) {
                            $w->whereNotNull('conversation_users.archived_at')
                                ->orWhereNotNull('conversation_users.removed_at')
                                ->orWhereNotNull('conversation_users.purged_at');
                        });
                });
            }])
            ->paginate($request->query('per_page', 12));

        return (new ListingResourceCollection($listings))->additional([
            'counts' => $skipCounts ? null : [
                'status' => $statusCounts,
                'visibility' => $visibilityCounts,
                'ats' => $atsCounts,
                'views' => $totalViews,
                'verification' => $verificationCounts,
            ],
        ]);
    }

    /**
     * Lightweight listing markers for the admin map (Zillow-style). Reuses the
     * exact same scope + filters + viewport/polygon as allListings, requires
     * geo coordinates, caps the result (~500), and returns minimal DTOs plus a
     * total + capped flag for the "showing X of Y — zoom in" hint.
     */
    public function mapMarkers(Request $request)
    {
        [$isAdmin, $ledAgentIds] = $this->resolveListingScope($request);
        [$query, $trashed, $propHas] = $this->scopedListingQuery($request, $isAdmin, $ledAgentIds);

        $c = $this->listingFilterClosures($request, $propHas);
        foreach (['status', 'visibility', 'category', 'featured', 'subtypes', 'verification', 'ats', 'date', 'price', 'beds', 'baths', 'area', 'furnishings'] as $k) {
            $query = $c[$k]($query);
        }
        $this->applyGeoFilters($query, $request, $trashed);

        // Markers always require usable coordinates.
        $query->whereHas('property', function ($sub) use ($trashed) {
            if ($trashed) {
                $sub->withTrashed();
            }
            $sub->whereNotNull('properties.geo_coordinates')
                ->where('properties.geo_coordinates', '!=', '');
        });

        $CAP = 500;
        $total = (clone $query)->count();

        $rows = $query
            ->with([
                'property' => function ($q) use ($trashed) {
                    if ($trashed) {
                        $q->withTrashed();
                    }
                    $q->with(['propertyAttribute' => function ($qa) use ($trashed) {
                        if ($trashed) {
                            $qa->withTrashed();
                        }
                    }]);
                },
                'category',
            ])
            // Cap ordering: featured first, then newest — keeps the most relevant
            // 500 pins when a wide viewport exceeds the cap.
            ->orderByDesc('listings.is_featured')
            ->orderByDesc('listings.created_at')
            ->limit($CAP)
            ->get();

        $cutoff = now()->subDays(14);
        $markers = $rows->map(function ($l) use ($cutoff) {
            $geo = $l->property?->geo_coordinates;
            $lat = is_array($geo) ? ($geo['lat'] ?? null) : null;
            $lng = is_array($geo) ? ($geo['lng'] ?? null) : null;
            if (! is_numeric($lat) || ! is_numeric($lng)) {
                return null;
            }
            $attr = $l->property?->propertyAttribute;

            // featured_photo may be a JSON string, an array of urls, or an array
            // of {url|path} objects — normalise to the first usable url.
            $fp = $l->featured_photo;
            if (is_string($fp)) {
                $fp = json_decode($fp, true);
            }
            $thumbnail = null;
            if (is_array($fp) && ! empty($fp)) {
                $first = $fp[0] ?? null;
                $thumbnail = is_array($first) ? ($first['url'] ?? $first['path'] ?? null) : (is_string($first) ? $first : null);
            }

            return [
                'id' => $l->id,
                'slug' => $l->slug,
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'price' => $l->price !== null ? (float) $l->price : null,
                'thumbnail' => $thumbnail,
                'category' => $l->category->name ?? null,
                'status' => $l->property?->status,
                'beds' => $attr?->bedroom_count,
                'baths' => $attr?->bathroom_count,
                'floor_area' => $attr?->floor_area,
                'is_new' => $l->created_at?->gte($cutoff) ?? false,
            ];
        })->filter()->values();

        return response()->json([
            'data' => $markers,
            'total' => $total,
            'capped' => $total > $CAP,
        ]);
    }

    /**
     * Resolve who can see what: admin sees all; a team leader sees only their
     * team's listings; anyone else is rejected. Returns [bool $isAdmin, int[] $ledAgentIds].
     */
    private function resolveListingScope(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->role->name === 'admin';
        $ledAgentIds = [];
        if (! $isAdmin) {
            $ledAgentIds = app(TeamLeadershipService::class)->getLedAgentIds($user->id);
            if (empty($ledAgentIds)) {
                abort(403);
            }
        }

        return [$isAdmin, $ledAgentIds];
    }

    /**
     * Base scoped query shared by allListings + mapMarkers: agent scope, the
     * optional single-agent filter, trashed/removed handling, search, and the
     * $propHas helper (opts trashed property rows back in). Returns
     * [Builder $query, bool $trashed, callable $propHas].
     */
    private function scopedListingQuery(Request $request, bool $isAdmin, array $ledAgentIds): array
    {
        $query = Listing::query();
        if (! $isAdmin) {
            $query->whereIn('agent_id', $ledAgentIds);
        }

        // Optional single-agent filter (Agents directory "View Listings").
        if ($request->filled('agent_id')) {
            $aid = (int) $request->input('agent_id');
            if (! $isAdmin && ! in_array($aid, $ledAgentIds, true)) {
                abort(403);
            }
            $query->where('agent_id', $aid);
        }

        // Soft-deleted / removed views (see allListings doc for the distinction).
        $removed = filter_var($request->input('removed'), FILTER_VALIDATE_BOOLEAN);
        $trashed = filter_var($request->input('trashed'), FILTER_VALIDATE_BOOLEAN) || $removed;
        if ($trashed) {
            $query->onlyTrashed();
        }
        if ($removed) {
            $query->whereNotNull('listings.photos_migration_note');
        } elseif ($trashed) {
            $query->whereNull('listings.photos_migration_note');
        }

        $propHas = function ($q, callable $cb) use ($trashed) {
            return $q->whereHas('property', function ($sub) use ($cb, $trashed) {
                if ($trashed) {
                    $sub->withTrashed();
                }
                $cb($sub);
            });
        };

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search, $trashed) {
                $q->where('listings.name', 'like', "%{$search}%")
                    ->orWhere('listings.code', 'like', "%{$search}%")
                    ->orWhereHas('property', function ($sub) use ($search, $trashed) {
                        if ($trashed) {
                            $sub->withTrashed();
                        }
                        $sub->where('address', 'like', "%{$search}%");
                    });
            });
        }

        return [$query, $trashed, $propHas];
    }

    /**
     * The single source of every status/visibility/category/featured/subtype/
     * verification/ats/date filter, as composable closures. allListings composes
     * subsets for its counts and the full set for the paginated result;
     * mapMarkers applies the full set. (date is NOT applied to the count clones,
     * matching the original behavior.)
     */
    private function listingFilterClosures(Request $request, callable $propHas): array
    {
        $status = $request->input('status');
        $visibility = $request->input('visibility');
        $category = $request->input('category');
        $featured = $request->input('featured');
        $subtypes = $request->input('subtypes');
        $verificationStatus = $request->input('verification_status');
        $atsStatus = $request->input('ats_status');

        return [
            'status' => function ($q) use ($status, $propHas) {
                if (! $status) {
                    return $q;
                }

                return $propHas($q, fn ($sub) => $sub->where('status', $status));
            },
            'visibility' => function ($q) use ($visibility) {
                if (! $visibility) {
                    return $q;
                }

                return $q->where('visibility', $visibility);
            },
            'category' => function ($q) use ($category) {
                if (! $category) {
                    return $q;
                }

                return $q->whereHas('category', fn ($sub) => $sub->where('name', $category));
            },
            'featured' => function ($q) use ($featured) {
                if ($featured === null || $featured === '' || strtolower((string) $featured) === 'all') {
                    return $q;
                }
                $normalized = strtolower((string) $featured);
                $bool = null;
                if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                    $bool = true;
                } elseif (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                    $bool = false;
                } else {
                    $parsed = filter_var($featured, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($parsed !== null) {
                        $bool = $parsed;
                    }
                }
                if ($bool === true) {
                    return $q->where('listings.is_featured', 1);
                }
                if ($bool === false) {
                    return $q->where(function ($qq) {
                        $qq->where('listings.is_featured', 0)->orWhereNull('listings.is_featured');
                    });
                }

                return $q;
            },
            'subtypes' => function ($q) use ($subtypes) {
                if (! $subtypes) {
                    return $q;
                }
                $ids = is_array($subtypes) ? $subtypes : explode(',', (string) $subtypes);
                $ids = array_filter(array_map('intval', $ids));
                if (empty($ids)) {
                    return $q;
                }

                return $q->whereHas('property.propertyAttribute.subtype', fn ($sub) => $sub->whereIn('id', $ids));
            },
            'verification' => function ($q) use ($verificationStatus) {
                if (! $verificationStatus) {
                    return $q;
                }
                if ($verificationStatus === 'unverified') {
                    return $q->whereNull('verification_status');
                }

                return $q->where('verification_status', $verificationStatus);
            },
            'ats' => function ($q) use ($atsStatus, $propHas) {
                if (! $atsStatus) {
                    return $q;
                }

                return $propHas($q, fn ($sub) => $sub->where('ats_status', $atsStatus === 'approved' ? 'approve' : $atsStatus));
            },
            'date' => function ($q) use ($request) {
                if ($dateTo = $request->input('date_to')) {
                    $q->where('listings.created_at', '<=', $dateTo.' 23:59:59');
                }
                if ($dateFrom = $request->input('date_from')) {
                    $q->where('listings.created_at', '>=', $dateFrom.' 00:00:00');
                }

                return $q;
            },
            // Price range — listings.price is on the listings table (no join).
            // Mirrors the public Listing::filter() price branch.
            'price' => function ($q) use ($request) {
                $min = $request->input('price_min');
                $max = $request->input('price_max');
                if (is_numeric($min)) {
                    $q->where('listings.price', '>=', $min);
                }
                if (is_numeric($max)) {
                    $q->where('listings.price', '<=', $max);
                }

                return $q;
            },
            // Beds / Baths — property_attributes columns. Plain whereHas (same
            // pattern as the subtypes closure). condition: plus → >=, minus → <=.
            'beds' => function ($q) use ($request) {
                if (! $request->filled('beds')) {
                    return $q;
                }
                $op = match ($request->input('beds_condition', 'equal')) {
                    'plus' => '>=',
                    'minus' => '<=',
                    default => '=',
                };

                return $q->whereHas('property.propertyAttribute', fn ($sub) => $sub->where('bedroom_count', $op, (int) $request->input('beds')));
            },
            'baths' => function ($q) use ($request) {
                if (! $request->filled('baths')) {
                    return $q;
                }
                $op = match ($request->input('baths_condition', 'equal')) {
                    'plus' => '>=',
                    'minus' => '<=',
                    default => '=',
                };

                return $q->whereHas('property.propertyAttribute', fn ($sub) => $sub->where('bathroom_count', $op, (int) $request->input('baths')));
            },
            // Area (sqm) — larger of lot/floor area, matching the public scope.
            'area' => function ($q) use ($request) {
                $hasMin = $request->filled('sqm_min');
                $hasMax = $request->filled('sqm_max');
                if (! $hasMin && ! $hasMax) {
                    return $q;
                }

                return $q->whereHas('property.propertyAttribute', function ($sub) use ($request, $hasMin, $hasMax) {
                    if ($hasMin) {
                        $sub->whereRaw('GREATEST(COALESCE(lot_area, 0), COALESCE(floor_area, 0)) >= ?', [$request->input('sqm_min')]);
                    }
                    if ($hasMax) {
                        $sub->whereRaw('GREATEST(COALESCE(lot_area, 0), COALESCE(floor_area, 0)) <= ?', [$request->input('sqm_max')]);
                    }
                });
            },
            // Furnishings — properties.furnishing_id (CSV or array of ids).
            'furnishings' => function ($q) use ($request) {
                $furnishings = $request->input('furnishings');
                if (! $furnishings) {
                    return $q;
                }
                $ids = is_array($furnishings) ? $furnishings : explode(',', (string) $furnishings);
                $ids = array_filter(array_map('intval', $ids));
                if (empty($ids)) {
                    return $q;
                }

                return $q->whereHas('property', fn ($sub) => $sub->whereIn('furnishing_id', $ids));
            },
        ];
    }

    /**
     * Map-view geography: filter to a drawn polygon boundary (takes precedence)
     * or the current viewport bounding box. No-op when neither is present, so
     * the normal list view is unaffected. geo_coordinates is JSON {lat,lng}; the
     * lat/lng extraction mirrors InquiryInsightsService.
     *
     * PERF: JSON_EXTRACT bounds is a full scan of ~11k properties (fine for now).
     * Later: add generated stored geo_lat/geo_lng DECIMAL columns + a composite
     * index and swap the whereRaw expressions for indexed column comparisons.
     */
    private function applyGeoFilters($query, Request $request, bool $trashed): void
    {
        $latExpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(properties.geo_coordinates, '$.lat')) AS DECIMAL(12,8))";
        $lngExpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(properties.geo_coordinates, '$.lng')) AS DECIMAL(12,8))";

        // Clicked administrative boundary (city/municipality or barangay) — filter
        // by the boundary's STORED geometry (SRID 0). Highest precedence. An
        // MBRContains bbox prefilter (cheap, on the boundary's MBR) narrows rows
        // before the exact ST_Contains. The boundary subquery is constant (PK
        // lookup), so MySQL evaluates it once.
        $boundaryId = $request->input('boundary_id');
        if (is_numeric($boundaryId)) {
            $pt = "ST_GeomFromText(CONCAT('POINT(', ({$lngExpr}), ' ', ({$latExpr}), ')'), 0)";
            $query->whereHas('property', function ($sub) use ($trashed, $pt, $boundaryId) {
                if ($trashed) {
                    $sub->withTrashed();
                }
                $sub->whereNotNull('properties.geo_coordinates')
                    ->whereRaw("MBRContains((SELECT geom FROM boundaries WHERE id = ?), {$pt})", [(int) $boundaryId])
                    ->whereRaw("ST_Contains((SELECT geom FROM boundaries WHERE id = ?), {$pt})", [(int) $boundaryId]);
            });

            return;
        }

        // Drawn polygon boundary (Zillow "Draw"): bbox prefilter + ST_Contains.
        $polygonRaw = $request->input('polygon');
        if ($polygonRaw) {
            $vertices = is_array($polygonRaw) ? $polygonRaw : json_decode((string) $polygonRaw, true);
            $ring = []; // [lng, lat] pairs (WKT order is "lng lat")
            if (is_array($vertices)) {
                foreach ($vertices as $v) {
                    $lat = $v['lat'] ?? null;
                    $lng = $v['lng'] ?? null;
                    if (is_numeric($lat) && is_numeric($lng)) {
                        $ring[] = [(float) $lng, (float) $lat];
                    }
                }
            }
            if (count($ring) >= 3) {
                $lngs = array_column($ring, 0);
                $lats = array_column($ring, 1);
                $minLng = min($lngs);
                $maxLng = max($lngs);
                $minLat = min($lats);
                $maxLat = max($lats);
                if ($ring[0] !== end($ring)) {
                    $ring[] = $ring[0]; // close the ring for valid WKT
                }
                $wkt = 'POLYGON(('.implode(',', array_map(fn ($p) => "{$p[0]} {$p[1]}", $ring)).'))';

                $query->whereHas('property', function ($sub) use ($trashed, $latExpr, $lngExpr, $minLat, $maxLat, $minLng, $maxLng, $wkt) {
                    if ($trashed) {
                        $sub->withTrashed();
                    }
                    $sub->whereNotNull('properties.geo_coordinates')
                        ->whereRaw("({$latExpr}) BETWEEN ? AND ?", [$minLat, $maxLat])
                        ->whereRaw("({$lngExpr}) BETWEEN ? AND ?", [$minLng, $maxLng])
                        ->whereRaw(
                            "ST_Contains(ST_GeomFromText(?), ST_GeomFromText(CONCAT('POINT(', ({$lngExpr}), ' ', ({$latExpr}), ')')))",
                            [$wkt]
                        );
                });

                return;
            }
        }

        // Viewport bounding box.
        $minLat = $request->input('min_lat');
        $maxLat = $request->input('max_lat');
        $minLng = $request->input('min_lng');
        $maxLng = $request->input('max_lng');
        if (is_numeric($minLat) && is_numeric($maxLat) && is_numeric($minLng) && is_numeric($maxLng)) {
            $query->whereHas('property', function ($sub) use ($trashed, $latExpr, $lngExpr, $minLat, $maxLat, $minLng, $maxLng) {
                if ($trashed) {
                    $sub->withTrashed();
                }
                $sub->whereNotNull('properties.geo_coordinates')
                    ->whereRaw("({$latExpr}) BETWEEN ? AND ?", [(float) $minLat, (float) $maxLat])
                    ->whereRaw("({$lngExpr}) BETWEEN ? AND ?", [(float) $minLng, (float) $maxLng]);
            });
        }
    }

    public function createPropertyStatistics($start, $end, $typeId)
    {
        $baseQuery = Listing::whereHas('property.propertyAttribute', function ($q) use ($typeId) {
            $q->where('property_subtype_id', $typeId);
        })->whereBetween('created_at', [$start, $end]);

        $statistics['active'] = (clone $baseQuery)->active()->count();
        $statistics['total'] = (clone $baseQuery)->count();
        $statistics['views'] = (int) (clone $baseQuery)->sum('clicks');

        $statistics['inquiries'] = ListingInquiry::whereIn(
            'listing_id',
            (clone $baseQuery)->select('id')
        )->count();

        $statistics['rented'] = (clone $baseQuery)->rented()->count();
        $statistics['sold'] = (clone $baseQuery)->sold()->count();
        $statistics['leased'] = (clone $baseQuery)->leased()->count();

        return $statistics;
    }

    public function propertyStatistics($start, $end)
    {
        $subTypes = PropertySubtype::get();
        $statistics = [];
        $totals = [
            'active' => 0,
            'total' => 0,
            'rented' => 0,
            'sold' => 0,
            'leased' => 0,
            'inquiries' => 0,
            'views' => 0,
        ];

        foreach ($subTypes as $st) {
            $stat = $this->createPropertyStatistics($start, $end, $st->id);
            $statistics[] = [
                'type' => $st->type->name,
                'subType' => $st->name,
                'statistics' => $stat,
            ];

            foreach ($totals as $key => $value) {
                $totals[$key] += $stat[$key] ?? 0;
            }
        }

        return [
            'data' => $statistics,
            'totals' => $totals,
        ];
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->role->name === 'admin';
        $start = date('2020-01-01');
        $end = date('Y-m-d');
        $statistics = [
            'active' => 0,
            'total' => 0,
            'rented' => 0,
            'sold' => 0,
            'inquiries' => 0,
            'views' => 0,
            'agents' => 0,
        ];

        if (isset($request->date_start) && isset($request->date_start)) {
            $start = $request->date_start;
            $end = $request->date_end;
        }

        if ($isAdmin) {
            $agentCount = Agent::whereBetween('member_since', [$start, $end])->count();
            $propertyStatistics = $this->propertyStatistics($start, $end);

            return [
                'agents' => $agentCount,
                'properties' => $propertyStatistics,
                'private_listings' => Listing::where('visibility', 'private')->count(),
            ];
        }

        $baseQuery = Listing::withCount('inQuiries')->where('agent_id', $user->agent->id);
        $statistics['active'] = (clone $baseQuery)->active()->count();
        $statistics['total'] = (clone $baseQuery)->count();
        $statistics['views'] = (int) (clone $baseQuery)->sum('clicks');

        $statistics['inquiries'] = ListingInquiry::whereIn(
            'listing_id',
            (clone $baseQuery)->select('id')
        )->count();

        $statistics['rented'] = (clone $baseQuery)->rented()->count();
        $statistics['sold'] = (clone $baseQuery)->sold()->count();
        $statistics['leased'] = (clone $baseQuery)->leased()->count();
        $statistics['private_listings'] = (clone $baseQuery)->where('visibility', 'private')->count();
        $statistics['agent'] = 1;

        // Category breakdown (For Sale / For Rent / Foreclosure) for the
        // dashboard Category card — scoped to this agent's own listings.
        $categoryCountsRaw = Listing::where('agent_id', $user->agent->id)
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->selectRaw('categories.name as name, COUNT(*) as count')
            ->groupBy('categories.name')
            ->pluck('count', 'name');
        $statistics['category'] = [
            'For Sale' => (int) ($categoryCountsRaw['For Sale'] ?? 0),
            'For Rent' => (int) ($categoryCountsRaw['For Rent'] ?? 0),
            'Foreclosure' => (int) ($categoryCountsRaw['Foreclosure'] ?? 0),
        ];

        return response()->json($statistics);
    }

    /**
     * Demographics (gender + age brackets) of agents who closed at least one
     * transaction (property sold/rented/leased) within the selected period —
     * keyed off properties.status_change_date. Admin sees all agents; an agent
     * sees only themselves. Each agent is counted once (by their own gender /
     * age), not per transaction.
     */
    public function dashboardAgentDemographics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_start' => 'nullable|date',
            'date_end' => 'nullable|date|after_or_equal:date_start',
            'granularity' => 'nullable|in:day,month,year', // accepted for parity with other charts
        ]);

        $user = $request->user();
        $isAdmin = $user->role->name === 'admin';
        $start = $validated['date_start'] ?? now()->startOfYear()->toDateString();
        $end = $validated['date_end'] ?? now()->toDateString();

        // Distinct agents with ≥1 transaction in the period.
        $agentIds = Listing::query()
            ->whereHas('property', function ($q) use ($start, $end) {
                $q->whereIn('status', ['sold', 'rented', 'leased'])
                    ->whereNotNull('status_change_date')
                    ->whereBetween('status_change_date', [$start.' 00:00:00', $end.' 23:59:59']);
            })
            ->when(! $isAdmin, fn ($q) => $q->where('agent_id', optional($user->agent)->id))
            ->distinct()
            ->pluck('agent_id')
            ->filter()
            ->all();

        $agents = Agent::whereIn('id', $agentIds)
            ->get(['id', 'gender', 'birthdate', 'first_name', 'last_name', 'avatar']);

        // Per-agent transaction tally (sold/rented/leased) within the period —
        // shown in the per-age drill-down. Single grouped aggregate (no row
        // hydration); same filter as $agentIds above.
        $txByAgent = []; // agentId => ['sold' => n, 'rented' => n, 'leased' => n]
        $txRows = Listing::query()
            ->join('properties', 'listings.property_id', '=', 'properties.id')
            ->whereIn('properties.status', ['sold', 'rented', 'leased'])
            ->whereNotNull('properties.status_change_date')
            ->whereBetween('properties.status_change_date', [$start.' 00:00:00', $end.' 23:59:59'])
            ->when(! $isAdmin, fn ($q) => $q->where('listings.agent_id', optional($user->agent)->id))
            ->whereNotNull('listings.agent_id')
            ->groupBy('listings.agent_id', 'properties.status')
            ->get([
                'listings.agent_id as agent_id',
                'properties.status as status',
                DB::raw('count(*) as c'),
            ]);
        foreach ($txRows as $row) {
            $aid = $row->agent_id;
            if (! isset($txByAgent[$aid])) {
                $txByAgent[$aid] = ['sold' => 0, 'rented' => 0, 'leased' => 0];
            }
            $txByAgent[$aid][$row->status] = (int) $row->c;
        }

        $gender = ['male' => 0, 'female' => 0, 'unknown' => 0];
        $age = ['18-24' => 0, '25-34' => 0, '35-44' => 0, '45-54' => 0, '55+' => 0, 'unknown' => 0];
        $withGender = 0;
        $withAge = 0;
        $ageSum = 0;
        $ageByGender = []; // exactAge => ['male' => n, 'female' => n]
        $ageAgents = []; // exactAge => [ ['id','name','avatar','gender'], ... ]

        foreach ($agents as $a) {
            $g = in_array($a->gender, ['male', 'female'], true) ? $a->gender : 'unknown';
            $gender[$g]++;
            if ($g !== 'unknown') {
                $withGender++;
            }

            // Identity + transactions for the per-age drill-down. Avatar is
            // cast to array; take the first URL.
            $avatar = is_array($a->avatar) ? ($a->avatar[0] ?? null) : $a->avatar;
            $tx = $txByAgent[$a->id] ?? ['sold' => 0, 'rented' => 0, 'leased' => 0];
            $entry = [
                'id' => $a->id,
                'name' => trim(($a->first_name ?? '').' '.($a->last_name ?? '')),
                'avatar' => $avatar,
                'gender' => $g,
                'sold' => $tx['sold'],
                'rented' => $tx['rented'],
                'leased' => $tx['leased'],
            ];

            if ($a->birthdate) {
                $yrs = $a->birthdate->age;
                $bracket = $yrs < 25 ? '18-24'
                    : ($yrs < 35 ? '25-34'
                    : ($yrs < 45 ? '35-44'
                    : ($yrs < 55 ? '45-54' : '55+')));
                $age[$bracket]++;
                $withAge++;
                $ageSum += $yrs;

                $ageAgents[$yrs][] = $entry;

                // Per-exact-age tally by gender. An agent with a birthdate
                // always has a gender too (both fill together on login), so
                // only male/female ever occur here.
                if ($g !== 'unknown') {
                    if (! isset($ageByGender[$yrs])) {
                        $ageByGender[$yrs] = ['male' => 0, 'female' => 0];
                    }
                    $ageByGender[$yrs][$g]++;
                }
            } else {
                $age['unknown']++;
                // Agents without a birthdate — listed under the "Unknown" row.
                $ageAgents['unknown'][] = $entry;
            }
        }

        $avgAge = $withAge > 0 ? (int) round($ageSum / $withAge) : null;

        ksort($ageByGender);
        ksort($ageAgents);
        $ageRows = [];
        foreach ($ageByGender as $yrs => $c) {
            $ageRows[] = ['age' => $yrs, 'male' => $c['male'], 'female' => $c['female']];
        }

        $toSeries = fn (array $map) => collect($map)
            ->map(fn ($v, $k) => ['label' => $k, 'value' => $v])
            ->values()
            ->all();

        return response()->json([
            'gender' => $toSeries($gender),
            'age' => $toSeries($age),
            'age_by_gender' => $ageRows,
            'age_agents' => (object) $ageAgents,
            'totals' => [
                'agents' => count($agents),
                'with_gender' => $withGender,
                'with_age' => $withAge,
                'avg_age' => $avgAge,
            ],
            'meta' => ['from' => $start, 'to' => $end],
        ]);
    }

    /**
     * Demographics (gender + age brackets) of CLIENT users (role 'client').
     * Admin-only. Mirrors dashboardAgentDemographics but over `users`
     * (clients have no `agents` row) and without the per-agent transaction
     * drill-down — clients vastly outnumber agents, so we keep the payload
     * to aggregate buckets only. Missing data lands in a "not_provided"
     * bucket (surfaced as "Not provided" in the UI). Optional date window
     * filters by users.created_at (registration cohort).
     */
    public function dashboardClientDemographics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_start' => 'nullable|date',
            'date_end' => 'nullable|date|after_or_equal:date_start',
            'granularity' => 'nullable|in:day,month,year', // parity with other charts
        ]);

        // Default to all-time so the stat isn't empty before any cohort
        // filtering; the frontend can narrow via date_start/date_end.
        $start = $validated['date_start'] ?? '2000-01-01';
        $end = $validated['date_end'] ?? now()->toDateString();

        $clients = User::query()
            ->client()
            ->whereBetween('created_at', [$start.' 00:00:00', $end.' 23:59:59'])
            ->get(['id', 'gender', 'birthdate']);

        $gender = ['male' => 0, 'female' => 0, 'not_provided' => 0];
        $age = ['18-24' => 0, '25-34' => 0, '35-44' => 0, '45-54' => 0, '55+' => 0, 'not_provided' => 0];
        $withGender = 0;
        $withAge = 0;
        $ageSum = 0;
        $ageByGender = []; // exactAge => ['male' => n, 'female' => n]

        foreach ($clients as $c) {
            $g = in_array($c->gender, ['male', 'female'], true) ? $c->gender : 'not_provided';
            $gender[$g]++;
            if ($g !== 'not_provided') {
                $withGender++;
            }

            if ($c->birthdate) {
                $yrs = $c->birthdate->age;
                $bracket = $yrs < 25 ? '18-24'
                    : ($yrs < 35 ? '25-34'
                    : ($yrs < 45 ? '35-44'
                    : ($yrs < 55 ? '45-54' : '55+')));
                $age[$bracket]++;
                $withAge++;
                $ageSum += $yrs;

                if ($g !== 'not_provided') {
                    if (! isset($ageByGender[$yrs])) {
                        $ageByGender[$yrs] = ['male' => 0, 'female' => 0];
                    }
                    $ageByGender[$yrs][$g]++;
                }
            } else {
                $age['not_provided']++;
            }
        }

        $avgAge = $withAge > 0 ? (int) round($ageSum / $withAge) : null;

        ksort($ageByGender);
        $ageRows = [];
        foreach ($ageByGender as $yrs => $c) {
            $ageRows[] = ['age' => $yrs, 'male' => $c['male'], 'female' => $c['female']];
        }

        $toSeries = fn (array $map) => collect($map)
            ->map(fn ($v, $k) => ['label' => $k, 'value' => $v])
            ->values()
            ->all();

        return response()->json([
            'gender' => $toSeries($gender),
            'age' => $toSeries($age),
            'age_by_gender' => $ageRows,
            'totals' => [
                'clients' => $clients->count(),
                'with_gender' => $withGender,
                'with_age' => $withAge,
                'avg_age' => $avgAge,
            ],
            'meta' => ['from' => $start, 'to' => $end],
        ]);
    }

    public function dashboardStatusByDate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_start' => 'nullable|date',
            'date_end' => 'nullable|date|after_or_equal:date_start',
            'granularity' => 'nullable|in:day,month,year',
        ]);

        $user = $request->user();
        $user->loadMissing('role', 'agent');
        $isAdmin = $user->role?->name === 'admin';
        $agentId = $user->agent?->id;
        $start = $validated['date_start'] ?? now()->startOfYear()->toDateString();
        $end = $validated['date_end'] ?? now()->toDateString();
        $gran = $validated['granularity'] ?? 'day';
        $statuses = ['rented', 'sold', 'leased'];

        $emptyResponse = fn () => response()->json([
            'data' => [],
            'totals' => array_merge(array_fill_keys($statuses, 0), ['total' => 0]),
            'meta' => ['granularity' => $gran, 'from' => $start, 'to' => $end],
        ]);

        if (! $isAdmin) {
            if (! $agentId) {
                return $emptyResponse();
            }
        }

        $dateExpr = DB::raw('properties.status_change_date as date');
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

            if (! $isAdmin) {
                $query->whereHas('listings', fn ($sub) => $sub->where('agent_id', $agentId));
            }

            return $query
                ->groupByRaw($groupByRaw)
                ->orderByRaw($groupByRaw)
                ->get();
        })();

        $byDate = [];
        $totals = array_fill_keys($statuses, 0);

        foreach ($rows as $row) {
            $bucketStart = match ($gran) {
                'year' => substr((string) $row->date, 0, 4).'-01-01',
                'month' => substr((string) $row->date, 0, 7).'-01',
                default => (string) $row->date,
            };
            $status = (string) $row->status;
            $count = (int) $row->count;

            $byDate[$bucketStart] ??= array_merge([
                'bucket_start' => $bucketStart,
                'bucket_label' => $this->dashboardBucketLabel($bucketStart, $gran),
            ], array_fill_keys($statuses, 0), ['total' => 0]);
            $byDate[$bucketStart][$status] += $count;
            $byDate[$bucketStart]['total'] += $count;
            $totals[$status] += $count;
        }

        return response()->json([
            'data' => array_values(array_map(function ($bucket) use ($statuses) {
                $counts = ['total' => $bucket['total']];
                foreach ($statuses as $status) {
                    $counts[$status] = $bucket[$status];
                }

                return [
                    'bucket_start' => $bucket['bucket_start'],
                    'bucket_label' => $bucket['bucket_label'],
                    'counts' => $counts,
                ];
            }, $byDate)),
            'totals' => array_merge($totals, ['total' => array_sum($totals)]),
            'meta' => ['granularity' => $gran, 'from' => $start, 'to' => $end],
        ]);
    }

    public function updateVisibility(Request $request, Listing $listing)
    {
        $this->authorize('update', $listing);

        $request->validate([
            'visibility' => 'required|in:public,private',
        ]);

        $actor = $request->user()?->name ?? 'Someone';
        $listing->auditSource = 'visibility_toggle';
        $listing->auditDescription = sprintf(
            '%s changed %s visibility to %s',
            $actor,
            $listing->name,
            $request->visibility,
        );
        $listing->update(['visibility' => $request->visibility]);

        return response()->json(['visibility' => $listing->visibility]);
    }

    public function updateStatus(Request $request, Listing $listing)
    {
        $this->authorize('update', $listing);

        $data = $request->validate([
            'status' => 'required|in:active,rented,sold,leased',
            'status_change_date' => 'required|date',
            'status_remark' => 'nullable|string',
        ]);

        $actor = $request->user()?->name ?? 'Someone';
        $listing->property->auditSource = 'status_change';
        $listing->property->auditDescription = sprintf(
            '%s changed %s status to %s',
            $actor,
            $listing->name,
            $data['status'],
        );
        $listing->property->update($data);

        // Push the owning agent when their listing is marked as a transaction
        // (sold / rented / leased). Skip 'active' (reactivation) and skip when
        // the agent made the change themselves — they already know. Non-fatal;
        // gated by the agent's "Status changes" toggle inside ExpoPushService.
        if (in_array($data['status'], ['sold', 'rented', 'leased'], true)) {
            try {
                $agentUser = optional($listing->agent)->user
                    ?? optional($listing->load('agent.user')->agent)->user;
                if ($agentUser && $agentUser->id !== $request->user()?->id) {
                    $verb = ['sold' => 'sold', 'rented' => 'rented', 'leased' => 'leased'][$data['status']];
                    app(ExpoPushService::class)->notify(
                        $agentUser,
                        'listing_status',
                        'Listing '.$verb.' 🎉',
                        "“{$listing->name}” was marked {$verb}. Congratulations on closing the deal!",
                        ['type' => 'listing_status', 'id' => $listing->id, 'status' => $data['status']],
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Push notify (listing status) failed', [
                    'listing_id' => $listing->id,
                    'status' => $data['status'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'status' => $listing->property->status,
            'status_change_date' => $listing->property->status_change_date,
            'status_remark' => $listing->property->status_remark,
        ]);
    }

    public function updateIsFeatured(Request $request, Listing $listing)
    {
        $this->authorize('update', $listing);

        $data = $request->validate([
            'is_featured' => 'required|boolean',
        ]);

        $actor = $request->user()?->name ?? 'Someone';
        $listing->auditSource = 'featured_toggle';
        $listing->auditDescription = sprintf(
            '%s marked %s as %s',
            $actor,
            $listing->name,
            $data['is_featured'] ? 'featured' : 'unfeatured',
        );
        $update = ['is_featured' => $data['is_featured']];
        if (! $data['is_featured']) {
            // Unfeature → clear any lingering token expiry for a clean slate.
            $update['featured_until'] = null;
        } elseif ($listing->featured_until !== null && $listing->featured_until->isPast()) {
            // Re-featuring a listing whose token already expired → make it an
            // indefinite manual feature, otherwise the auto-expire hook would
            // flip is_featured straight back to false. A valid future token
            // expiry is left untouched.
            $update['featured_until'] = null;
        }
        $listing->update($update);

        $listing = $listing->fresh();

        return response()->json([
            'is_featured' => (bool) $listing->is_featured,
            'featured_until' => $listing->featured_until,
        ]);
    }

    public function updateVerification(Request $request, Listing $listing)
    {
        $user = $request->user();
        // Admins audit anything. Team leaders audit listings owned by anyone
        // on their team (their own listings included). Everyone else is
        // rejected. Pattern mirrors ConversationPolicy::moderate().
        $isAdmin = $user->role->name === 'admin';
        if (! $isAdmin) {
            $ledAgentIds = app(TeamLeadershipService::class)->getLedAgentIds($user->id);
            if (empty($ledAgentIds) || ! in_array((int) $listing->agent_id, $ledAgentIds, true)) {
                abort(403);
            }
        }

        $validated = $request->validate([
            'verification_status' => 'nullable|in:verified,fully_verified,flagged,pending_review',
            'audit_notes' => 'nullable|string|max:2000',
            'audit_checklist' => 'nullable|array',
            'edited_fields' => 'nullable|array',
        ]);

        $previousVerification = $listing->verification_status;

        // Skip the default 'updated' audit — we'll fire a custom 'audited' one
        // below so audit decisions surface as their own event under
        // category=listings_audit instead of just another listing update.
        $listing->updateQuietly([
            'verification_status' => $validated['verification_status'] ?? null,
            'audit_notes' => $validated['audit_notes'] ?? null,
            'audit_checklist' => $validated['audit_checklist'] ?? null,
            'audit_edited_fields' => $validated['edited_fields'] ?? null,
            'audited_by' => $request->user()->id,
            'audited_at' => now(),
        ]);

        $listing->auditEvent = 'audited';
        $listing->isCustomEvent = true;
        $listing->auditCategoryOverride = 'listings_audit';
        $listing->auditSource = 'audit_modal';
        $listing->auditDescription = "Audit: {$previousVerification} → "
            .($validated['verification_status'] ?? 'cleared');
        $listing->auditCustomOld = ['verification_status' => $previousVerification];
        $listing->auditCustomNew = [
            'verification_status' => $validated['verification_status'] ?? null,
            'audit_notes' => $validated['audit_notes'] ?? null,
            'audit_checklist' => $validated['audit_checklist'] ?? null,
            'edited_fields' => $validated['edited_fields'] ?? null,
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
                    $listingUrl = 'https://filipinohomes.com/'.$roleSegment.'/create-listing'
                        .'?edit='.$listing->id;

                    $mailable = $status === 'flagged'
                        ? new ListingFlaggedMailer(
                            agentName: $agentUser->name ?? 'Agent',
                            listingTitle: $listing->name,
                            listingCode: $listing->code,
                            auditNotes: $validated['audit_notes'] ?? '',
                            auditChecklist: $validated['audit_checklist'] ?? null,
                            listingUrl: $listingUrl,
                            editedFields: $validated['edited_fields'] ?? null,
                            isLand: $isLand,
                        )
                        : new ListingVerifiedMailer(
                            agentName: $agentUser->name ?? 'Agent',
                            listingTitle: $listing->name,
                            listingCode: $listing->code,
                            auditNotes: $validated['audit_notes'] ?? '',
                            auditChecklist: $validated['audit_checklist'] ?? null,
                            listingUrl: $listingUrl,
                            isLand: $isLand,
                        );

                    Mail::to($agentUser->email)->send($mailable);
                    $emailSent = true;
                    // Positive log so we can confirm sends in storage/logs/laravel.log
                    // without checking the inbox. Pairs with the warning below.
                    Log::info('Listing verification email sent', [
                        'listing_id' => $listing->id,
                        'status' => $status,
                        'to' => $agentUser->email,
                        'is_land' => $isLand,
                    ]);
                } else {
                    Log::warning('Listing verification email skipped — no agent user/email', [
                        'listing_id' => $listing->id,
                        'status' => $status,
                        'has_agent' => (bool) $listing->agent,
                        'has_user' => (bool) $agentUser,
                    ]);
                }
            } catch (\Throwable $e) {
                // Non-fatal — audit status was saved, mail failure should not roll it back
                Log::warning('Listing verification email failed', [
                    'listing_id' => $listing->id,
                    'status' => $status,
                    'error' => $e->getMessage(),
                ]);
                app(AuditMailService::class)->recordFailure(
                    $e,
                    'ListingVerificationMailer',
                    $agentUser?->email ? [$agentUser->email] : [],
                    "Listing verification — {$status}",
                    [
                        'auditable_type' => Listing::class,
                        'auditable_id' => $listing->id,
                    ],
                );
            }
        }

        // Push the agent on an audit outcome so they hear about every decision:
        //   flagged        = action required (routes to the audit screen),
        //   verified       = passed review,
        //   fully_verified = the gold "Fully Verified" badge — a congratulations.
        // The mobile listing route is keyed on the listing id. Non-fatal — a push
        // failure must not roll back the saved audit status. Gated by the agent's
        // "Listing verification" category toggle inside ExpoPushService.
        if (in_array($status, ['flagged', 'verified', 'fully_verified'], true)) {
            try {
                $agentUser = optional($listing->agent)->user
                    ?? optional($listing->load('agent.user')->agent)->user;
                if ($agentUser) {
                    if ($status === 'flagged') {
                        // Surface why it was flagged (audit_notes) so the agent
                        // gets actionable detail, not just "needs attention".
                        $reason = trim((string) ($validated['audit_notes'] ?? ''));
                        $body = $reason !== ''
                            ? "“{$listing->name}” needs attention: ".Str::limit($reason, 140)
                            : "“{$listing->name}” needs your attention before it can be verified.";
                        app(ExpoPushService::class)->notify(
                            $agentUser,
                            'listing_flagged',
                            'Listing needs attention ⚠️',
                            $body,
                            ['type' => 'listing_flagged', 'id' => $listing->id, 'reason' => $reason ?: null],
                        );
                    } elseif ($status === 'fully_verified') {
                        app(ExpoPushService::class)->notify(
                            $agentUser,
                            'listing_fully_verified',
                            'Fully Verified 🎉',
                            "Congratulations! “{$listing->name}” is now Fully Verified — authentic, reviewed & trusted.",
                            ['type' => 'listing_fully_verified', 'id' => $listing->id],
                        );
                    } else {
                        app(ExpoPushService::class)->notify(
                            $agentUser,
                            'listing_verified',
                            'Listing verified ✅',
                            "“{$listing->name}” passed review and is now verified.",
                            ['type' => 'listing_verified', 'id' => $listing->id],
                        );
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Push notify (listing audit) failed', [
                    'listing_id' => $listing->id,
                    'status' => $status,
                    'error' => $e->getMessage(),
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
        if (! $isAdmin) {
            $ledAgentIds = app(TeamLeadershipService::class)->getLedAgentIds($user->id);
            if (empty($ledAgentIds)) {
                abort(403);
            }
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
            ]);

        if (! $isAdmin) {
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
                'property.barangay.city.province',
                'category',
                'agent.user',
            ])
            ->firstOrFail();

        $user = auth('sanctum')->user();

        if ($listing->visibility !== 'public') {
            if (! $user || ($user->role->name !== 'admin' && $listing->agent_id !== ($user->agent->id ?? null))) {
                abort(403, 'This listing is private. Only the owner or admin can view it.');
            }
        }

        return new ListingResource($listing);
    }

    public function featured(Request $request): ListingResourceCollection
    {
        $listings = Listing::publiclyListed()
            ->where('is_featured', true)
            ->where(function ($q) {
                $q->whereNull('featured_until')->orWhere('featured_until', '>=', now());
            })
            ->with([
                'property.propertyAttribute.subtype',
                // nearbyFacility omitted — whenLoaded() in PropertyResource and the
                // featured card doesn't render facilities (detail page loads it).
                'category',
                'agent' => function ($q) {
                    $q->withCount('listings');
                },
            ])
            ->get();

        return new ListingResourceCollection($listings);
    }

    public function update(Request $request, Listing $listing)
    {
        $this->authorize('update', $listing);

        $validated = $request->validate([
            'code' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|max:255',
            'name' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255',
            'price' => 'sometimes|numeric|min:0',
            'featured_photo' => 'nullable|string|max:255',
            'is_featured' => 'sometimes|boolean',
            'clicks' => 'nullable|integer|min:0',
            'property_id' => 'sometimes|integer|exists:properties,id',
            'category_id' => 'sometimes|integer|exists:categories,id',
        ]);

        $actor = $request->user()?->name ?? 'Someone';
        $listing->auditSource = 'quick_edit';
        $listing->auditDescription = sprintf('%s edited %s', $actor, $listing->name);
        $listing->update($validated);

        return new ListingResource($listing);
    }

    public function destroy(Request $request, Listing $listing)
    {
        $this->authorize('delete', $listing);

        $actor = $request->user()?->name ?? 'Someone';
        $listing->auditSource = 'listings_table';
        $listing->auditDescription = sprintf('%s deleted listing %s', $actor, $listing->name);

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

    public function restore(Request $request, $id)
    {
        $listing = Listing::withTrashed()->findOrFail($id);
        $this->authorize('delete', $listing);

        $actor = $request->user()?->name ?? 'Someone';
        $listing->auditSource = 'listings_table';
        $listing->auditDescription = sprintf('%s restored listing %s', $actor, $listing->name);

        DB::transaction(function () use ($listing) {
            // Restore in the inverse order of destroy(): attribute → property → listing
            $property = $listing->property()->withTrashed()->first();
            if ($property) {
                $propertyAttribute = $property->propertyAttribute()->withTrashed()->first();
                if ($propertyAttribute && $propertyAttribute->trashed()) {
                    $propertyAttribute->restore();
                }
                if ($property->trashed()) {
                    $property->restore();
                }
            }
            if ($listing->trashed()) {
                $listing->restore();
            }
        });

        return response()->json(['message' => 'Listing and related rows restored: listings, properties, property_attributes']);
    }

    /**
     * Manually input the photo arrays for a removed (photo-migration
     * soft-deleted) listing. The migration emptied these to [] and kept no
     * copy, so the admin pastes the originals back in. The listing STAYS
     * soft-deleted/removed — this only writes the arrays.
     *
     * With `redownload=1`, every pasted URL is fetched and re-hosted through
     * the ImageUploadController pipeline (new bucket, WebP ≤ 50 KB). Each
     * unique source is uploaded once and the same old→new mapping is applied
     * to both featured_photo and property.photos, so shared images stay
     * consistent. Dead/unreachable URLs are dropped.
     */
    public function updateRemovedPhotos(Request $request, $id)
    {
        $listing = Listing::withTrashed()->findOrFail($id);
        $this->authorize('update', $listing);

        $data = $request->validate([
            'featured_photo' => 'present|array',
            'featured_photo.*' => 'string',
            'photos' => 'nullable|array',
            'photos.*' => 'string',
            'redownload' => 'sometimes|boolean',
        ]);

        $clean = fn (array $urls) => array_values(array_filter(
            array_map(fn ($u) => is_string($u) ? trim($u) : $u, $urls),
            fn ($u) => is_string($u) && $u !== '',
        ));

        $featuredIn = $clean($data['featured_photo'] ?? []);
        $photosIn = $clean($data['photos'] ?? []);
        $redownload = filter_var($request->input('redownload'), FILTER_VALIDATE_BOOLEAN);

        $failed = [];
        if ($redownload) {
            $uploader = app(RemovedPhotoUploadController::class);
            // Re-host all URLs in one pass — downloads run concurrently inside
            // uploadManyFromUrls (was a sequential per-URL loop before).
            $map = $uploader->uploadManyFromUrls(array_merge($featuredIn, $photosIn), '/filipinohomes-new');
            foreach ($map as $url => $newUrl) {
                if ($newUrl === null) {
                    $failed[] = $url;
                    Log::warning('removed-photos redownload failed', [
                        'listing_id' => $listing->id, 'url' => $url,
                    ]);
                }
            }
            $applyMap = fn (array $urls) => array_values(array_filter(
                array_map(fn ($u) => $map[$u] ?? null, $urls),
            ));
            $featuredOut = $applyMap($featuredIn);
            $photosOut = $applyMap($photosIn);
        } else {
            $featuredOut = $featuredIn;
            $photosOut = $photosIn;
        }

        DB::transaction(function () use ($listing, $data, $featuredOut, $photosOut) {
            $listing->featured_photo = $featuredOut;
            $listing->saveQuietly();

            if (array_key_exists('photos', $data)) {
                $property = $listing->property()->withTrashed()->first();
                if ($property) {
                    $property->photos = $photosOut;
                    $property->saveQuietly();
                }
            }
        });

        $listing->load(['property' => fn ($q) => $q->withTrashed()]);

        return response()->json([
            'message' => $redownload
                ? sprintf('Re-hosted %d photo(s)%s.', count($featuredOut) + count($photosOut), $failed ? ', '.count($failed).' failed' : '')
                : 'Photos saved.',
            'featured_photo' => $listing->featured_photo,
            'photos' => optional($listing->property)->photos,
            'failed' => $failed,
        ]);
    }

    /**
     * Listing Insights — province breakdown. Mirrors ProjectController::byProvince
     * but counts ALL listings (project + standalone) and uses listing-row counts.
     */
    public function insightsByProvince(Request $request, ListingByProvinceService $insights): JsonResponse
    {
        $agentIds = $this->resolveInsightsAgentScope($request);

        $sortBy = (string) $request->query('sort_by', 'city_count');
        $dateStart = $request->query('date_start');
        $dateEnd = $request->query('date_end');
        $provinceId = $request->query('province_id');
        $cityId = $request->query('city_id');
        [$island, $region, $barangayId] = $this->insightsScopeParams($request);

        return response()->json($insights->provinceBreakdown(
            $sortBy,
            $agentIds,
            is_string($dateStart) ? $dateStart : null,
            is_string($dateEnd) ? $dateEnd : null,
            is_numeric($provinceId) ? (int) $provinceId : null,
            is_numeric($cityId) ? (int) $cityId : null,
            $island,
            $region,
            $barangayId
        ));
    }

    /**
     * Listing Insights — status breakdown. One row per properties.status with
     * category mix + top provinces.
     */
    public function insightsByStatus(Request $request, ListingByStatusService $insights): JsonResponse
    {
        $agentIds = $this->resolveInsightsAgentScope($request);
        $sortBy = (string) $request->query('sort_by', 'priority');
        $dateStart = $request->query('date_start');
        $dateEnd = $request->query('date_end');
        [$island, $region, $barangayId] = $this->insightsScopeParams($request);

        return response()->json($insights->statusBreakdown(
            $sortBy,
            $agentIds,
            is_string($dateStart) ? $dateStart : null,
            is_string($dateEnd) ? $dateEnd : null,
            $request->query('province_id') !== null ? (int) $request->query('province_id') : null,
            $request->query('city_id') !== null ? (int) $request->query('city_id') : null,
            (string) $request->query('group_by', 'province'),
            $island,
            $region,
            $barangayId
        ));
    }

    /**
     * Listing Insights — property-type breakdown. One row per property type
     * with subtype children and per-category + per-transaction-status counts.
     */
    public function insightsByType(Request $request, ListingByTypeService $insights): JsonResponse
    {
        $agentIds = $this->resolveInsightsAgentScope($request);
        $dateStart = $request->query('date_start');
        $dateEnd = $request->query('date_end');
        $cityId = $request->query('city_id');
        $provinceId = $request->query('province_id');
        [$island, $region, $barangayId] = $this->insightsScopeParams($request);

        return response()->json($insights->typeBreakdown(
            is_string($dateStart) ? $dateStart : null,
            is_string($dateEnd) ? $dateEnd : null,
            $agentIds,
            $cityId !== null ? (int) $cityId : null,
            $provinceId !== null ? (int) $provinceId : null,
            $island,
            $region,
            $barangayId
        ));
    }

    /**
     * Listing Insights — paginated listings for a single status. Used by the
     * "Listings by Status" drawer drill-down.
     */
    public function insightsListingsForStatus(Request $request, ListingByStatusService $insights, string $status): JsonResponse
    {
        $agentIds = $this->resolveInsightsAgentScope($request);

        return response()->json($insights->listingsForStatus($status, [
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 20),
            'category' => (string) $request->query('category', ''),
            'visibility' => (string) $request->query('visibility', ''),
            'province_id' => $request->query('province_id') !== null
                ? (int) $request->query('province_id')
                : null,
            'city_id' => $request->query('city_id') !== null
                ? (int) $request->query('city_id')
                : null,
            'date_start' => $request->query('date_start'),
            'date_end' => $request->query('date_end'),
            'island' => is_string($request->query('island')) ? $request->query('island') : null,
            'region' => is_string($request->query('region')) ? $request->query('region') : null,
            'barangay_id' => $request->query('barangay_id') !== null
                ? (int) $request->query('barangay_id')
                : null,
        ], $agentIds));
    }

    /**
     * Listing Insights — paginated ATS listings for a single city. Powers the
     * "Listings by City" ATS drill-down drawer (each row carries its ats_status
     * + attachment URLs for the MediaLightbox).
     */
    public function insightsCityAtsListings(Request $request, ListingByCityService $insights, int $city): JsonResponse
    {
        $agentIds = $this->resolveInsightsAgentScope($request);
        $dateStart = $request->query('date_start');
        $dateEnd = $request->query('date_end');

        return response()->json($insights->listingsForCity($city, [
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 20),
            'province_id' => $request->query('province_id') !== null
                ? (int) $request->query('province_id')
                : null,
            'date_start' => is_string($dateStart) ? $dateStart : null,
            'date_end' => is_string($dateEnd) ? $dateEnd : null,
            'category' => (string) $request->query('category', ''),
            'status' => (string) $request->query('status', ''),
            'ats_status' => (string) $request->query('ats_status', ''),
            'attachment' => (string) $request->query('attachment', 'with'),
        ], $agentIds));
    }

    /**
     * Listing Insights — geo clusters for the shared hierarchical map. Returns
     * count-weighted centroid bubbles for the current drill level (island →
     * region → province → city → barangay), scoped by the same filters the
     * insight tabs use. Leader-accessible (same scope as the other insights).
     */
    public function insightsClusters(Request $request, ListingClusterService $insights): JsonResponse
    {
        $agentIds = $this->resolveInsightsAgentScope($request);

        $filters = $request->validate([
            'date_start' => 'nullable|date',
            'date_end' => 'nullable|date',
            'island' => 'nullable|in:luzon,visayas,mindanao',
            'region' => 'nullable|string|max:40',
            'province_id' => 'nullable|integer',
            'city_id' => 'nullable|integer',
            'barangay_id' => 'nullable|integer',
            'level' => 'nullable|in:island,region,province,city,barangay',
            'min_lat' => 'nullable|numeric',
            'max_lat' => 'nullable|numeric',
            'min_lng' => 'nullable|numeric',
            'max_lng' => 'nullable|numeric',
        ]);

        // Drop an unknown region key rather than silently scoping to nothing.
        if (! empty($filters['region']) && ! in_array($filters['region'], RegionMap::REGIONS, true)) {
            unset($filters['region']);
        }

        return response()->json($insights->configure($filters, $agentIds)->clusters());
    }

    /**
     * Shared scope params for the insight tabs (island / region / barangay),
     * validated: island ∈ {luzon,visayas,mindanao}, region ∈ RegionMap::REGIONS.
     * Unknown values are dropped to null so they no-op instead of zeroing out.
     *
     * @return array{0: ?string, 1: ?string, 2: ?int}
     */
    private function insightsScopeParams(Request $request): array
    {
        $island = $request->query('island');
        $island = in_array($island, IslandMap::ISLANDS, true) ? $island : null;

        $region = $request->query('region');
        $region = in_array($region, RegionMap::REGIONS, true) ? $region : null;

        $barangayId = $request->query('barangay_id');
        $barangayId = is_numeric($barangayId) ? (int) $barangayId : null;

        return [$island, $region, $barangayId];
    }

    /**
     * Resolve who's allowed to call the insights endpoints + what agent scope
     * the service should apply. Admins → null (unscoped, see everything).
     * Team leaders → their led agent IDs (their team's footprint). Everyone
     * else → 403.
     */
    private function resolveInsightsAgentScope(Request $request): ?array
    {
        $user = $request->user();
        if (($user->role->name ?? null) === 'admin') {
            return null;
        }

        $ledAgentIds = app(TeamLeadershipService::class)->getLedAgentIds($user->id);
        if (empty($ledAgentIds)) {
            abort(403);
        }

        return $ledAgentIds;
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
        if (! $isAdmin) {
            $ledAgentIds = app(TeamLeadershipService::class)->getLedAgentIds($user->id);
            if (empty($ledAgentIds) || ! in_array((int) $listing->agent_id, $ledAgentIds, true)) {
                abort(403);
            }
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 50)));

        $paginated = Audit::query()
            ->where('auditable_type', Listing::class)
            ->where('auditable_id', $listing->id)
            ->latest('id')
            ->paginate($perPage);

        // Strip noise (clicks/impressions/updated_at/seo_tags) and drop rows
        // whose only changes were those noise fields. Keeps the audit-modal
        // history focused on meaningful events.
        $rows = ActivityLogController::scrubRows(array_map(
            fn ($m) => $m->toArray(),
            $paginated->items()
        ));

        return response()->json([
            'data' => $rows,
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'from' => $paginated->firstItem(),
            'to' => $paginated->lastItem(),
        ]);
    }
}
