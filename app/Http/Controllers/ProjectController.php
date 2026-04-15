<?php

namespace App\Http\Controllers;

use App\Services\Project\ProjectService;
use App\Models\Project;
use App\Models\Listing;
use App\Models\Property;
use App\Http\Resources\ListingResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProjectController extends Controller
{
    public function index(ProjectService $projectService)
    {
        $projects = $projectService->fetchProjects();

        return response()->json([
            'message' => 'Projects fetched successfully',
            'data' => $projects
        ]);
    }

    public function store(Request $request, ProjectService $projectService): JsonResponse
    {
        $user = $request->user();
        if (($user->role->name ?? null) !== 'admin') {
            return response()->json(['message' => 'Only admins can create projects.'], 403);
        }

        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'prov_id'          => 'nullable|integer',
            'prop_type_id'     => 'nullable|integer',
            'city_id'          => 'nullable|integer',
            'brgy_id'          => 'nullable|integer',
            'street'           => 'nullable|string|max:255',
            'mapaddress'       => 'nullable|string|max:500',
            'latitude'         => 'nullable|numeric',
            'longitude'        => 'nullable|numeric',
            'complete_address' => 'nullable|string|max:1000',
            'date_updated'       => 'nullable|date',
            'featured_photo'   => 'nullable|string|max:1000',
            'photos_url'       => 'nullable|array',
            'photos_url.*'     => 'string|max:1000',
        ]);

        $payload = array_merge($data, [
            'date_updated' => $data['date_updated'] ?? now()->toDateString(),
            'added_by'     => $user->id,
            // Some legacy schemas require a non-null 'devid'; provide a fallback
            'devid'        => $request->input('devid')
                                ?? $request->input('device_id')
                                ?? $request->header('X-Device-Id')
                                ?? (string) Str::uuid(),
        ]);

        $project = Project::create($payload);
        Cache::forget('projects_db');

        return response()->json([
            'message' => 'Project created successfully',
            'data'    => $project->toArray(),
        ], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        if (($user->role->name ?? null) !== 'admin') {
            return response()->json(['message' => 'Only admins can update projects.'], 403);
        }

        $data = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'prov_id'          => 'sometimes|integer|nullable',
            'prop_type_id'     => 'sometimes|integer|nullable',
            'city_id'          => 'sometimes|integer|nullable',
            'brgy_id'          => 'sometimes|integer|nullable',
            'street'           => 'sometimes|string|max:255|nullable',
            'mapaddress'       => 'sometimes|string|max:500|nullable',
            'latitude'         => 'sometimes|numeric|nullable',
            'longitude'        => 'sometimes|numeric|nullable',
            'complete_address' => 'sometimes|string|max:1000|nullable',
            'date_added'       => 'sometimes|date|nullable',
            'featured_photo'   => 'sometimes|string|max:1000|nullable',
            'photos_url'       => 'sometimes|array|nullable',
            'photos_url.*'     => 'string|max:1000',
        ]);

        $project->update(array_merge($data, [
            'date_updated' => now()->toDateString(),
        ]));
        Cache::forget('projects_db');

        return response()->json([
            'message' => 'Project updated successfully',
            'data'    => $project->toArray(),
        ]);
    }
    public function projects(ProjectService $projectService)
    {
        $projects = $projectService->fetchProjectsPaginated(12);

        return response()->json([
            'message' => 'Projects fetched successfully',
            'data'    => $projects->items(),
            'meta'    => [
                'current_page' => $projects->currentPage(),
                'last_page'    => $projects->lastPage(),
                'per_page'     => $projects->perPage(),
                'total'        => $projects->total(),
            ],
            'links'   => [
                'first' => $projects->url(1),
                'prev'  => $projects->previousPageUrl(),
                'next'  => $projects->nextPageUrl(),
                'last'  => $projects->url($projects->lastPage()),
            ],
        ]);
    }
    public function show(string $slug): JsonResponse
    {
        $project = null;

        $nameGuess = trim(str_replace('-', ' ', $slug));
        $project = Project::whereRaw('LOWER(name) = ?', [strtolower($nameGuess)])->first();

        if (!$project) {
            $project = Project::all()->first(function ($p) use ($slug) {
                return Str::slug((string) $p->name) === Str::slug((string) $slug);
            });
        }

        if (!$project) {
            return response()->json([
                'message' => 'Project not found',
            ], 404);
        }

        $lat = $project->latitude ?? $project->lat ?? null;
        $lng = $project->longitude ?? $project->lng ?? null;
        $geo = null;
        if ($lat !== null && $lng !== null) {
            $geo = [
                'lat' => is_numeric($lat) ? (float) $lat : null,
                'lng' => is_numeric($lng) ? (float) $lng : null,
            ];
        }

        $propertiesCount = Property::query()
            ->where('is_project', true)
            ->whereRaw('LOWER(name) = ?', [strtolower((string) $project->name)])
            ->count();

        $listings = Listing::query()
            ->where('visibility', 'public')
            ->whereHas('property', function ($q) use ($project) {
                $q->where('is_project', true)
                  ->whereRaw('LOWER(name) = ?', [strtolower((string) $project->name)]);
            })
            ->with([
                'property.propertyAttribute.subtype',
                'property.nearbyFacility',
                'category',
                'agent' => function ($q) {
                    $q->withCount('listings');
                }
            ])
            ->orderByDesc('updated_at')
            ->paginate(12);

        $payload = array_merge($project->toArray(), [
            'geo_coordinates'  => $geo,
            'properties_count' => $propertiesCount,
        ]);

        return response()->json([
            'message'        => 'Project fetched successfully',
            'data'           => $payload,
            'listings'       => (new ListingResourceCollection($listings))->toArray(request()),
            'listings_meta'  => [
                'current_page' => $listings->currentPage(),
                'last_page'    => $listings->lastPage(),
                'per_page'     => $listings->perPage(),
                'total'        => $listings->total(),
            ],
            'listings_links' => [
                'first' => $listings->url(1),
                'prev'  => $listings->previousPageUrl(),
                'next'  => $listings->nextPageUrl(),
                'last'  => $listings->url($listings->lastPage()),
            ],
        ]);
    }
}
