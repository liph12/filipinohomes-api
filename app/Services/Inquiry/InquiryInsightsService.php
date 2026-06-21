<?php

namespace App\Services\Inquiry;

use App\Support\IslandMap;
use Illuminate\Support\Facades\DB;

/**
 * Shared SQL building blocks for the admin "Inquiry Analytics" services
 * (overview, location drill-down, top clients).
 *
 * The fact table is the LIVE inquiry model: a `chats` row with type='listing'
 * is one inquiry (chats.type_id = listing, chats.user_id = the inquiring
 * client). This service is rooted on `chats` and NEVER joins `conversations`,
 * so each chat counts exactly once — no dedupe, no double-count from multiple
 * conversations per chat. (This deliberately differs from ChatController@stats,
 * which is conversation-grained for agent-workload metrics.)
 *
 * Per-request scope (date / category / property type / location) is stored on
 * the instance and applied centrally in baseInquiryQuery() so every sub-query
 * of a single call stays consistent. Location is resolved across BOTH the
 * project and the property → barangay → city → province paths (mirrors
 * ListingInsightsService) so project-unit inquiries aren't dropped.
 */
abstract class InquiryInsightsService
{
    protected const STANDARD_CATEGORIES = ['For Sale', 'For Rent', 'Foreclosure'];

    protected ?string $dateFrom = null;     // 'YYYY-MM-DD'
    protected ?string $dateTo = null;       // 'YYYY-MM-DD'
    protected ?int $categoryId = null;
    protected ?string $propertyType = null; // property_types.name
    protected ?int $provinceId = null;
    protected ?int $cityId = null;
    protected ?int $barangayId = null;
    /** Scope to a single inquiring client (drill from the clients table). */
    protected ?int $clientId = null;
    /** Province IDs for an island filter (precomputed from IslandMap). */
    protected ?array $islandProvinceIds = null;
    protected ?string $island = null;

    /**
     * Viewport-driven mode (the map heatmap). When $levelOverride is set, the
     * cluster query groups at that admin level directly, and the bounding box
     * (set below) restricts to properties currently on screen — so panning /
     * zooming re-clusters to "what I'm looking at".
     */
    protected ?string $levelOverride = null;
    protected ?float $minLat = null;
    protected ?float $maxLat = null;
    protected ?float $minLng = null;
    protected ?float $maxLng = null;

    /** Cached [province_id => province_name], loaded once per request. */
    private ?array $provinceNames = null;

    /**
     * Hydrate scope from validated request filters. Call once before querying.
     */
    public function configure(array $filters): static
    {
        $this->dateFrom     = $filters['date_from']     ?? null;
        $this->dateTo       = $filters['date_to']       ?? null;
        $this->categoryId   = isset($filters['category_id']) ? (int) $filters['category_id'] : null;
        $this->propertyType = $filters['property_type'] ?? null;
        $this->provinceId   = isset($filters['province_id']) ? (int) $filters['province_id'] : null;
        $this->cityId       = isset($filters['city_id']) ? (int) $filters['city_id'] : null;
        $this->barangayId   = isset($filters['barangay_id']) ? (int) $filters['barangay_id'] : null;
        $this->clientId     = isset($filters['client_id']) ? (int) $filters['client_id'] : null;
        $this->island       = $filters['island'] ?? null;

        $this->levelOverride = $filters['level'] ?? null;
        $this->minLat = isset($filters['min_lat']) ? (float) $filters['min_lat'] : null;
        $this->maxLat = isset($filters['max_lat']) ? (float) $filters['max_lat'] : null;
        $this->minLng = isset($filters['min_lng']) ? (float) $filters['min_lng'] : null;
        $this->maxLng = isset($filters['max_lng']) ? (float) $filters['max_lng'] : null;

        if ($this->island && !$this->provinceId && !$this->cityId) {
            $this->islandProvinceIds = IslandMap::provinceIdsForIsland(
                $this->provinceNames(),
                $this->island
            );
        }

        return $this;
    }

    /** [province_id => name] from the provinces table (cached, ~82 rows). */
    protected function provinceNames(): array
    {
        if ($this->provinceNames === null) {
            $this->provinceNames = DB::table('provinces')->pluck('name', 'id')->all();
        }

        return $this->provinceNames;
    }

    // Location resolution expressions — shared by every grouped query.
    protected function provinceIdExpr(): string
    {
        return 'COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)';
    }

    protected function provinceNameExpr(): string
    {
        return 'COALESCE(project_provinces.name, property_provinces.name)';
    }

    protected function cityIdExpr(): string
    {
        return 'COALESCE(projects.city_id, property_cities.id)';
    }

    protected function cityNameExpr(): string
    {
        return 'COALESCE(project_cities.name, property_cities.name)';
    }

    /**
     * Base join chain: chats(type=listing) → listing → category, property →
     * type/subtype, property/project → location, inquiring user (role=client).
     * Returns a fresh builder each call so sub-queries don't share state.
     */
    protected function baseInquiryQuery()
    {
        $q = DB::table('chats')
            ->whereNull('chats.deleted_at')
            ->where('chats.type', 'listing')
            ->join('listings', 'listings.id', '=', 'chats.type_id')
            ->whereNull('listings.deleted_at')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES)
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->whereNull('properties.deleted_at')
            ->where('properties.status', '!=', 'deleted')
            ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            // Location — project path + property path (LEFT so null-location
            // inquiries still count in totals/breakdowns).
            ->leftJoin('projects', function ($join) {
                $join->on('projects.id', '=', 'properties.project_id')
                    ->whereNull('projects.deleted_at');
            })
            ->leftJoin('cities as project_cities', 'project_cities.id', '=', 'projects.city_id')
            ->leftJoin('provinces as project_provinces', 'project_provinces.id', '=', 'projects.prov_id')
            ->leftJoin('barangays', 'barangays.id', '=', 'properties.address_id')
            ->leftJoin('cities as property_cities', 'property_cities.id', '=', 'barangays.city_id')
            ->leftJoin('provinces as property_provinces', 'property_provinces.id', '=', 'property_cities.province_id')
            // Real client inquirers only (exclude admin/agent inquirers).
            ->join('users as inq', 'inq.id', '=', 'chats.user_id')
            ->join('roles as inq_role', 'inq_role.id', '=', 'inq.role_id')
            ->where('inq_role.name', 'client');

