<?php

namespace App\Services\Listing;

use Illuminate\Support\Facades\DB;

/**
 * "Listings by Province" — one row per (province, city) with listing-count,
 * category, transaction and ATS-attachment metrics, grouped into provinces
 * with a cities[] child list.
 */
class ListingByProvinceService extends ListingInsightsService
{
    /**
     * Province-level breakdown. Returns one row per (province, city) with
     * listing-count metrics, then groups into provinces with cities[].
     */
    public function provinceBreakdown(string $sortBy = 'listing_count', ?array $agentIds = null, ?string $dateStart = null, ?string $dateEnd = null, ?int $provinceId = null, ?int $cityId = null): array
    {
        $this->agentIds   = $agentIds;
        $this->dateStart  = $dateStart;
        $this->dateEnd    = $dateEnd;
        $this->provinceId = $provinceId;
        $this->cityId     = $cityId;

        // One grouped pass per (province, city): listing_count plus the
        // category / transaction / ATS breakdowns as conditional sums. A single
        // scan of the join instead of four separate GROUP-BY queries. Province
        // totals are accumulated from the city rows in PHP so they stay
        // consistent with the city-level counts.
        $cityRows = $this->baseListingQuery()
            ->whereNotNull(DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)'))
            ->whereNotNull(DB::raw('COALESCE(projects.city_id, property_cities.id)'))
            ->select(
                DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id) as province_id'),
                DB::raw('COALESCE(project_provinces.name, property_provinces.name) as province_name'),
                DB::raw('COALESCE(projects.city_id, property_cities.id) as city_id'),
                DB::raw('COALESCE(project_cities.name, property_cities.name) as city_name'),
                DB::raw('COUNT(listings.id) as listing_count'),
                DB::raw("SUM(CASE WHEN categories.name = 'For Sale' THEN 1 ELSE 0 END) as for_sale"),
                DB::raw("SUM(CASE WHEN categories.name = 'For Rent' THEN 1 ELSE 0 END) as for_rent"),
                DB::raw("SUM(CASE WHEN categories.name = 'Foreclosure' THEN 1 ELSE 0 END) as foreclosure"),
                DB::raw("SUM(CASE WHEN properties.status = 'sold' THEN 1 ELSE 0 END) as sold"),
                DB::raw("SUM(CASE WHEN properties.status = 'rented' THEN 1 ELSE 0 END) as rented"),
                DB::raw("SUM(CASE WHEN properties.status = 'leased' THEN 1 ELSE 0 END) as leased"),
                DB::raw("SUM(CASE WHEN properties.has_ats_files = 1 AND properties.ats_status = 'approve' THEN 1 ELSE 0 END) as ats_approve"),
                DB::raw("SUM(CASE WHEN properties.has_ats_files = 1 AND properties.ats_status = 'pending' THEN 1 ELSE 0 END) as ats_pending"),
                DB::raw("SUM(CASE WHEN properties.has_ats_files = 1 AND properties.ats_status = 'expired' THEN 1 ELSE 0 END) as ats_expired"),
                DB::raw("SUM(CASE WHEN properties.has_ats_files = 1 AND properties.ats_status = 'rejected' THEN 1 ELSE 0 END) as ats_rejected")
            )
            ->groupByRaw('
                COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id),
                COALESCE(project_provinces.name, property_provinces.name),
                COALESCE(projects.city_id, property_cities.id),
                COALESCE(project_cities.name, property_cities.name)
            ')
            ->orderBy('province_name')
            ->orderByDesc('listing_count')
            ->orderBy('city_name')
            ->get();

        // Assemble province array — breakdowns read straight off each city row,
        // province totals accumulated as we go.
        $provinces = [];
        foreach ($cityRows as $row) {
            $provinceId = (int) $row->province_id;
            $count = (int) $row->listing_count;

            $cityCategory = [
                'for_sale'    => (int) $row->for_sale,
                'for_rent'    => (int) $row->for_rent,
                'foreclosure' => (int) $row->foreclosure,
            ];
            $cityTransaction = [
                'sold'   => (int) $row->sold,
                'rented' => (int) $row->rented,
                'leased' => (int) $row->leased,
            ];

            if (!isset($provinces[$provinceId])) {
                $provinces[$provinceId] = [
                    'province_id' => $provinceId,
                    'province_name' => (string) $row->province_name,
                    'listing_count' => 0,
                    'city_count' => 0,
                    'listing_breakdown' => ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0],
                    'transaction_breakdown' => ['sold' => 0, 'rented' => 0, 'leased' => 0],
                    'cities' => [],
                ];
            }

