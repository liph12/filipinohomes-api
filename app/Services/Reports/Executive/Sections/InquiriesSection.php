<?php

namespace App\Services\Reports\Executive\Sections;

use App\Services\Reports\Executive\Geography;
use App\Services\Reports\Executive\MetricEngine;
use App\Services\Reports\Executive\ReportContext;
use App\Services\Reports\Executive\ReportQueries;
use App\Services\Reports\Executive\RowHydrator;
use App\Support\OfficeRegionMap;
use App\Support\RegionMap;

/**
 * Where inquiries come from and what happens to them: by PSA region and by
 * office region, by property type and category, the most-inquired listings,
 * the hanging buckets, and the "said sold, still listed" claims.
 */
final class InquiriesSection
{
    private const TOP_LISTINGS = 10;

    public function __construct(private readonly RowHydrator $rows) {}

    public function build(ReportContext $c): array
    {
        $rows = $c->inquiries();

        return [
            'stats' => [
                $c->stat('inquiries.received', compare: true),
                $c->stat('inquiries.unique_clients', compare: true),
                $c->stat('inquiries.reached_agent'),
                $c->stat('inquiries.answered'),
                $c->stat('inquiries.first_reply_minutes'),
                $c->stat('inquiries.pending_now'),
            ],
            'hanging' => [
                $c->stat('inquiries.hanging.pending_review'),
                $c->stat('inquiries.hanging.no_reply'),
                $c->stat('inquiries.hanging.client_waiting'),
                $c->stat('inquiries.hanging.total'),
                $c->stat('inquiries.stalled'),
            ],
            'claims' => [
                $c->stat('claims.sold_unmarked', compare: true),
                $c->stat('claims.reserved_unmarked'),
                $c->stat('claims.inquiries_after'),
            ],
            'by_region' => [
                'metric' => 'inquiries.received',
                'psa' => $this->psaRegions($c->byRegion(ReportContext::countBy($rows, 'province_id'))),
                'office' => $this->officeRegions(ReportContext::byOffice(ReportContext::countBy($rows, 'office'))),
            ],
            'by_type' => [
                'metric' => 'inquiries.received',
                'rows' => $this->byType($c, ReportContext::countBy($rows, 'type_id')),
            ],
            'by_category' => [
                'metric' => 'inquiries.received',
                'rows' => $this->byCategory(ReportContext::countBy($rows, 'category')),
            ],
            'top_listings' => $this->topListings($c, ReportContext::countBy($rows, 'listing_id')),
        ];
    }

    private function psaRegions(array $counts): array
    {
        $out = [];
        foreach (ReportContext::ranked($counts) as $region) {
            $known = $region !== Geography::UNKNOWN_REGION;
            $out[] = [
                'key' => $region,
                'label' => $known ? RegionMap::label($region) : 'Unknown',
                'island' => $known ? RegionMap::islandOf($region) : null,
                'value' => $counts[$region],
                'filters' => ['psa_region' => $region],
            ];
        }

        return $out;
    }

    private function officeRegions(array $counts): array
    {
        $out = [];
        foreach (ReportContext::ranked($counts) as $office) {
            $out[] = [
                'key' => $office,
                'label' => $office === 'unassigned' ? 'No office region' : OfficeRegionMap::label($office),
                'value' => $counts[$office],
                'filters' => ['office_region' => $office],
            ];
        }

        return $out;
    }

    private function byType(ReportContext $c, array $counts): array
    {
        $names = $c->typeNames();
        $out = [];
        foreach (ReportContext::ranked($counts) as $type) {
            $id = $type === '' ? 0 : (int) $type;
            $out[] = [
                'key' => $id,
                'label' => $names[$id] ?? 'Unspecified',
                'value' => $counts[$type],
                'filters' => ['type_id' => $id],
            ];
        }

        return $out;
    }

    private function byCategory(array $counts): array
    {
        $keys = array_flip(ReportQueries::CATEGORIES);
        $out = [];
        foreach (ReportContext::ranked($counts) as $name) {
            if (! isset($keys[$name])) {
                continue; // the base query only holds the three standard categories
            }
            $out[] = ['key' => $keys[$name], 'label' => $name, 'value' => $counts[$name], 'filters' => ['category' => $keys[$name]]];
        }

        return $out;
    }

    private function topListings(ReportContext $c, array $counts): array
    {
        $ids = array_map('intval', ReportContext::ranked(array_diff_key($counts, ['' => true]), self::TOP_LISTINGS));
        $hanging = ReportContext::countBy($c->lifecycle()->whereIn('bucket', MetricEngine::HANGING), 'listing_id');
        $details = collect($this->rows->listings($ids, 'listing', 'listings.added'))->keyBy(fn ($row) => $row['listing']['id']);

        $rows = [];
        foreach ($ids as $id) {
            $d = $details->get($id);
            if ($d === null) {
                continue;
            }
            $rows[] = [
                'listing' => $d['listing'],
                'place' => $d['place'],
                'type' => $d['type'],
                'status' => $d['status'],
                'agent' => $d['agent'],
                'inquiries' => $counts[$id] ?? 0,
                'hanging' => $hanging[$id] ?? 0,
                'filters' => ['listing_id' => $id],
            ];
        }

        return [
            'columns' => [
                Table::col('listing', 'Listing', 'listing'),
                Table::col('place', 'Place', 'place'),
                Table::col('type', 'Type', 'text'),
                Table::col('status', 'Status', 'status'),
                Table::col('agent', 'Agent', 'agent'),
                Table::col('inquiries', 'Inquiries', 'number', 'inquiries.received'),
                Table::col('hanging', 'Hanging', 'number', 'inquiries.hanging.total'),
            ],
            'rows' => $rows,
        ];
    }
}
