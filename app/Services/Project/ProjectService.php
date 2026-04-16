<?php

namespace App\Services\Project;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Project;
use App\Models\Property;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProjectService
{
    private function normalizedNameExpression(string $column = 'name'): string
    {
        return "LOWER(TRIM({$column}))";
    }

    private function roundedGeoCoordinateExpression(string $column, string $key): string
    {
        return "ROUND(CAST(JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.{$key}')) AS DECIMAL(12,8)), 4)";
    }

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

    private function roundedCoordinateValue($value): ?float
    {
        return is_numeric($value) ? round((float) $value, 4) : null;
    }

    private function prioritizeProjectsWithStreet($query)
    {
        return $query
            ->orderByRaw("CASE WHEN TRIM(COALESCE(street, '')) <> '' THEN 0 ELSE 1 END")
            ->orderBy('id');
    }

    private function findMatchingProjectForProperty(Property $property): ?Project
    {
        $normalizedName = strtolower(trim((string) $property->name));
        if ($normalizedName === '') {
            return null;
        }

        $normalizedAddress = strtolower(trim((string) $property->address));
        $barangayId = $property->address_id ? (int) $property->address_id : null;
        $geo = $this->decodeGeoCoordinates($property->geo_coordinates);
        $lat = $this->roundedCoordinateValue($geo['lat'] ?? null);
        $lng = $this->roundedCoordinateValue($geo['lng'] ?? null);

        $queries = [];

        if ($lat !== null && $lng !== null) {
            $queries[] = function () use ($normalizedName, $lat, $lng, $barangayId) {
                $query = Project::query()
                    ->whereRaw($this->normalizedNameExpression('name') . ' = ?', [$normalizedName])
                    ->whereRaw('ROUND(CAST(latitude AS DECIMAL(12,8)), 4) = ?', [$lat])
                    ->whereRaw('ROUND(CAST(longitude AS DECIMAL(12,8)), 4) = ?', [$lng]);

                if ($barangayId !== null) {
                    $query->where('brgy_id', $barangayId);
                }

                return $this->prioritizeProjectsWithStreet($query)->first();
            };
        }

        if ($barangayId !== null && $normalizedAddress !== '') {
            $queries[] = function () use ($normalizedName, $normalizedAddress, $barangayId) {
                return $this->prioritizeProjectsWithStreet(Project::query()
                    ->whereRaw($this->normalizedNameExpression('name') . ' = ?', [$normalizedName])
                    ->where('brgy_id', $barangayId)
                    ->whereRaw($this->normalizedNameExpression('complete_address') . ' = ?', [$normalizedAddress])
                )->first();
            };
        }

        if ($normalizedAddress !== '') {
            $queries[] = function () use ($normalizedName, $normalizedAddress) {
                return $this->prioritizeProjectsWithStreet(Project::query()
                    ->whereRaw($this->normalizedNameExpression('name') . ' = ?', [$normalizedName])
                    ->whereRaw($this->normalizedNameExpression('complete_address') . ' = ?', [$normalizedAddress])
                )->first();
            };
        }

        if ($barangayId !== null) {
            $queries[] = function () use ($normalizedName, $barangayId) {
                return $this->prioritizeProjectsWithStreet(Project::query()
                    ->whereRaw($this->normalizedNameExpression('name') . ' = ?', [$normalizedName])
                    ->where('brgy_id', $barangayId)
                )->first();
            };
        }

        foreach ($queries as $resolve) {
            $project = $resolve();
            if ($project) {
                return $project;
            }
        }

        return null;
    }

    private function buildProjectPayloadFromProperty(Property $property): array
    {
        $property->loadMissing('barangay.city.province');

        $barangay = $property->barangay;
        $city = $barangay?->city;
        $province = $city?->province;
        $geo = $this->decodeGeoCoordinates($property->geo_coordinates);

        return [
            'name' => trim((string) $property->name),
            'prov_id' => $province?->id,
            'city_id' => $city?->id,
            'brgy_id' => $barangay?->id,
            'street' => $this->extractStreetFromAddress($property),
            'mapaddress' => $property->address,
            'complete_address' => $property->address,
            'latitude' => isset($geo['lat']) ? (string) $geo['lat'] : '',
            'longitude' => isset($geo['lng']) ? (string) $geo['lng'] : '',
            'date_updated' => now(),
            'featured_photo' => null,
            'photos_url' => null,
        ];
    }

    private function unassociatedProjectGroupsQuery(string $search = '')
    {
        $normalizedName = $this->normalizedNameExpression('properties.name');
        $normalizedAddress = $this->normalizedNameExpression("COALESCE(properties.address, '')");
        $lat = $this->roundedGeoCoordinateExpression('properties.geo_coordinates', 'lat');
        $lng = $this->roundedGeoCoordinateExpression('properties.geo_coordinates', 'lng');

        return Property::query()
            ->selectRaw('MIN(properties.id) as sample_property_id')
            ->selectRaw("{$normalizedName} as normalized_name")
            ->selectRaw('properties.address_id as brgy_id')
            ->selectRaw("{$normalizedAddress} as normalized_address")
            ->selectRaw("{$lat} as geo_lat")
            ->selectRaw("{$lng} as geo_lng")
            ->selectRaw('COUNT(*) as properties_count')
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
            ->groupByRaw("{$normalizedName}, properties.address_id, {$normalizedAddress}, {$lat}, {$lng}");
    }

    private function unassociatedProjectNameGroupsQuery(string $search = '')
    {
        $normalizedName = $this->normalizedNameExpression('properties.name');  

        return Property::query()
            ->selectRaw('MIN(properties.id) as sample_property_id')
            ->selectRaw("{$normalizedName} as normalized_name")
            ->selectRaw('COUNT(*) as properties_count')
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
            ->groupByRaw($normalizedName);
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
        $groups = $this->unassociatedProjectNameGroupsQuery($search);

        $paginator = DB::query()
            ->fromSub($groups, 'unassociated_groups')
            ->join('properties', 'properties.id', '=', 'unassociated_groups.sample_property_id')
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
                'unassociated_groups.properties_count',
            ])
            ->orderBy('properties.name')
            ->paginate($perPage, ['*'], 'page', $page);

        $collection = $paginator->getCollection()->transform(function ($row) {
            $address = trim((string) ($row->complete_address ?? ''));
            $segments = array_filter(array_map('trim', explode(',', $address)));

            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'brgy_id' => $row->brgy_id ? (int) $row->brgy_id : null,
                'city_id' => $row->city_id ? (int) $row->city_id : null,
                'prov_id' => $row->prov_id ? (int) $row->prov_id : null,
                'street' => $segments[0] ?? null,
                'geo_coordinates' => $this->decodeGeoCoordinates($row->geo_coordinates),
                'complete_address' => $row->complete_address,
                'properties_count' => (int) ($row->properties_count ?? 0),
            ];
        });

        $paginator->setCollection($collection);

        return $paginator;
    }

    public function backfillUnassociatedProjects(?int $limit = null): array
    {
        $groupsQuery = $this->unassociatedProjectGroupsQuery()
            ->orderBy('sample_property_id');

        if ($limit !== null && $limit > 0) {
            $groupsQuery->limit($limit);
        }

        $groups = $groupsQuery->get();

        $createdProjects = 0;
        $matchedProjects = 0;
        $linkedProperties = 0;

        foreach ($groups as $group) {
            $sampleProperty = Property::query()
                ->with('barangay.city.province')
                ->find($group->sample_property_id);

            if (!$sampleProperty) {
                continue;
            }

            $project = $this->findMatchingProjectForProperty($sampleProperty);

            if (!$project) {
                $project = Project::create($this->buildProjectPayloadFromProperty($sampleProperty));
                $createdProjects++;
            } else {
                $matchedProjects++;
            }

            $linkedProperties += Property::query()
                ->where('is_project', true)
                ->whereNull('project_id')
                ->whereRaw($this->normalizedNameExpression('name') . ' = ?', [$group->normalized_name])
                ->where(function ($query) use ($group) {
                    if ($group->brgy_id === null) {
                        $query->whereNull('address_id');
                    } else {
                        $query->where('address_id', $group->brgy_id);
                    }
                })
                ->whereRaw($this->normalizedNameExpression("COALESCE(address, '')") . ' = ?', [$group->normalized_address])
                ->whereRaw("{$this->roundedGeoCoordinateExpression('geo_coordinates', 'lat')} <=> ?", [$group->geo_lat])
                ->whereRaw("{$this->roundedGeoCoordinateExpression('geo_coordinates', 'lng')} <=> ?", [$group->geo_lng])
                ->update(['project_id' => $project->id]);
        }

        Cache::forget('projects_db');

        return [
            'groups_processed' => $groups->count(),
            'projects_created' => $createdProjects,
            'projects_matched' => $matchedProjects,
            'properties_linked' => $linkedProperties,
        ];
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