            $provinces[$provinceId]['listing_count'] += $count;
            $provinces[$provinceId]['city_count'] += 1;
            foreach ($cityCategory as $k => $v) {
                $provinces[$provinceId]['listing_breakdown'][$k] += $v;
            }
            foreach ($cityTransaction as $k => $v) {
                $provinces[$provinceId]['transaction_breakdown'][$k] += $v;
            }
            $provinces[$provinceId]['cities'][] = [
                'city_id' => (int) $row->city_id,
                'city_name' => (string) $row->city_name,
                'listing_count' => $count,
                'listing_breakdown' => $cityCategory,
                'transaction_breakdown' => $cityTransaction,
                'ats_breakdown' => [
                    'approve'  => (int) $row->ats_approve,
                    'pending'  => (int) $row->ats_pending,
                    'expired'  => (int) $row->ats_expired,
                    'rejected' => (int) $row->ats_rejected,
                ],
            ];
        }

        $provinceData = array_values($provinces);

        foreach ($provinceData as &$province) {
            usort($province['cities'], function (array $a, array $b) {
                return [$b['listing_count'], $a['city_name']] <=> [$a['listing_count'], $b['city_name']];
            });
        }
        unset($province);

        usort($provinceData, function (array $a, array $b) use ($sortBy) {
            return match ($sortBy) {
                'listing_count' => [$b['listing_count'], $b['city_count'], $a['province_name']] <=> [$a['listing_count'], $a['city_count'], $b['province_name']],
                'for_sale'      => [$b['listing_breakdown']['for_sale'], $b['city_count'], $a['province_name']] <=> [$a['listing_breakdown']['for_sale'], $a['city_count'], $b['province_name']],
                'for_rent'      => [$b['listing_breakdown']['for_rent'], $b['city_count'], $a['province_name']] <=> [$a['listing_breakdown']['for_rent'], $a['city_count'], $b['province_name']],
                'foreclosure'   => [$b['listing_breakdown']['foreclosure'], $b['city_count'], $a['province_name']] <=> [$a['listing_breakdown']['foreclosure'], $a['city_count'], $b['province_name']],
                'sold'          => [$b['transaction_breakdown']['sold'], $b['city_count'], $a['province_name']] <=> [$a['transaction_breakdown']['sold'], $a['city_count'], $b['province_name']],
                'rented'        => [$b['transaction_breakdown']['rented'], $b['city_count'], $a['province_name']] <=> [$a['transaction_breakdown']['rented'], $a['city_count'], $b['province_name']],
                'leased'        => [$b['transaction_breakdown']['leased'], $b['city_count'], $a['province_name']] <=> [$a['transaction_breakdown']['leased'], $a['city_count'], $b['province_name']],
                'province_name' => [$a['province_name'], $b['listing_count']] <=> [$b['province_name'], $a['listing_count']],
                default         => [$b['city_count'], $b['listing_count'], $a['province_name']] <=> [$a['city_count'], $a['listing_count'], $b['province_name']],
            };
        });

        $totals = [
            'for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0,
            'sold' => 0, 'rented' => 0, 'leased' => 0,
        ];
        foreach ($provinceData as $province) {
            $totals['for_sale']    += $province['listing_breakdown']['for_sale']     ?? 0;
            $totals['for_rent']    += $province['listing_breakdown']['for_rent']     ?? 0;
            $totals['foreclosure'] += $province['listing_breakdown']['foreclosure']  ?? 0;
            $totals['sold']        += $province['transaction_breakdown']['sold']     ?? 0;
            $totals['rented']      += $province['transaction_breakdown']['rented']   ?? 0;
            $totals['leased']      += $province['transaction_breakdown']['leased']   ?? 0;
        }

        return [
            'data' => $provinceData,
            'meta' => [
                'total_provinces'   => count($provinceData),
                // Each city belongs to exactly one province, so summing the
                // per-province city_count gives the unique city total.
                'total_cities'      => array_sum(array_column($provinceData, 'city_count')),
                'total_listings'    => array_sum(array_column($provinceData, 'listing_count')),
                'total_for_sale'    => $totals['for_sale'],
                'total_for_rent'    => $totals['for_rent'],
                'total_foreclosure' => $totals['foreclosure'],
                'total_sold'        => $totals['sold'],
                'total_rented'      => $totals['rented'],
                'total_leased'      => $totals['leased'],
                'sort_by'           => $sortBy,
                'date_start'        => $this->dateStart,
                'date_end'          => $this->dateEnd,
            ],
        ];
    }
}
