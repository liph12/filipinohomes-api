<?php

namespace App\Services\Project;

use Illuminate\Support\Facades\Cache;
use App\Models\Project;
use App\Models\Property;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProjectService
{
    private function extractStreetFromAddress(Property $property): ?string
    {
        $address = trim((string) $property->address);
        if ($address === '') {
            return null;
        }

        $segments = array_filter(array_map('trim', explode(',', $address)));
        return $segments[0] ?? null;
    }

    private function transformProject(Project $project): array
    {
        $lat = $project->latitude ?? $project->lat ?? null;
        $lng = $project->longitude ?? $project->lng ?? null;

        $geo = null;
        if ($lat !== null && $lng !== null) {
            $geo = [
                'lat' => is_numeric($lat) ? (float) $lat : null,
                'lng' => is_numeric($lng) ? (float) $lng : null,
            ];
        }

        return array_merge($project->toArray(), [
            'geo_coordinates' => $geo,
        ]);
    }

    private function applyProjectSearch($query, string $search)
    {
        $search = trim($search);
        if ($search === '') {
            return $query;
        }

        $searchTerm = '%' . $search . '%';

        return $query->where(function ($q) use ($searchTerm) {
            $q->where('projects.name', 'like', $searchTerm)
                ->orWhere('projects.complete_address', 'like', $searchTerm);
        });
    }

    public function fetchProjects(): array
    {
        return Cache::remember('projects_db', 600, function () {
            $rows = Project::query()
                ->withCount([
                    'properties as properties_count' => function ($query) {
                        $query->where('is_project', true);
                    },
                ])
                ->get();

            return $rows->map(fn (Project $project) => $this->transformProject($project))->toArray();
        });
    }

    public function fetchProjectsPaginated(int $perPage = 12, string $search = ""): LengthAwarePaginator
    {
        $paginator = Project::query()
            ->withCount([
                'properties as properties_count' => function ($query) {
                    $query->where('is_project', true);
                },
            ]);

        $paginator = $this->applyProjectSearch($paginator, $search)
            ->orderByDesc('properties_count')
            ->paginate($perPage);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Project $project) => $this->transformProject($project))
        );

        return $paginator;
    }

    public function fetchUnassociatedProjectPropertiesPaginated(int $perPage = 10, int $page = 1, string $search = "")
    {
        $paginator = Property::query()
            ->select('properties.*')
            ->with(['barangay.city.province'])
            ->withCount(['publicListing as listings_count'])
            ->where('is_project', true)
            ->whereNull('project_id')
            ->when(trim($search) !== '', function ($query) use ($search) {
                $searchTerm = '%' . trim($search) . '%';
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'like', $searchTerm)
                      ->orWhere('address', 'like', $searchTerm);
                });
            })
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'page', $page);

        $collection = $paginator->getCollection()->transform(function (Property $property) {
            $barangay = $property->barangay;
            $city = $barangay?->city;
            $province = $city?->province;

            $geo = null;
            if (is_array($property->geo_coordinates)) {
                $geo = [
                    'lat' => $property->geo_coordinates['lat'] ?? null,
                    'lng' => $property->geo_coordinates['lng'] ?? null,
                ];
            } elseif ($property->latitude !== null && $property->longitude !== null) {
                $geo = [
                    'lat' => is_numeric($property->latitude) ? (float) $property->latitude : null,
                    'lng' => is_numeric($property->longitude) ? (float) $property->longitude : null,
                ];
            }

            return array_merge($property->toArray(), [
                'brgy_id' => $property->address_id,
                'city_id' => $city?->id,
                'prov_id' => $province?->id,
                'street' => $this->extractStreetFromAddress($property),
                'geo_coordinates' => $geo,
                'complete_address' => $property->address,
                'properties_count' => $property->listings_count ?? 0,
            ]);
        });

        $paginator->setCollection($collection);

        return $paginator;
    }

    public function fetchProjectsWithListingsPaginated(int $perPage = 12, string $search = "")
    {
        $query = Project::query()
            ->withCount([
                'properties as properties_count' => function ($q) {
                    $q->where('is_project', true);
                },
            ])
            ->whereHas('properties', function ($q) {
                $q->where('is_project', true);
            });

        $paginator = $this->applyProjectSearch($query, $search)
            ->orderByDesc('properties_count')
            ->paginate($perPage);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Project $project) => $this->transformProject($project))
        );

        return $paginator;
    }
}