        // Date range on the inquiry timestamp.
        if ($this->dateFrom) {
            $q->where('chats.created_at', '>=', $this->dateFrom . ' 00:00:00');
        }
        if ($this->dateTo) {
            $q->where('chats.created_at', '<=', $this->dateTo . ' 23:59:59');
        }

        // Cross-filters — apply at every level.
        if ($this->categoryId) {
            $q->where('listings.category_id', $this->categoryId);
        }
        if ($this->propertyType) {
            $q->where('property_types.name', $this->propertyType);
        }

        // Single-client scope (drill from the clients table / "this client's
        // inquired listings").
        if ($this->clientId) {
            $q->where('chats.user_id', $this->clientId);
        }

        // Location scope.
        if ($this->barangayId) {
            $q->where('barangays.id', $this->barangayId);
        }
        if ($this->cityId) {
            $q->where(DB::raw($this->cityIdExpr()), $this->cityId);
        }
        if ($this->provinceId) {
            $q->where(DB::raw($this->provinceIdExpr()), $this->provinceId);
        }
        if ($this->islandProvinceIds !== null) {
            // Empty list (island with no provinces) → no rows, intentionally.
            $q->whereIn(DB::raw($this->provinceIdExpr()), $this->islandProvinceIds ?: [0]);
        }

        // Viewport bounding box (map heatmap) — restrict to properties whose
        // geo_coordinates fall inside the currently-visible screen.
        if ($this->minLat !== null && $this->maxLat !== null && $this->minLng !== null && $this->maxLng !== null) {
            $lat = $this->geoLat();
            $lng = $this->geoLng();
            $q->whereRaw("({$lat}) BETWEEN ? AND ?", [$this->minLat, $this->maxLat])
              ->whereRaw("({$lng}) BETWEEN ? AND ?", [$this->minLng, $this->maxLng]);
        }

        return $q;
    }

    /** Numeric lat/lng extracted from the geo_coordinates JSON column. */
    protected function geoLat(): string
    {
        return "CAST(JSON_UNQUOTE(JSON_EXTRACT(properties.geo_coordinates, '$.lat')) AS DECIMAL(12,8))";
    }

    protected function geoLng(): string
    {
        return "CAST(JSON_UNQUOTE(JSON_EXTRACT(properties.geo_coordinates, '$.lng')) AS DECIMAL(12,8))";
    }

    protected function categoryKey(string $name): ?string
    {
        return match ($name) {
            'For Sale'    => 'for_sale',
            'For Rent'    => 'for_rent',
            'Foreclosure' => 'foreclosure',
            default       => null,
        };
    }

    /**
     * Category-aware price bucket CASE expression. Sale/Foreclosure use the
     * ₱-millions ladder; For Rent uses the ₱-thousands/month ladder — the two
     * scales must never share an axis.
     */
    protected function priceBucketExpr(): string
    {
        return "CASE
            WHEN categories.name = 'For Rent' THEN
                CASE
                    WHEN listings.price < 10000  THEN 'rent_lt_10k'
                    WHEN listings.price < 20000  THEN 'rent_10_20k'
                    WHEN listings.price < 35000  THEN 'rent_20_35k'
                    WHEN listings.price < 50000  THEN 'rent_35_50k'
                    WHEN listings.price < 100000 THEN 'rent_50_100k'
                    ELSE 'rent_100k_plus'
                END
            ELSE
                CASE
                    WHEN listings.price < 1000000  THEN 'sale_lt_1m'
                    WHEN listings.price < 3000000  THEN 'sale_1_3m'
                    WHEN listings.price < 5000000  THEN 'sale_3_5m'
                    WHEN listings.price < 10000000 THEN 'sale_5_10m'
                    WHEN listings.price < 20000000 THEN 'sale_10_20m'
                    ELSE 'sale_20m_plus'
                END
        END";
    }

    /** Ordered label map for price buckets — shared shape with the frontend. */
    public static function priceBucketLabels(): array
    {
        return [
            'sale' => [
                ['key' => 'sale_lt_1m',    'label' => '< ₱1M'],
                ['key' => 'sale_1_3m',     'label' => '₱1–3M'],
                ['key' => 'sale_3_5m',     'label' => '₱3–5M'],
                ['key' => 'sale_5_10m',    'label' => '₱5–10M'],
                ['key' => 'sale_10_20m',   'label' => '₱10–20M'],
                ['key' => 'sale_20m_plus', 'label' => '₱20M+'],
            ],
            'rent' => [
                ['key' => 'rent_lt_10k',    'label' => '< ₱10k/mo'],
                ['key' => 'rent_10_20k',    'label' => '₱10–20k/mo'],
                ['key' => 'rent_20_35k',    'label' => '₱20–35k/mo'],
                ['key' => 'rent_35_50k',    'label' => '₱35–50k/mo'],
                ['key' => 'rent_50_100k',   'label' => '₱50–100k/mo'],
                ['key' => 'rent_100k_plus', 'label' => '₱100k+/mo'],
            ],
        ];
    }
}
