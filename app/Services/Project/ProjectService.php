<?php

namespace App\Services\Project;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Project;
use App\Models\Property;
use Illuminate\Support\Str;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProjectService
{
    private const DEFAULT_SORT = 'properties';
    private const PROJECT_LIST_COLUMNS = [
        'projects.id',
        'projects.name',
        'projects.slug',
        'projects.prov_id',
        'projects.city_id',
        'projects.brgy_id',
        'projects.street',
        'projects.mapaddress',
        'projects.complete_address',
        'projects.featured_photo',
        'projects.photos_url',
        'projects.latitude',
        'projects.longitude',
        'projects.views',
    ];

    private function normalizedValue(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function normalizeSort(string $sortBy): string
    {
        $sortBy = strtolower(trim($sortBy));

        return match ($sortBy) {
            'views', 'a-z', 'az', 'properties' => $sortBy,
            default => self::DEFAULT_SORT,
        };
    }

    private function applyProjectSort($query, string $sortBy)
    {
        $sortBy = $this->normalizeSort($sortBy);

        return match ($sortBy) {
            'views' => $query
                ->orderByRaw('COALESCE(views, 0) DESC')
                ->orderByDesc('properties_count')
                ->orderBy('name'),
            'a-z', 'az' => $query
                ->orderBy('name')
                ->orderByDesc('properties_count'),
            default => $query
                ->orderByDesc('properties_count')
                ->orderByRaw('COALESCE(views, 0) DESC')
                ->orderBy('name'),
        };
    }

    private function projectCountsSubquery()
    {
        return Property::query()
            ->selectRaw('project_id, COUNT(*) as properties_count')
            ->where('is_project', true)
            ->whereNotNull('project_id')
            ->groupBy('project_id');
    }

    private function baseProjectListQuery(bool $withListingsOnly = false)
    {
        $counts = $this->projectCountsSubquery();

        $query = Project::query()
            ->select([
                ...self::PROJECT_LIST_COLUMNS,
                DB::raw('COALESCE(project_property_counts.properties_count, 0) as properties_count'),
            ]);

        if ($withListingsOnly) {
            return $query->joinSub($counts, 'project_property_counts', function ($join) {
                $join->on('project_property_counts.project_id', '=', 'projects.id');
            });
        }

        return $query->leftJoinSub($counts, 'project_property_counts', function ($join) {
            $join->on('project_property_counts.project_id', '=', 'projects.id');
        });
    }

    private function roundedCoordinateValue($value): ?float
    {
        return is_numeric($value) ? round((float) $value, 4) : null;
    }

    private function applyProjectSearch($query, string $search, string $searchField = 'all')
    {
        $search = trim($search);
        if ($search === '') {
            return $query;
        }

        $searchTerm = '%' . $search . '%';
        $booleanSearch = collect(preg_split('/[^[:alnum:]]+/u', Str::lower($search)) ?: [])
            ->map(fn ($term) => trim((string) $term))
            ->filter(fn ($term) => $term !== '' && Str::length($term) >= 3)
            ->map(fn ($term) => $term . '*')
            ->implode(' ');

        if ($searchField === 'name') {
            return $query->where('projects.name', 'like', $searchTerm);
        }

        return $query->where(function ($q) use ($searchTerm, $booleanSearch) {
            if ($booleanSearch !== '') {
                $q->where(function ($fullTextQuery) use ($booleanSearch, $searchTerm) {
                    $fullTextQuery->whereRaw(
                        'MATCH(projects.name, projects.complete_address) AGAINST (? IN BOOLEAN MODE)',
                        [$booleanSearch]
                    )
                    ->orWhere('projects.name', 'like', $searchTerm)
                    ->orWhere('projects.complete_address', 'like', $searchTerm);
                });
                return;
            }

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
            return $this->baseProjectListQuery()
                ->orderByDesc('properties_count')
                ->orderByRaw('COALESCE(views, 0) DESC')
                ->orderBy('name')
                ->get()
                ->all();
        });
    }

    public function fetchProjectsPaginated(
        int $perPage = 12,
        string $search = "",
        string $sortBy = self::DEFAULT_SORT,
        string $searchField = 'all'
    ): LengthAwarePaginator
    {
        $paginator = $this->baseProjectListQuery();

        $paginator = $this->applyProjectSort(
            $this->applyProjectSearch($paginator, $search, $searchField),
            $sortBy
        )->paginate($perPage);

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
        $projectName = trim((string) $project->name);
        if ($projectName === '') {
            return 0;
        }

        $sourceProperty = $sourcePropertyId ? Property::query()->find($sourcePropertyId) : null;
        $linkedCount = 0;
        $sourcePropertyRowId = null;

        if ($sourceProperty) {
            $sourcePropertyRowId = (int) $sourceProperty->id;
            $linkedCount += Property::query()
                ->where('id', $sourceProperty->id)
                ->where('is_project', true)
                ->update([
                    'project_id' => $project->id,
                    'name' => $projectName,
                ]);

            $geo = $this->decodeGeoCoordinates($sourceProperty->geo_coordinates);
            $lat = $this->roundedCoordinateValue($geo['lat'] ?? null);
            $lng = $this->roundedCoordinateValue($geo['lng'] ?? null);
        } else {
            $lat = $this->roundedCoordinateValue($project->latitude);
            $lng = $this->roundedCoordinateValue($project->longitude);
        }

        $cityId = $project->city_id ? (int) $project->city_id : null;
        $provinceId = $project->prov_id ? (int) $project->prov_id : null;
        $canMatchCityProvince = $cityId !== null && $provinceId !== null;
        $canMatchCoordinates = $lat !== null && $lng !== null;

        if (!$canMatchCityProvince && !$canMatchCoordinates) {
            $this->syncProjectPropertyNames($project);
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

        $linkedCount += $query->update([
            'project_id' => $project->id,
            'name' => $projectName,
        ]);

        $this->syncProjectPropertyNames($project);

        return $linkedCount;
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
        $linkedCount = Property::query()
            ->where('is_project', true)
            ->where('project_id', $deletedProject->id)
            ->update([
                'project_id' => $destinationProject->id,
                'name' => $destinationProject->name,
            ]);

        $this->syncProjectPropertyNames($destinationProject);

        return $linkedCount;
    }

    private function syncProjectPropertyNames(Project $project): int
    {
        $projectName = trim((string) $project->name);

        if ($projectName === '') {
            return 0;
        }

        return Property::query()
            ->where('is_project', true)
            ->where('project_id', $project->id)
            ->where(function ($query) use ($projectName) {
                $query->whereNull('name')
                    ->orWhere('name', '!=', $projectName);
            })
            ->update(['name' => $projectName]);
    }

    public function fetchProjectsWithListingsPaginated(
        int $perPage = 12,
        string $search = "",
        string $sortBy = self::DEFAULT_SORT
    )
    {
        $query = $this->baseProjectListQuery(true);
  
        $paginator = $this->applyProjectSort(
            $this->applyProjectSearch($query, $search),
            $sortBy
        )->paginate($perPage);

        return $paginator;
    }
}
