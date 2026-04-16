<?php

namespace App\Services\Project;

use Illuminate\Support\Facades\Cache;
use App\Models\Project;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

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

    public function fetchProjects(): array
    {
        return Cache::remember('projects_db', 600, function () {
            $rows = Project::query()
                ->select('projects.*')
                ->selectSub(function ($q) {
                    $q->from('properties')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('properties.name', 'projects.name')
                        ->where('is_project', true);
                }, 'properties_count')
                ->get();

            return $rows->map(function (Project $p) {
                $lat = $p->latitude ?? $p->lat ?? null;
                $lng = $p->longitude ?? $p->lng ?? null;

                $geo = null;
                if ($lat !== null && $lng !== null) {
                    $geo = [
                        'lat' => is_numeric($lat) ? (float) $lat : null,
                        'lng' => is_numeric($lng) ? (float) $lng : null,
                    ];
                }

                return array_merge($p->toArray(), [
                    'geo_coordinates' => $geo,
                ]);
            })->toArray();
        });
    }

public function fetchProjectsPaginated(int $perPage = 12)
{
    $paginator = Project::query()
        ->leftJoin('properties', function ($join) {
            $join->on('properties.name', '=', 'projects.name')
                 ->where('properties.is_project', true);
        })
        ->select('projects.*', DB::raw('COUNT(properties.id) as properties_count'))
        ->groupBy('projects.id')
        ->orderByDesc('properties_count')
        ->paginate($perPage);

    $collection = $paginator->getCollection()->transform(function (Project $p) {
        $lat = $p->latitude ?? $p->lat ?? null;
        $lng = $p->longitude ?? $p->lng ?? null;

        $geo = null;
        if ($lat !== null && $lng !== null) {
            $geo = [
                'lat' => is_numeric($lat) ? (float) $lat : null,
                'lng' => is_numeric($lng) ? (float) $lng : null,
            ];
        }

        return array_merge($p->toArray(), [
            'geo_coordinates' => $geo,
        ]);
    });

    $paginator->setCollection($collection);

    return $paginator;
}

    public function fetchUnassociatedProjectPropertiesPaginated(int $perPage = 10, int $page = 1)
    {
        $paginator = Property::query()
            ->with(['barangay.city.province'])
            ->where('is_project', true)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw('1'))
                    ->from('projects')
                    ->whereRaw('LOWER(projects.name) = LOWER(properties.name)');
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
                'properties_count' => 1,
            ]);
        });

        $paginator->setCollection($collection);

        return $paginator;
    }
}
