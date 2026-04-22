<?php

namespace App\Http\Controllers;

use App\Services\Project\ProjectService;
use App\Models\Project;
use App\Models\Listing;
use App\Http\Resources\ListingResourceCollection;
use App\Http\Resources\ProjectResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        $projects = $service->fetchProjectsPaginated(12, $search, $sortBy);

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

    public function byProvince(Request $request): JsonResponse
    {
        $user = $request->user();
        $sortBy = (string) $request->query('sort_by', 'city_count');

        if (($user->role->name ?? null) !== 'admin') {
            abort(403);
        }

        $cityRows = DB::table('projects')
            ->join('provinces', 'provinces.id', '=', 'projects.prov_id')
            ->join('cities', 'cities.id', '=', 'projects.city_id')
            ->whereNull('projects.deleted_at')
            ->whereNotNull('projects.prov_id')
            ->whereNotNull('projects.city_id')
            ->select(
                'projects.prov_id as province_id',
                'provinces.name as province_name',
                'projects.city_id as city_id',
                'cities.name as city_name',
                DB::raw('COUNT(*) as project_count')
            )
            ->groupBy(
                'projects.prov_id',
                'provinces.name',
                'projects.city_id',
                'cities.name'
            )
            ->orderBy('provinces.name')
            ->orderByDesc('project_count')
            ->orderBy('cities.name')
            ->get();

        $publicProjectListingCategories = DB::table('listings')
            ->select('listings.property_id', 'listings.category_id')
            ->whereNull('listings.deleted_at')
            ->where('listings.visibility', '=', 'public')
            ->distinct();

        $categoryRows = DB::table('properties')
            ->join('projects', function ($join) {
                $join->on('projects.id', '=', 'properties.project_id')
                    ->whereNull('projects.deleted_at')
                    ->whereNotNull('projects.prov_id');
            })
            ->joinSub($publicProjectListingCategories, 'project_listing_categories', function ($join) {
                $join->on('project_listing_categories.property_id', '=', 'properties.id');
            })
            ->join('categories', 'categories.id', '=', 'project_listing_categories.category_id')
            ->whereNull('properties.deleted_at')
            ->where('properties.is_project', '=', 1)
            ->whereNotNull('properties.project_id')
            ->whereIn('categories.name', ['For Sale', 'For Rent', 'Foreclosure'])
            ->select(
                'projects.prov_id as province_id',
                'categories.name as category_name',
                DB::raw('COUNT(DISTINCT properties.project_id) as project_count')
            )
            ->groupBy('projects.prov_id', 'categories.name')
            ->get();

        $categoryCountsByProvince = [];

        foreach ($categoryRows as $row) {
            $provinceId = (int) $row->province_id;
            $categoryName = (string) $row->category_name;
            $count = (int) $row->project_count;

            $categoryCountsByProvince[$provinceId] ??= [
                'for_sale' => 0,
                'for_rent' => 0,
                'foreclosure' => 0,
            ];

            if ($categoryName === 'For Sale') {
                $categoryCountsByProvince[$provinceId]['for_sale'] = $count;
            } elseif ($categoryName === 'For Rent') {
                $categoryCountsByProvince[$provinceId]['for_rent'] = $count;
            } elseif ($categoryName === 'Foreclosure') {
                $categoryCountsByProvince[$provinceId]['foreclosure'] = $count;
            }
        }

        $provinces = [];

        foreach ($cityRows as $row) {
            $provinceId = (int) $row->province_id;
            $projectCount = (int) $row->project_count;

            if (!isset($provinces[$provinceId])) {
                $provinces[$provinceId] = [
                    'province_id' => $provinceId,
                    'province_name' => (string) $row->province_name,
                    'project_count' => 0,
                    'city_count' => 0,
                    'listing_breakdown' => $categoryCountsByProvince[$provinceId] ?? [
                        'for_sale' => 0,
                        'for_rent' => 0,
                        'foreclosure' => 0,
                    ],
                    'cities' => [],
                ];
            }

            $provinces[$provinceId]['project_count'] += $projectCount;
            $provinces[$provinceId]['city_count'] += 1;
            $provinces[$provinceId]['cities'][] = [
                'city_id' => (int) $row->city_id,
                'city_name' => (string) $row->city_name,
                'project_count' => $projectCount,
            ];
        }

        $provinceData = array_values($provinces);

        foreach ($provinceData as &$province) {
            usort($province['cities'], function (array $a, array $b) {
                return [$b['project_count'], $a['city_name']] <=> [$a['project_count'], $b['city_name']];
            });
        }
        unset($province);

        usort($provinceData, function (array $a, array $b) use ($sortBy) {
            return match ($sortBy) {
                'project_count' => [$b['project_count'], $b['city_count'], $a['province_name']] <=> [$a['project_count'], $a['city_count'], $b['province_name']],
                'for_sale' => [$b['listing_breakdown']['for_sale'], $b['city_count'], $a['province_name']] <=> [$a['listing_breakdown']['for_sale'], $a['city_count'], $b['province_name']],
                'for_rent' => [$b['listing_breakdown']['for_rent'], $b['city_count'], $a['province_name']] <=> [$a['listing_breakdown']['for_rent'], $a['city_count'], $b['province_name']],
                'foreclosure' => [$b['listing_breakdown']['foreclosure'], $b['city_count'], $a['province_name']] <=> [$a['listing_breakdown']['foreclosure'], $a['city_count'], $b['province_name']],
                'province_name' => [$a['province_name'], $b['project_count']] <=> [$b['province_name'], $a['project_count']],
                default => [$b['city_count'], $b['project_count'], $a['province_name']] <=> [$a['city_count'], $a['project_count'], $b['province_name']],
            };
        });

        return response()->json([
            'data' => $provinceData,
            'meta' => [
                'total_provinces' => count($provinceData),
                'total_projects' => array_sum(array_column($provinceData, 'project_count')),
                'sort_by' => $sortBy,
            ],
        ]);
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

        $projects = $service->fetchProjectsWithListingsPaginated(12, $search, $sortBy);

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

    public function show(Request $request, string $slug): JsonResponse
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

        $baseListingsQuery = Listing::query()
            ->where('visibility', 'public')
            ->whereHas('property', fn ($q) =>
                $q->where('is_project', true)
                  ->where('project_id', $project->id)
            )
            ->with([
                'property.propertyAttribute.subtype',
                'property.nearbyFacility',
                'category',
                'agent' => fn ($q) => $q->withCount('listings'),
            ]);

        $filteredListingsQuery = (clone $baseListingsQuery)->filter($request);

        $listingsBreakdown = [
            'total' => (clone $filteredListingsQuery)->count(),
            'sale' => (clone $filteredListingsQuery)
                ->whereHas('category', fn ($query) => $query->where('name', 'For Sale'))
                ->count(),
            'rent' => (clone $filteredListingsQuery)
                ->whereHas('category', fn ($query) => $query->where('name', 'For Rent'))
                ->count(),
            'foreclosure' => (clone $filteredListingsQuery)
                ->whereHas('category', fn ($query) => $query->where('name', 'Foreclosure'))
                ->count(),
        ];

        $activeUnitType = (string) $request->query('active_unit_type', 'all');
        if ($activeUnitType === 'sale') {
            $filteredListingsQuery->whereHas('category', fn ($query) => $query->where('name', 'For Sale'));
        } elseif ($activeUnitType === 'rent') {
            $filteredListingsQuery->whereHas('category', fn ($query) => $query->where('name', 'For Rent'));
        } elseif ($activeUnitType === 'foreclosure') {
            $filteredListingsQuery->whereHas('category', fn ($query) => $query->where('name', 'Foreclosure'));
        }

        $listings = $filteredListingsQuery
            ->sorted($sortBy)
            ->paginate(12);

        $projectData = ProjectResource::make($project);
        $projectData['geo_coordinates'] = $geo;

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
        ]);
    }
}
