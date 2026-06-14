<?php

namespace App\Services\Project;

use Illuminate\Support\Facades\DB;

/**
 * Shared SQL building blocks for the admin Project Insights services
 * (leaderboard, by-name, by-province). Holds the soft-delete-safe base query,
 * the project-key expression, and small value normalizers so each concrete
 * service stays focused on its own aggregation.
 *
 * Soft-delete invariants enforced everywhere:
 *   listings.deleted_at IS NULL
 *   properties.deleted_at IS NULL
 *   projects.deleted_at IS NULL (via leftJoin condition)
 *
 * "Standard categories" = For Sale / For Rent / Foreclosure. Other categories
 * (e.g., legacy / archived) are excluded from all counts and lists.
 */
abstract class ProjectInsightsService
{
    protected const STANDARD_CATEGORIES = ['For Sale', 'For Rent', 'Foreclosure'];
    protected const TRANSACTION_STATUSES = ['sold', 'rented', 'leased'];

    /**
     * Group-key expression used by every aggregation:
     *   project:<id>    when the property is linked to a live project
     *   property:<id>   when the project is missing or soft-deleted (orphan)
     *
     * Uses projects.id (NULL on soft-deleted) instead of properties.project_id
     * (which still references the dead FK) — this matches what the drill-down
     * endpoint accepts so click-through never 404s.
     */
    protected function projectKeyExpr(): string
    {
        return "CASE
            WHEN projects.id IS NULL THEN CONCAT('property:', properties.id)
            ELSE CONCAT('project:', projects.id)
        END";
    }

    /**
     * Base query: every is_project property that's still alive, joined to the
     * project (if any) and the full location resolution chain. Most insight
     * queries layer on top of this closure factory.
     */
    protected function baseProjectDashboardQuery()
    {
        return DB::table('properties')
            ->leftJoin('projects', function ($join) {
                $join->on('projects.id', '=', 'properties.project_id')
                    ->whereNull('projects.deleted_at');
            })
            ->leftJoin('cities as project_cities', 'project_cities.id', '=', 'projects.city_id')
            ->leftJoin('provinces as project_provinces', 'project_provinces.id', '=', 'projects.prov_id')
            ->leftJoin('barangays', 'barangays.id', '=', 'properties.address_id')
            ->leftJoin('cities as property_cities', 'property_cities.id', '=', 'barangays.city_id')
            ->leftJoin('provinces as property_provinces', 'property_provinces.id', '=', 'property_cities.province_id')
            ->whereNull('properties.deleted_at')
            ->where('properties.is_project', '=', 1);
    }

    /**
     * JSON-cast normalization: projects.featured_photo is cast to array on the
     * Eloquent model, but DB::table() bypasses casts. The raw column can be a
     * JSON array string, a plain URL, or null — return the first URL (or null).
     */
    protected function normalizeFeaturedPhoto($value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '"')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    return !empty($decoded[0]) ? (string) $decoded[0] : null;
                }
                if (is_string($decoded)) {
                    return $decoded;
                }
            }
            return $trimmed === '' ? null : $trimmed;
        }
        if (is_array($value)) {
            return !empty($value[0]) ? (string) $value[0] : null;
        }
        return null;
    }
}
