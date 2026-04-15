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

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'prov_id' => 'nullable|integer',
            'city_id' => 'nullable|integer',
            'brgy_id' => 'nullable|integer',
            'street' => 'nullable|string|max:255',
            'mapaddress' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'complete_address' => 'nullable|string|max:1000',
            'featured_photo' => 'nullable|array',
            'featured_photo.*' => 'string|max:1000',
            'photos_url' => 'nullable|array',
            'photos_url.*' => 'string|max:1000',
        ]);

        $payload = array_merge($data, [
            'date_updated' => now(),
            'added_by' => $request->user()->id,
            // Some legacy schemas require a non-null 'devid'; provide a fallback
            'devid' => $request->input('devid')
                        ?? $request->input('device_id')
                        ?? $request->header('X-Device-Id')
                        ?? (string) Str::uuid(),
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

    public function update(Request $request, Project $project): JsonResponse
    {
        if (($request->user()->role->name ?? null) !== 'admin') {
            return response()->json(['message' => 'Only admins can update projects.'], 403);
        }

        $updates = array_merge(
            $request->validate([
                'name' => 'sometimes|string|max:255',
                'prov_id' => 'sometimes|integer|nullable',
                'city_id' => 'sometimes|integer|nullable',
                'brgy_id' => 'sometimes|integer|nullable',
                'street' => 'sometimes|string|max:255|nullable',
                'mapaddress' => 'sometimes|string|max:500|nullable',
                'latitude' => 'sometimes|numeric|nullable',
                'longitude' => 'sometimes|numeric|nullable',
                'complete_address' => 'sometimes|string|max:1000|nullable',
                'featured_photo' => 'sometimes|array|nullable',
                'featured_photo.*' => 'string|max:1000',
                'photos_url' => 'sometimes|array|nullable',
                'photos_url.*' => 'string|max:1000',
            ]),
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

    public function destroy(Request $request, Project $project): JsonResponse
    {
        if (($request->user()->role->name ?? null) !== 'admin') {
            return response()->json(['message' => 'Only admins can delete projects.'], 403);
        }

        $project->delete();
        Cache::forget('projects_db');

        return response()->json(['message' => 'Project deleted successfully']);
    }

    public function projects(ProjectService $service): JsonResponse
    {
        $projects = $service->fetchProjectsPaginated(12);

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

    public function show(string $slug): JsonResponse
    {
        $project = Project::query()
            ->whereRaw('LOWER(name) = ?', [strtolower(str_replace('-', ' ', $slug))])
            ->first()
            ?? Project::all()->first(fn ($p) =>
                Str::slug($p->name) === Str::slug($slug)
            );

        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $geo = ($project->latitude && $project->longitude)
            ? [
                'lat' => (float) $project->latitude,
                'lng' => (float) $project->longitude,
            ]
            : null;

        $propertiesCount = Property::where('is_project', true)
            ->whereRaw('LOWER(name) = ?', [strtolower($project->name)])
            ->count();

        $listings = Listing::where('visibility', 'public')
            ->whereHas('property', fn ($q) =>
                $q->where('is_project', true)
                  ->whereRaw('LOWER(name) = ?', [strtolower($project->name)])
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
                'properties_count' => $propertiesCount,
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