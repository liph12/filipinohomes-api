<?php

namespace App\Services\Reports\Executive\Sections;

use App\Services\Reports\Executive\PricePerSqm;
use App\Services\Reports\Executive\ReportContext;

/**
 * Where the market is: the towns with the most listings added (with their
 * inquiries, deals and median ₱/sqm), a link to the Listings Heatmap for the
 * same dates, and ₱/sqm by property type.
 *
 * Towns are the heatmap's (project location first, then the barangay; the
 * duplicate city rows folded), so a row here matches the map for the dates.
 */
final class PlacesSection
{
    private const TOP = 15;

    /** Towns outside the top that still lead on inquiries or deals get a row too. */
    private const ALSO = 5;

    public function build(ReportContext $c): array
    {
        $geo = $c->geo();
        $listings = $c->byCity(ReportContext::countBy($c->listingsAdded(), 'city_id'));
        $inquiries = $c->byCity(ReportContext::countBy($c->inquiries(), 'city_id'));
        $deals = $c->byCity(ReportContext::countBy($c->deals(), 'city_id'));

        $ppsqm = [];
        foreach ($c->priced('listings.ppsqm_sale') as $row) {
            $town = $row->city_id === null ? 0 : ($geo->cityGroup((int) $row->city_id) ?? 0);
            $ppsqm[$town][] = $row->ppsqm;
        }

        $noCity = [0 => true];
        $towns = ReportContext::ranked(array_diff_key($listings, $noCity), self::TOP);
        foreach ([$inquiries, $deals] as $counts) {
            foreach (ReportContext::ranked(array_diff_key($counts, $noCity), self::ALSO) as $town) {
                if (! in_array($town, $towns, true)) {
                    $towns[] = $town;
                }
            }
        }

        $rows = [];
        foreach ($towns as $town) {
            $rows[] = [
                'city' => $geo->cityName($town) ?? "City #{$town}",
                'province' => $geo->provinceName($geo->cityProvince($town)),
                'listings' => $listings[$town] ?? 0,
                'inquiries' => $inquiries[$town] ?? 0,
                'deals' => $deals[$town] ?? 0,
                'ppsqm_sale' => Table::median(PricePerSqm::median($ppsqm[$town] ?? []), count($ppsqm[$town] ?? [])),
                'filters' => ['city_id' => $town],
            ];
        }
        usort($rows, fn ($a, $b) => [$b['listings'], $b['inquiries'], $b['deals']] <=> [$a['listings'], $a['inquiries'], $a['deals']]);

        return [
            'stats' => [
                $c->stat('listings.added', compare: true),
                $c->stat('listings.added_by_staff'),
                $c->stat('listings.live'),
                $c->stat('listings.ppsqm_sale'),
                $c->stat('listings.ppsqm_rent'),
            ],
            'cities' => [
                'columns' => [
                    Table::col('city', 'Town / city', 'text'),
                    Table::col('province', 'Province', 'text'),
                    Table::col('listings', 'Listings added', 'number', 'listings.added'),
                    Table::col('inquiries', 'Inquiries', 'number', 'inquiries.received'),
                    Table::col('deals', 'Deals', 'number', 'deals.total'),
                    Table::col('ppsqm_sale', 'Median ₱/sqm (sale)', 'ppsqm_median', 'listings.ppsqm_sale'),
                ],
                'rows' => $rows,
                'towns' => count(array_diff_key($listings, $noCity)),
            ],
            'ppsqm_by_type' => $this->ppsqmByType($c),
            'heatmap' => [
                'path' => '/admin/insights-analytics/listings-heatmap',
                'query' => ['view' => 'cities', 'from' => $c->range->start, 'to' => $c->range->end],
            ],
        ];
    }

    private function ppsqmByType(ReportContext $c): array
    {
        $values = [];
        foreach (['sale' => 'listings.ppsqm_sale', 'rent' => 'listings.ppsqm_rent'] as $side => $key) {
            foreach ($c->priced($key) as $row) {
                $values[(int) ($row->type_id ?? 0)][$side][] = $row->ppsqm;
            }
        }

        $names = $c->typeNames();
        $rows = [];
        foreach ($values as $type => $sides) {
            $rows[] = [
                'type' => $names[$type] ?? 'Unspecified',
                'sale' => Table::median(PricePerSqm::median($sides['sale'] ?? []), count($sides['sale'] ?? [])),
                'rent' => Table::median(PricePerSqm::median($sides['rent'] ?? []), count($sides['rent'] ?? [])),
                'filters' => ['type_id' => $type],
            ];
        }
        usort($rows, fn ($a, $b) => [$b['sale']['sample'] + $b['rent']['sample'], $a['type']] <=> [$a['sale']['sample'] + $a['rent']['sample'], $b['type']]);

        return [
            'columns' => [
                Table::col('type', 'Type', 'text'),
                Table::col('sale', 'For sale (₱/sqm)', 'ppsqm_median', 'listings.ppsqm_sale'),
                Table::col('rent', 'For rent (₱/sqm a month)', 'ppsqm_median', 'listings.ppsqm_rent'),
            ],
            'rows' => $rows,
        ];
    }
}
