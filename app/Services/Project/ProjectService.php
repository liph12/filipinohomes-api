<?php

namespace App\Services\Project;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Project;
use App\Models\Property;
use App\Models\Listing;
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

    /**
     * Selling-price window for the directory: a project stays in the list when
     * at least one of its public For Sale units is priced within [min, max].
     * Sale only — the picker's presets are sale-tier amounts, and mixing in
     * monthly rents would match every rental project on "Under ₱1M".
     * EXISTS on the indexed properties(is_project, project_id) → listings
     * (property_id) path, so it costs one correlated index probe per project.
     */
    private function applyProjectPriceRange($query, ?float $priceMin, ?float $priceMax)
    {
        if ($priceMin === null && $priceMax === null) {
            return $query;
        }

        $units = Listing::query()
            ->publiclyListed()
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->where('properties.is_project', true)
            ->whereColumn('properties.project_id', 'projects.id')
            ->where('categories.name', 'For Sale')
            // Same floor as unit_stats.price_range: ₱1 / ₱0 placeholders are not prices.
            ->where('listings.price', '>=', 1000)
            ->when($priceMin !== null, fn ($q) => $q->where('listings.price', '>=', $priceMin))
            ->when($priceMax !== null, fn ($q) => $q->where('listings.price', '<=', $priceMax))
            ->selectRaw('1')
            // toBase(), not getQuery(): applies the SoftDeletes global scope so
            // trashed listings can't satisfy the EXISTS.
            ->toBase();

        return $query->whereExists($units);
    }

    private function projectCountsSubquery()
    {
        return Property::query()
            ->selectRaw('project_id, COUNT(*) as properties_count')
            ->where('is_project', true)
            ->whereNotNull('project_id')
            ->groupBy('project_id');
    }

    // Public listing counts per project, split by category — powers the
    // sale/rent/foreclosure breakdown the directory + sitemap need to know
    // which /projects/{slug}/{category} pages actually have units.
    private function projectCategoryCountsSubquery()
    {
        return Listing::query()
            ->publiclyListed()
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->where('properties.is_project', true)
            ->whereNotNull('properties.project_id')
            ->selectRaw(
                "properties.project_id as project_id,
                 COUNT(*) as public_listings_count,
                 SUM(categories.name = 'For Sale') as sale_count,
                 SUM(categories.name = 'For Rent') as rent_count,
                 SUM(categories.name = 'Foreclosure') as foreclosure_count"
            )
            ->groupBy('properties.project_id');
    }

    private function unitStatsForProjects(array $projectIds)
    {
        return Listing::query()
            ->publiclyListed()
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->leftJoin('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->leftJoin('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->leftJoin('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            ->leftJoin('furnishings', 'furnishings.id', '=', 'properties.furnishing_id')
            ->where('properties.is_project', true)
            ->whereIn('properties.project_id', $projectIds)
            ->selectRaw(
                "properties.project_id as project_id,
                 MIN(CASE WHEN categories.name = 'For Sale' AND listings.price >= 1000 THEN listings.price END) as sale_min_price,
                 MAX(CASE WHEN categories.name = 'For Sale' AND listings.price >= 1000 THEN listings.price END) as sale_max_price,
                 MIN(CASE WHEN categories.name = 'For Rent' AND listings.price >= 1000 THEN listings.price END) as rent_min_price,
                 MAX(CASE WHEN categories.name = 'For Rent' AND listings.price >= 1000 THEN listings.price END) as rent_max_price,
                 MIN(property_attributes.bedroom_count) as bedroom_min,
                 MAX(property_attributes.bedroom_count) as bedroom_max,
                 MIN(property_attributes.bathroom_count) as bathroom_min,
                 MAX(property_attributes.bathroom_count) as bathroom_max,
                 MIN(property_attributes.garage_count) as garage_min,
                 MAX(property_attributes.garage_count) as garage_max,
                 MIN(CASE WHEN property_attributes.floor_area >= 10 THEN property_attributes.floor_area END) as floor_area_min,
                 MAX(CASE WHEN property_attributes.floor_area >= 10 THEN property_attributes.floor_area END) as floor_area_max,
                 GROUP_CONCAT(DISTINCT property_types.name) as type_names,
                 GROUP_CONCAT(DISTINCT property_subtypes.name) as subtype_names,
                 GROUP_CONCAT(DISTINCT furnishings.name) as furnishing_names"
            )
            ->groupBy('properties.project_id')
            ->toBase()
            ->get()
            ->keyBy('project_id');
    }

    private function unitCombosForProjects(array $projectIds)
    {
        return Listing::query()
            ->publiclyListed()
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->leftJoin('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->leftJoin('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->leftJoin('furnishings', 'furnishings.id', '=', 'properties.furnishing_id')
            ->where('properties.is_project', true)
            ->whereIn('properties.project_id', $projectIds)
            ->selectRaw('DISTINCT properties.project_id as project_id, categories.name as category, property_subtypes.name as subtype, furnishings.name as furnishing')
            ->toBase()
            ->get()
            ->groupBy('project_id');
    }

    public function unitStatsFor(Project $project): array
    {
        $stats = $this->unitStatsForProjects([$project->id]);
        $combos = $this->unitCombosForProjects([$project->id]);

        return $this->attachUnitStats($project, $stats->get($project->id), $combos->get($project->id))->unit_stats;
    }

    private function attachUnitStats(Project $project, ?object $s, $combos = null): Project
    {
        $range = static fn ($min, $max, bool $int) => $min === null && $max === null
            ? null
            : [
                'min' => $int ? (int) $min : (float) $min,
                'max' => $int ? (int) $max : (float) $max,
            ];
        $names = static fn ($csv) => collect(explode(',', (string) $csv))
            ->map(fn ($n) => trim($n))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $project->setAttribute('unit_stats', [
            'price_range' => [
                'sale' => $range($s?->sale_min_price, $s?->sale_max_price, false),
                'rent' => $range($s?->rent_min_price, $s?->rent_max_price, false),
            ],
            'bedrooms' => $range($s?->bedroom_min, $s?->bedroom_max, true),
            'bathrooms' => $range($s?->bathroom_min, $s?->bathroom_max, true),
            'parking' => $range($s?->garage_min, $s?->garage_max, true),
            'floor_area' => $range($s?->floor_area_min, $s?->floor_area_max, false),
            'types' => $names($s?->type_names),
            'subtypes' => $names($s?->subtype_names),
            'furnishings' => $names($s?->furnishing_names),
            'combos' => collect($combos ?? [])
                ->map(fn ($c) => ['category' => $c->category, 'subtype' => $c->subtype, 'furnishing' => $c->furnishing])
                ->values()
                ->all(),
        ]);

        return $project;
    }

    private function baseProjectListQuery(bool $withListingsOnly = false)
    {
        $counts = $this->projectCountsSubquery();
        $categoryCounts = $this->projectCategoryCountsSubquery();

        $query = Project::query()
            ->select([
                ...self::PROJECT_LIST_COLUMNS,
                DB::raw('COALESCE(project_property_counts.properties_count, 0) as properties_count'),
                DB::raw('COALESCE(project_category_counts.public_listings_count, 0) as public_listings_count'),
                DB::raw('COALESCE(project_category_counts.sale_count, 0) as sale_count'),
                DB::raw('COALESCE(project_category_counts.rent_count, 0) as rent_count'),
                DB::raw('COALESCE(project_category_counts.foreclosure_count, 0) as foreclosure_count'),
            ])
            ->leftJoinSub($categoryCounts, 'project_category_counts', function ($join) {
                $join->on('project_category_counts.project_id', '=', 'projects.id');
            });

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
        string $sortBy = self::DEFAULT_SORT,
        ?float $priceMin = null,
        ?float $priceMax = null
    )
    {
        $query = $this->applyProjectPriceRange($this->baseProjectListQuery(true), $priceMin, $priceMax);

        $paginator = $this->applyProjectSort(
            $this->applyProjectSearch($query, $search),
            $sortBy
        )->paginate($perPage);

        $projects = $paginator->getCollection();
        $ids = $projects->pluck('id')->all();
        $stats = $projects->isEmpty() ? collect() : $this->unitStatsForProjects($ids);
        $combos = $projects->isEmpty() ? collect() : $this->unitCombosForProjects($ids);

        $projects->transform(fn (Project $p) => $this->attachUnitStats($p, $stats->get($p->id), $combos->get($p->id)));

        return $paginator;
    }
}
