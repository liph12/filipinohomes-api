<?php

namespace App\Services\Listing;

use Illuminate\Support\Facades\DB;

/**
 * Shared SQL building blocks for the admin "Listing Insights" services
 * (by-province, by-city, by-status, by-type). Counterpart to the Project
 * Insights services, but operates on every listing (no is_project filter), so
 * project units and standalone listings are treated equally. Counts are
 * listing-row counts.
 *
 * Per-request scope (agent/date/location) is stored on the instance and applied
 * centrally in baseListingQuery() so every sub-query of a single call stays
 * consistent. "Standard categories" = For Sale / For Rent / Foreclosure.
 */
abstract class ListingInsightsService
{
    protected const STANDARD_CATEGORIES = ['For Sale', 'For Rent', 'Foreclosure'];
    protected const TRANSACTION_STATUSES = ['sold', 'rented', 'leased'];

    /**
     * Per-request scoping for team-leader callers. When non-null, every
     * aggregation only counts listings whose `agent_id` is in this list —
     * the leader sees their team's footprint, not the whole platform.
     * Admins pass null (no scoping). An empty array means "no agents" and
     * intentionally yields zeroes.
     */
    protected ?array $agentIds = null;

    /**
     * Per-request date window (on listings.created_at). When set, every
     * aggregation only counts listings created within the range. Null means
     * "all-time". Applied centrally in baseListingQuery() so all sub-queries
     * stay consistent.
     */
    protected ?string $dateStart = null;
    protected ?string $dateEnd = null;

    /**
     * Optional province / city scope. When set, baseListingQuery() narrows to
     * the matching location (resolved via project or property → barangay).
     */
    protected ?int $provinceId = null;
    protected ?int $cityId = null;

    /**
     * Base join chain — listings → properties → location resolution.
     * Every aggregation query layers on top of this closure.
     */
    protected function baseListingQuery()
    {
        $query = DB::table('listings')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->leftJoin('projects', function ($join) {
                $join->on('projects.id', '=', 'properties.project_id')
                    ->whereNull('projects.deleted_at');
            })
            ->leftJoin('cities as project_cities', 'project_cities.id', '=', 'projects.city_id')
            ->leftJoin('provinces as project_provinces', 'project_provinces.id', '=', 'projects.prov_id')
            ->leftJoin('barangays', 'barangays.id', '=', 'properties.address_id')
            ->leftJoin('cities as property_cities', 'property_cities.id', '=', 'barangays.city_id')
            ->leftJoin('provinces as property_provinces', 'property_provinces.id', '=', 'property_cities.province_id')
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            // Exclude listings whose property was marked deleted (status, not soft-delete).
            ->where('properties.status', '!=', 'deleted')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES);

        if ($this->agentIds !== null) {
            $query->whereIn('listings.agent_id', $this->agentIds);
        }

        if ($this->dateStart !== null && $this->dateStart !== '') {
            $query->where('listings.created_at', '>=', $this->dateStart . ' 00:00:00');
        }
        if ($this->dateEnd !== null && $this->dateEnd !== '') {
            $query->where('listings.created_at', '<=', $this->dateEnd . ' 23:59:59');
        }

        if ($this->provinceId !== null) {
            $query->where(DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)'), $this->provinceId);
        }
        if ($this->cityId !== null) {
            $query->where(DB::raw('COALESCE(projects.city_id, property_cities.id)'), $this->cityId);
        }

        return $query;
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
     * Constrain a query to listings whose property carries ATS attachment files.
     * Uses the indexed generated column properties.has_ats_files (see the
     * add_insights_indexes migration) so this is index-backed, not a per-row
     * JSON_LENGTH scan.
     */
    protected function withAtsAttachments($query)
    {
        return $query->where('properties.has_ats_files', 1);
    }
}
