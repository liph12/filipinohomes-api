<?php

namespace App\Http\Controllers;

use App\Services\Project\ProjectService;
use App\Services\Project\ProjectByProvinceService;
use App\Services\Project\ProjectByNameService;
use App\Models\Project;
use App\Models\Listing;
use App\Models\PropertySubtype;
use App\Models\Furnishing;
use App\Http\Resources\ListingResourceCollection;
use App\Http\Resources\ProjectResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    private function normalizeLocationKeys(array $data): array
    {
        if (array_key_exists('province_id', $data) && !array_key_exists('prov_id', $data)) {
            $data['prov_id'] = $data['province_id'];
        }

        if (array_key_exists('barangay_id', $data) && !array_key_exists('brgy_id', $data)) {
            $data['brgy_id'] = $data['barangay_id'];
        }

        unset($data['province_id'], $data['barangay_id']);

        return $data;
    }

    private function trackingIdentifier(Request $request): string
    {
        if ($request->user()) {
            return 'user_' . $request->user()->id;
        }

        $deviceId = $request->input('device_id')
            ?? $request->header('X-Device-Id')
            ?? $request->header('x-device-id')
            ?? $request->cookie('device_id');

        if ($deviceId) {
            return 'dev_' . (string) $deviceId . '|' . (string) $request->ip();
        }

        $ua = (string) ($request->userAgent() ?? 'unknown');
        $ip = (string) $request->ip();

        return 'guest_' . substr(hash('sha256', $ip . '|' . $ua), 0, 32);
    }

    public function index(ProjectService $service): JsonResponse
    {
        return response()->json([
            'message' => 'Projects fetched successfully',
            'data' => $service->fetchProjects(),
        ]);
    }

    public function store(Request $request, ProjectService $service): ProjectResource
    {
        $this->authorize('create', Project::class);

        $data = $this->normalizeLocationKeys($request->validate([
            'name' => 'required|string|max:255',
            'source_property_id' => 'nullable|exists:properties,id',
            'prov_id' => 'nullable|exists:provinces,id',
            'city_id' => 'nullable|exists:cities,id',
            'brgy_id' => 'nullable|exists:barangays,id',
            'province_id' => 'nullable|exists:provinces,id',
            'barangay_id' => 'nullable|exists:barangays,id',
            'street' => 'nullable|string|max:255',
            'mapaddress' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'complete_address' => 'nullable|string|max:1000',
            'featured_photo' => 'nullable|array',
            'featured_photo.*' => 'string|max:1000',
            'photos_url' => 'nullable|array',
            'photos_url.*' => 'string|max:1000',
        ]));

        $payload = array_merge($data, [
            'date_updated' => now(),
            'added_by' => $request->user()->email,
        ]);

        // Ensure mapaddress has a raw address fallback
        if (empty($payload['mapaddress'] ?? null)) {
            $payload['mapaddress'] = (string) ($payload['complete_address'] ?? '');
        }

        $project = Project::create($payload);
        $service->syncProjectProperties($project, $data['source_property_id'] ?? null);

        Cache::forget('projects_db');

        return ProjectResource::make($project);
    }

    public function update(Request $request, $id, ProjectService $service): ProjectResource
    {
        $project = Project::findOrFail($id);
        $this->authorize('update', $project);

        $updates = array_merge(
            $this->normalizeLocationKeys($request->validate([
                'name' => 'sometimes|string|max:255',
                'source_property_id' => 'sometimes|exists:properties,id|nullable',
                'prov_id' => 'sometimes|exists:provinces,id|nullable',
                'city_id' => 'sometimes|exists:cities,id|nullable',
                'brgy_id' => 'sometimes|exists:barangays,id|nullable',
                'province_id' => 'sometimes|exists:provinces,id|nullable',
                'barangay_id' => 'sometimes|exists:barangays,id|nullable',
                'street' => 'sometimes|string|max:255|nullable',
                'mapaddress' => 'sometimes|string|max:500|nullable',
                'latitude' => 'sometimes|numeric|nullable',
                'longitude' => 'sometimes|numeric|nullable',
                'complete_address' => 'sometimes|string|max:1000|nullable',
                'featured_photo' => 'sometimes|array|nullable',
                'featured_photo.*' => 'string|max:1000',
                'photos_url' => 'sometimes|array|nullable',
                'photos_url.*' => 'string|max:1000',
            ])),
            ['date_updated' => now()]
        );

        // Fallback for mapaddress during update as well
        if (empty($updates['mapaddress'] ?? null)) {
            $updates['mapaddress'] = (string) ($updates['complete_address'] ?? $project->complete_address ?? $project->mapaddress ?? '');
        }

        $project->update($updates);
        $service->syncProjectProperties($project->fresh(), $updates['source_property_id'] ?? null);

        Cache::forget('projects_db');

        return ProjectResource::make($project);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('delete', $project);
        $project->delete();
        Cache::forget('projects_db');

        return response()->json(['message' => 'Project deleted successfully']);
    }

    public function restore(Request $request, $id): JsonResponse
    {
        $project = Project::withTrashed()->findOrFail($id);
        $this->authorize('delete', $project);
        $project->restore();
        Cache::forget('projects_db');

        return response()->json([
            'message' => 'Project restored successfully',
            'data' => ProjectResource::make($project->fresh()),
        ]);
    }

    public function trackView(Request $request, string $slug): JsonResponse
    {
        $project = Project::query()
            ->where('slug', $slug)
            ->first();

        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $identifier = $this->trackingIdentifier($request);
        $now = now('Asia/Manila');
        $today = $now->toDateString();

        $viewKey = "{$identifier}_project_{$slug}_view";
        $lastViewed = Cache::get($viewKey);

        if ($lastViewed !== $today) {
            if ($project->views === null) {
                $project->forceFill(['views' => 0])->saveQuietly();
            }

            $project->increment('views');
            Cache::put($viewKey, $today, $now->copy()->endOfDay());
        }

        return response()->json(['success' => true]);
    }

    public function projects(Request $request, ProjectService $service): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $search = (string) $request->query('search', '');
        $sortBy = (string) $request->query('sort_by', 'properties');
        $searchField = (string) $request->query('search_field', 'all');
        $projects = $service->fetchProjectsPaginated(12, $search, $sortBy, $searchField);

        return response()->json([
            'message' => 'Projects fetched successfully',
            'data' => $projects->items(),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(), 
            ],
        ]);
    }

    public function byProvince(Request $request, ProjectByProvinceService $insights): JsonResponse
    {
        $user = $request->user();
        if (($user->role->name ?? null) !== 'admin') {
            abort(403);
        }

        $sortBy = (string) $request->query('sort_by', 'city_count');
        return response()->json($insights->provinceBreakdown(
            $sortBy,
            $request->query('province_id') !== null ? (int) $request->query('province_id') : null,
            $request->query('city_id') !== null ? (int) $request->query('city_id') : null,
            $request->query('date_start'),
            $request->query('date_end'),
        ));
    }

    /**
     * Paginated list of every project (and standalone is_project=1 property
     * with no project_id) with its full stat breakdown. One row per project
     * entity. Used by the new "Projects by Name" dashboard section.
     *
     * Query params:
     *   page         (int, default 1)
     *   per_page     (int, default 20)
     *   search       (string, matches projects.name or properties.name LIKE)
     *   sort_by      ('name' | 'total_listings' | 'recent', default 'total_listings')
     *   category     (optional: 'for-sale' | 'for-rent' | 'foreclosure')
     */
    public function byName(Request $request, ProjectByNameService $insights): JsonResponse
    {
        $user = $request->user();
        if (($user->role->name ?? null) !== 'admin') {
            abort(403);
        }

        return response()->json($insights->projectsByName([
            'page'        => (int) $request->query('page', 1),
            'per_page'    => (int) $request->query('per_page', 20),
            'search'      => (string) $request->query('search', ''),
            'sort_by'     => (string) $request->query('sort_by', 'total_listings'),
            'category'    => (string) $request->query('category', ''),
            'province_id' => $request->query('province_id') !== null ? (int) $request->query('province_id') : null,
            'city_id'     => $request->query('city_id') !== null ? (int) $request->query('city_id') : null,
            'date_start'  => $request->query('date_start'),
            'date_end'    => $request->query('date_end'),
        ]));
    }
    /**
     * Single-project drill-down. Project key is either "project:{id}" or
     * "property:{id}" (the latter for standalone is_project=1 properties).
     * Returns the project entity + aggregate totals + a paginated list of
     * its listings.
     */
    public function insightsDetail(Request $request, ProjectByNameService $insights, string $projectKey): JsonResponse
    {
        $user = $request->user();
        if (($user->role->name ?? null) !== 'admin') {
            abort(403);
        }

        $result = $insights->projectDetail($projectKey, [
            'page'     => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 50),
            'status'   => (string) $request->query('status', ''),
            'category' => (string) $request->query('category', ''),
        ]);

        if ($result === null) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
        return response()->json($result);
    }

    public function unassociatedProjects(Request $request, ProjectService $service): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $search = (string) $request->query('search', '');
        $properties = $service->fetchUnassociatedProjectPropertiesPaginated(10, $page, $search);

        return response()->json([
            'message' => 'Unassociated project properties fetched successfully',
            'data' => $properties->items(),
            'meta' => [
                'current_page' => $properties->currentPage(),
                'last_page' => $properties->lastPage(),
                'per_page' => $properties->perPage(),
                'total' => $properties->total(),
            ],
        ]);
    }

    public function deletedProjects(Request $request, ProjectService $service): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $search = (string) $request->query('search', '');
        $projects = $service->fetchDeletedProjectsPaginated(10, $page, $search);

        return response()->json([
            'message' => 'Deleted projects fetched successfully',
            'data' => ProjectResource::collection($projects->items()),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
            ],
        ]);
    }

    public function linkUnassociatedProperty(Request $request, int $id, ProjectService $service): JsonResponse
    {
        $project = Project::query()->findOrFail($id);
        $this->authorize('link', $project);

        $data = $request->validate([
            'source_property_id' => 'required|exists:properties,id',
        ]);

        $linkedCount = $service->syncProjectProperties($project, (int) $data['source_property_id']);

        Cache::forget('projects_db');

        return response()->json([
            'message' => 'Project linked successfully',
            'data' => ProjectResource::make($project->fresh()),
            'linked_count' => $linkedCount,
        ]);
    }

    public function projectsWithListings(Request $request, ProjectService $service): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $search = (string) $request->query('search', '');
        $sortBy = (string) $request->query('sort_by', 'properties');
        // Optional selling-price window: keep only projects with at least one
        // public For Sale unit priced inside it. Non-numeric / negative → ignored.
        $price = static fn (string $key): ?float => is_numeric($v = $request->query($key)) && (float) $v > 0 ? (float) $v : null;
        $priceMin = $price('price_min');
        $priceMax = $price('price_max');

        $projects = $service->fetchProjectsWithListingsPaginated(12, $search, $sortBy, $priceMin, $priceMax);

        return response()->json([
            'message' => 'Projects with listings fetched successfully',
            'data' => $projects->items(),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
            ],
        ]);
    }

    public function linkDeletedProjectProperties(Request $request, int $id, ProjectService $service): JsonResponse
    {
        $sourceProject = Project::withTrashed()->findOrFail($id);

        $data = $request->validate([
            'destination_project_id' => 'required|integer',
        ]);

        $destinationProject = Project::query()->findOrFail((int) $data['destination_project_id']);
        $this->authorize('link', $destinationProject);

        if ((int) $destinationProject->id === (int) $sourceProject->id) {
            return response()->json(['message' => 'Destination project must be different.'], 422);
        }

        $linkedCount = $service->relinkDeletedProjectProperties($sourceProject, $destinationProject);

        Cache::forget('projects_db');

        return response()->json([
            'message' => 'Project properties linked successfully',
            'data' => ProjectResource::make($destinationProject->fresh()),
            'linked_count' => $linkedCount,
        ]);
    }

    public function show(Request $request, string $slug, ProjectService $service): JsonResponse
    {
        $project = Project::query()
            ->withCount([
                'properties as properties_count' => function ($query) {
                    $query->where('is_project', true);
                },
            ])
            ->where('slug', $slug)
            ->first();

        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $geo = ($project->latitude && $project->longitude)
            ? [
                'lat' => (float) $project->latitude,
                'lng' => (float) $project->longitude,
            ]
            : null;

        $sortBy = match ((string) $request->query('sort_by', 'featured')) {
            'newest' => 'newest',
            'oldest' => 'oldest',
            'views' => 'most-viewed',
            'price_asc' => 'price-low',
            'price_desc' => 'price-high',
            default => 'featured',
        };

        $activeSubtype = null;
        $subtypeSlug = (string) $request->query('active_subtype', '');
        if ($subtypeSlug !== '') {
            $activeSubtype = PropertySubtype::query()
                ->get(['id', 'name'])
                ->first(fn ($s) => Str::slug($s->name) === $subtypeSlug);

            if (!$activeSubtype) {
                return response()->json(['message' => 'Unit type not found'], 404);
            }
        }

        $activeFurnishing = null;
        $furnishingSlug = (string) $request->query('active_furnishing', '');
        if ($furnishingSlug !== '') {
            $activeFurnishing = Furnishing::query()
                ->get(['id', 'name'])
                ->first(fn ($f) => Str::slug($f->name) === $furnishingSlug);

            if (!$activeFurnishing) {
                return response()->json(['message' => 'Furnishing not found'], 404);
            }
        }

        $activeUnitType = (string) $request->query('active_unit_type', 'all');
        $categoryName = match ($activeUnitType) {
            'sale' => 'For Sale',
            'rent' => 'For Rent',
            'foreclosure' => 'Foreclosure',
            default => null,
        };

        $scopeQuery = Listing::query()
            ->publiclyListed()
            ->whereHas('property', fn ($q) =>
                $q->where('is_project', true)
                  ->where('project_id', $project->id)
            )
            ->when($categoryName, fn ($q) =>
                $q->whereHas('category', fn ($c) => $c->where('name', $categoryName))
            );

        $subtypeConstraint = fn ($q) => $q->whereHas('property.propertyAttribute', fn ($a) =>
            $a->where('property_subtype_id', $activeSubtype->id)
        );
        $furnishingConstraint = fn ($q) => $q->whereHas('property', fn ($p) =>
            $p->where('furnishing_id', $activeFurnishing->id)
        );

        $facetSubtypes = (clone $scopeQuery)
            ->when($activeFurnishing, $furnishingConstraint)
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->distinct()
            ->pluck('property_subtypes.name')
            ->filter()
            ->sort()
            ->values();

        $facetFurnishings = (clone $scopeQuery)
            ->when($activeSubtype, $subtypeConstraint)
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('furnishings', 'furnishings.id', '=', 'properties.furnishing_id')
            ->distinct()
            ->pluck('furnishings.name')
            ->filter()
            ->sort()
            ->values();

        $baseListingsQuery = (clone $scopeQuery)
            ->when($activeSubtype, $subtypeConstraint)
            ->when($activeFurnishing, $furnishingConstraint)
            ->with([
                'property.propertyAttribute.subtype',
                'property.nearbyFacility',
                'property.barangay.city.province',
                'category',
                'agent' => fn ($q) => $q->withCount('listings')->with(['user', 'pageBuilder']),
            ]);

        $filteredListingsQuery = (clone $baseListingsQuery)->filter($request);

        // Single aggregation query instead of 4 separate COUNTs
        $breakdown = Listing::query()
            ->publiclyListed()
            ->whereHas('property', fn ($q) =>
                $q->where('is_project', true)
                  ->where('project_id', $project->id)
            )
            ->filter($request)
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->selectRaw("
                COUNT(*) as total,
                SUM(categories.name = 'For Sale') as sale,
                SUM(categories.name = 'For Rent') as rent,
                SUM(categories.name = 'Foreclosure') as foreclosure
            ")
            ->first();

        $listingsBreakdown = [
            'total'       => (int) ($breakdown->total ?? 0),
            'sale'        => (int) ($breakdown->sale ?? 0),
            'rent'        => (int) ($breakdown->rent ?? 0),
            'foreclosure' => (int) ($breakdown->foreclosure ?? 0),
        ];

        $listings = $filteredListingsQuery
            ->sorted($sortBy)
            ->paginate(12);

        $projectData = ProjectResource::make($project);
        $projectData['geo_coordinates'] = $geo;
        $projectData['unit_stats'] = $service->unitStatsFor($project);

        return response()->json([
            'message' => 'Project fetched successfully',
            'data' => (object) $projectData,
            'listings' => (new ListingResourceCollection($listings)),
            'listings_meta' => [
                'current_page' => $listings->currentPage(),
                'last_page' => $listings->lastPage(),
                'per_page' => $listings->perPage(),
                'total' => $listings->total(),
            ],
            'listings_breakdown' => $listingsBreakdown,
            'active_subtype' => $activeSubtype
                ? ['id' => $activeSubtype->id, 'name' => $activeSubtype->name, 'slug' => $subtypeSlug]
                : null,
            'active_furnishing' => $activeFurnishing
                ? ['id' => $activeFurnishing->id, 'name' => $activeFurnishing->name, 'slug' => $furnishingSlug]
                : null,
            'facets' => [
                'subtypes' => $facetSubtypes,
                'furnishings' => $facetFurnishings,
            ],
        ]);
    }
}
