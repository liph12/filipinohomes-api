<?php

namespace App\Services\Project;

use Illuminate\Support\Facades\Cache;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ProjectService
{
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
            ->select('projects.*')
            ->selectSub(function ($q) {
                $q->from('properties')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('properties.name', 'projects.name')
                    ->where('is_project', true);
            }, 'properties_count')
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
}
