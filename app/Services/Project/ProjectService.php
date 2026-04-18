<?php

namespace App\Services\Project;

use Illuminate\Support\Facades\Cache;
use App\Models\Project;
use App\Models\Property;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProjectService
{
    private function normalizedValue(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function roundedCoordinateValue($value): ?float
    {
        return is_numeric($value) ? round((float) $value, 4) : null;
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

    private function decodeGeoCoordinates($value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (!is_array($value)) {
            return null;
        }

        $lat = $value['lat'] ?? null;
        $lng = $value['lng'] ?? null;

        if ($lat === null || $lng === null) {
            return null;
        }

        return [
            'lat' => is_numeric($lat) ? (float) $lat : null,
            'lng' => is_numeric($lng) ? (float) $lng : null,
        ];
    }

    private function applyPropertyCityProvinceMatch($query, ?int $cityId, ?int $provinceId): void
    {
        $query->whereExists(function ($subQuery) use ($cityId, $provinceId) {
            $subQuery->selectRaw('1')
                ->from('barangays')
                ->join('cities', 'cities.id', '=', 'barangays.city_id')
                ->whereColumn('barangays.id', 'properties.address_id');

            if ($cityId !== null) {
                $subQuery->where('cities.id', $cityId);
            }

            if ($provinceId !== null) {
                $subQuery->where('cities.province_id', $provinceId);
            }
        });
    }

    public function fetchProjects(): array
    {
        return Cache::remember('projects_db', 600, function () {
            return Project::query()
                ->withCount('properties as properties_count')
                ->get()
                ->all();
        });
    }

    public function fetchProjectsPaginated(int $perPage = 12, string $search = ""): LengthAwarePaginator
    {
        $paginator = Project::query()->withCount('properties as properties_count');

        $paginator = $this->applyProjectSearch($paginator, $search)
            ->orderByDesc('properties_count')
            ->paginate($perPage);

        return $paginator;
    }

    public function fetchUnassociatedProjectPropertiesPaginated(int $perPage = 10, int $page = 1, string $search = "")
    {
        $paginator = Property::query()
            ->where('properties.is_project', true)
            ->whereNull('properties.project_id')
            ->whereRaw("TRIM(properties.name) <> ''")
            ->when(trim($search) !== '', function ($query) use ($search) {
                $searchTerm = '%' . trim($search) . '%';

                $query->where(function ($q) use ($searchTerm) {
                    $q->where('properties.name', 'like', $searchTerm)
                        ->orWhere('properties.address', 'like', $searchTerm);
                });
            })
            ->leftJoin('barangays', 'barangays.id', '=', 'properties.address_id')
            ->leftJoin('cities', 'cities.id', '=', 'barangays.city_id')
            ->leftJoin('provinces', 'provinces.id', '=', 'cities.province_id')
            ->select([
                'properties.id',
                'properties.name',
                'properties.address as complete_address',
                'properties.address_id as brgy_id',
                'properties.geo_coordinates',
                'cities.id as city_id',
                'provinces.id as prov_id',
            ])
            ->orderBy('properties.name')
            ->orderBy('properties.id')
            ->paginate($perPage, ['*'], 'page', $page);

        $collection = $paginator->getCollection()->transform(function ($row) {
            $address = trim((string) ($row->complete_address ?? ''));
            $segments = array_filter(array_map('trim', explode(',', $address)));
            $geoCoordinates = $this->decodeGeoCoordinates($row->geo_coordinates);

            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'brgy_id' => $row->brgy_id ? (int) $row->brgy_id : null,
                'city_id' => $row->city_id ? (int) $row->city_id : null,
                'prov_id' => $row->prov_id ? (int) $row->prov_id : null,
                'street' => $segments[0] ?? null,
                'latitude' => $geoCoordinates['lat'] ?? null,
                'longitude' => $geoCoordinates['lng'] ?? null,
                'geo_coordinates' => $geoCoordinates,
                'complete_address' => $row->complete_address,
            ];
        });

        $paginator->setCollection($collection);

        return $paginator;
    }

    public function syncProjectProperties(Project $project, ?int $sourcePropertyId = null): int
    {
        $sourceProperty = $sourcePropertyId ? Property::query()->find($sourcePropertyId) : null;
        $linkedCount = 0;
        $sourcePropertyRowId = null;

        if ($sourceProperty) {
            $sourcePropertyRowId = (int) $sourceProperty->id;
            $linkedCount += Property::query()
                ->where('id', $sourceProperty->id)
                ->where('is_project', true)
                ->update(['project_id' => $project->id]);

            $projectName = trim((string) $sourceProperty->name);
            $geo = $this->decodeGeoCoordinates($sourceProperty->geo_coordinates);
            $lat = $this->roundedCoordinateValue($geo['lat'] ?? null);
            $lng = $this->roundedCoordinateValue($geo['lng'] ?? null);
        } else {
            $projectName = trim((string) $project->name);
            $lat = $this->roundedCoordinateValue($project->latitude);
            $lng = $this->roundedCoordinateValue($project->longitude);
        }

        if ($projectName === '') {
            return 0;
        }

        $cityId = $project->city_id ? (int) $project->city_id : null;
        $provinceId = $project->prov_id ? (int) $project->prov_id : null;
        $canMatchCityProvince = $cityId !== null && $provinceId !== null;
        $canMatchCoordinates = $lat !== null && $lng !== null;

        if (!$canMatchCityProvince && !$canMatchCoordinates) {
            return $linkedCount;
        }

        $query = Property::query()
            ->where('is_project', true)
            ->whereNull('project_id')
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($projectName)]);

        if ($sourcePropertyRowId !== null) {
            $query->where('id', '!=', $sourcePropertyRowId);
        }

        $query->where(function ($matchQuery) use ($canMatchCityProvince, $canMatchCoordinates, $cityId, $provinceId, $lat, $lng) {
            if ($canMatchCityProvince) {
                $matchQuery->orWhere(function ($cityProvinceQuery) use ($cityId, $provinceId) {
                    $this->applyPropertyCityProvinceMatch($cityProvinceQuery, $cityId, $provinceId);
                });
            }

            if ($canMatchCoordinates) {
                $matchQuery->orWhere(function ($coordinatesQuery) use ($lat, $lng) {
                    $coordinatesQuery
                        ->whereRaw("ROUND(CAST(JSON_UNQUOTE(JSON_EXTRACT(geo_coordinates, '$.lat')) AS DECIMAL(12,8)), 4) = ?", [$lat])
                        ->whereRaw("ROUND(CAST(JSON_UNQUOTE(JSON_EXTRACT(geo_coordinates, '$.lng')) AS DECIMAL(12,8)), 4) = ?", [$lng]);
                });
            }
        });

        return $linkedCount + $query->update(['project_id' => $project->id]);
    }

    public function fetchDeletedProjectsPaginated(int $perPage = 10, int $page = 1, string $search = ""): LengthAwarePaginator
    {
        return $this->applyProjectSearch(
            Project::onlyTrashed()->withCount([
                'properties as properties_count' => function ($query) {
                    $query->where('is_project', true);
                },
            ]),
            $search
        )
            ->orderByDesc('deleted_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function relinkDeletedProjectProperties(Project $deletedProject, Project $destinationProject): int
    {
        return Property::query()
            ->where('is_project', true)
            ->where('project_id', $deletedProject->id)
            ->update(['project_id' => $destinationProject->id]);
    }

    public function fetchProjectsWithListingsPaginated(int $perPage = 12, string $search = "")
    {
        $query = Project::query()
            ->withCount('properties as properties_count')
            ->whereHas('properties');

        $paginator = $this->applyProjectSearch($query, $search)
            ->orderByDesc('properties_count')
            ->paginate($perPage);

        return $paginator;
    }
}
