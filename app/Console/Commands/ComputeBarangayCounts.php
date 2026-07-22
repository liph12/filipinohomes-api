<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes public listing counts per effective barangay × (category ×
 * property type) for the barangay-tier SEO pages
 * (/for-sale/house/in-{barangay}-{city}-{province}).
 *
 * Effective barangay = COALESCE(properties.geo_barangay_id, address_id when
 * the agent-picked barangay doesn't contradict the reverse-geocoded pin's
 * city) — identical semantics to the `barangay_id` filter in
 * Listing::scopeFilter, so the precomputed totals equal on-page "X Results".
 *
 * Writes to `barangay_listing_counts` (delete-and-insert in a transaction).
 * Scheduled daily; mirrors `seo:compute-facility-counts`. Totals ≥ 1 are all
 * stored — indexability floors (≥10 sitemap, ≥25 SSG, <5 noindex) stay
 * frontend-owned so one table serves every gate.
 */
class ComputeBarangayCounts extends Command
{
    protected $signature = 'seo:compute-barangay-counts';

    protected $description = 'Recompute per-barangay public listing counts for the barangay-tier SEO pages.';

    public function handle(): int
    {
        $now = Carbon::now();

        // One GROUP BY over the publicly-listed predicate used by
        // SitemapController@locationCounts (visibility public, not flagged,
        // soft-deletes excluded, NO property.status filter — the public browse
        // shows all statuses). For Sale / For Rent only: Foreclosure has no
        // URL tier in the frontend yet.
        $cohorts = DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            // Agent-status gate: mirror Listing::scopePubliclyListed()'s
            // whereHas('agent', active) so an inactive/resigned/deactivated (or
            // soft-deleted) agent's listings leave the barangay counts too.
            // INNER JOIN on the PK (agent_id NOT NULL + FK) never fans out.
            ->join('agents', function ($j) {
                $j->on('agents.id', '=', 'listings.agent_id')
                  ->where('agents.status', 'active')
                  ->whereNull('agents.deleted_at');
            })
            ->leftJoin('barangays as addr_b', 'addr_b.id', '=', 'properties.address_id')
            // Effective barangay: pin wins; the agent dropdown only counts
            // when it doesn't contradict the pin's city (or there is no pin).
            ->join('barangays as eff_b', 'eff_b.id', '=', DB::raw(
                'COALESCE(properties.geo_barangay_id, '
                .'CASE WHEN properties.geo_city_id IS NULL OR addr_b.city_id = properties.geo_city_id '
                .'THEN properties.address_id ELSE NULL END)'
            ))
            ->join('cities', 'cities.id', '=', 'eff_b.city_id')
            ->join('provinces', 'provinces.id', '=', 'cities.province_id')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            ->whereNull('property_attributes.deleted_at')
            ->where('listings.visibility', 'public')
            ->where(function ($q) {
                $q->whereNull('listings.verification_status')
                    ->orWhere('listings.verification_status', '!=', 'flagged');
            })
            ->whereIn('categories.name', ['For Sale', 'For Rent'])
            ->select(
                'eff_b.id as barangay_id',
                'eff_b.name as barangay',
                'cities.id as city_id',
                'cities.name as city',
                'provinces.id as province_id',
                'provinces.name as province',
                'categories.name as category',
                'property_types.name as type',
                DB::raw('COUNT(*) as total'),
            )
            ->groupBy(
                'eff_b.id', 'eff_b.name',
                'cities.id', 'cities.name',
                'provinces.id', 'provinces.name',
                'categories.name', 'property_types.name',
            )
            ->get();

        $rows = $cohorts->map(fn ($c) => [
            'barangay_id' => (int) $c->barangay_id,
            'barangay'    => $c->barangay,
            'city_id'     => (int) $c->city_id,
            'city'        => $c->city,
            'province_id' => (int) $c->province_id,
            'province'    => $c->province,
            'category'    => $c->category,
            'type'        => $c->type,
            'total'       => (int) $c->total,
            'computed_at' => $now,
        ])->all();

        DB::transaction(function () use ($rows) {
            DB::table('barangay_listing_counts')->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('barangay_listing_counts')->insert($chunk);
            }
        });

        $this->info(sprintf('Wrote %d barangay cohort count(s).', count($rows)));

        return self::SUCCESS;
    }
}
