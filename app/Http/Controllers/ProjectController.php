<?php

namespace App\Http\Controllers;

use App\Services\Project\ProjectService;
use App\Models\Project;
use App\Models\Listing;
use App\Models\Property;
use App\Http\Resources\ListingResourceCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

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

    public function store(Request $request, ProjectService $service): JsonResponse
    {
        if (($request->user()->role->name ?? null) !== 'admin') {
            return response()->json(['message' => 'Only admins can create projects.'], 403);
        }

        $data = $this->normalizeLocationKeys($request->validate([
            'name' => 'required|string|max:255',
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
            'added_by' => $request->user()->id,
        ]);

        // Ensure mapaddress has a raw address fallback
        if (empty($payload['mapaddress'] ?? null)) {
            $payload['mapaddress'] = (string) ($payload['complete_address'] ?? '');
        }

        $project = Project::create($payload);

        Cache::forget('projects_db');

        return response()->json([
            'message' => 'Project created successfully',
            'data' => $project,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        if (($request->user()->role->name ?? null) !== 'admin') {
            return response()->json(['message' => 'Only admins can update projects.'], 403);
        }

        $project = Project::findOrFail($id);

        $updates = array_merge(
            $this->normalizeLocationKeys($request->validate([
                'name' => 'sometimes|string|max:255',
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

        Cache::forget('projects_db');

        return response()->json([
            'message' => 'Project updated successfully',
            'data' => $project,
        ]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        if (($request->user()->role->name ?? null) !== 'admin') {
            return response()->json(['message' => 'Only admins can delete projects.'], 403);
        }

        $project = Project::findOrFail($id);
        $project->delete();
        Cache::forget('projects_db');

        return response()->json(['message' => 'Project deleted successfully']);
    }

    public function trackView(Request $request, string $slug): JsonResponse
    {
        $project = Project::query()
            ->where('slug', $slug)
            ->orWhereRaw('LOWER(name) = ?', [strtolower(str_replace('-', ' ', $slug))])
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
            // increment once per day per device/user
            $project->increment('views');
            Cache::put($viewKey, $today, $now->copy()->endOfDay());
        }

        return response()->json(['success' => true]);
    }

    public function projects(Request $request, ProjectService $service): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $search = (string) $request->query('search', '');
        $projects = $service->fetchProjectsPaginated(12, $search);

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

    public function projectsWithListings(Request $request, ProjectService $service): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $search = (string) $request->query('search', '');

        $projects = $service->fetchProjectsWithListingsPaginated(12, $search);

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

    public function show(string $slug): JsonResponse
    {
        $project = Project::query()
            ->withCount([
                'properties as properties_count' => function ($query) {
                    $query->where('is_project', true);
                },
            ])
            ->where(function ($query) use ($slug) {
                $query->where('slug', $slug)
                    ->orWhereRaw('LOWER(name) = ?', [strtolower(str_replace('-', ' ', $slug))]);
            })
            ->first()
            ?? Project::query()
                ->withCount([
                    'properties as properties_count' => function ($query) {
                        $query->where('is_project', true);
                    },
                ])
                ->where('slug', Str::slug($slug))
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

        $listings = Listing::where('visibility', 'public')
            ->whereHas('property', fn ($q) =>
                $q->where('is_project', true)
                  ->where('project_id', $project->id)
            )
            ->with([
                'property.propertyAttribute.subtype',
                'property.nearbyFacility',
                'category',
                'agent' => fn ($q) => $q->withCount('listings'),
            ])
            ->latest()
            ->paginate(12);

        return response()->json([
            'message' => 'Project fetched successfully',
            'data' => array_merge($project->toArray(), [
                'geo_coordinates' => $geo,
                'properties_count' => (int) ($project->properties_count ?? 0),
            ]),
            'listings' => (new ListingResourceCollection($listings))->toArray(request()),
            'listings_meta' => [
                'current_page' => $listings->currentPage(),
                'last_page' => $listings->lastPage(),
                'total' => $listings->total(),
            ],
        ]);
    }
}
