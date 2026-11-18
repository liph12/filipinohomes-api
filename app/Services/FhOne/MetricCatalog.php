<?php

namespace App\Services\FhOne;

use App\Http\Requests\FhOne\MetricBatchRequest;
use App\Services\Reports\Executive\Geography;
use App\Services\Reports\Executive\LeadQueries;
use App\Services\Reports\Executive\MetricRegistry;
use App\Services\Reports\Executive\ReportQueries;
use App\Services\Reports\Executive\ReportRange;
use App\Support\OfficeRegionMap;
use App\Support\RegionMap;
use Illuminate\Support\Carbon;

/**
 * What FH One can ask: every metric with its definition, trust level, the
 * date its data starts, the filters it takes and the ways it can be split.
 * Built from MetricRegistry, so it can't drift from the numbers themselves,
 * and written so FH One can turn it into the tools it offers Claude.
 */
final class MetricCatalog
{
    public const VERSION = 'v1';

    /**
     * How far to trust a number. `fh_signal`: what agents recorded on FH, a
     * stand-in for the real thing, so it shows the direction while totals may
     * run low (deals marked sold, "sold" said in chat). `fh_record`: FH's own
     * records, counted as they stand.
     */
    private const SIGNAL_PREFIXES = ['deals.', 'claims.'];

    public function __construct(private readonly BreakdownService $breakdowns) {}

    public static function trust(string $key): string
    {
        foreach (self::SIGNAL_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return 'fh_signal';
            }
        }

        return 'fh_record';
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now(ReportRange::TZ)->toIso8601String(),
            'trust' => [
                'fh_signal' => 'What agents recorded on FH, used as a stand-in for the real thing. It shows the direction; totals may run low.',
                'fh_record' => 'FH\'s own records, counted as they stand.',
            ],
            // What the API can do beyond one metric at a time, so FH One can use it when it is there and do without when it is not.
            'capabilities' => ['batch' => ['max_ids' => MetricBatchRequest::MAX_IDS]],
            'metrics' => array_map(fn (string $key) => $this->metric($key), MetricRegistry::keys()),
            'filters' => $this->filters(),
            'breakdowns' => BreakdownService::DIMENSIONS,
        ];
    }

    /** @return array<string, mixed> */
    public function metric(string $key): array
    {
        $def = MetricRegistry::get($key);
        $source = $def['source'];

        return [
            'id' => $key,
            'label' => $def['label'],
            'definition' => $def['hint'],
            'unit' => $def['unit'],
            'kind' => $def['kind'],
            // range: counted over the dates asked for. now: counted at this moment, whatever the dates.
            'measured' => $def['as_of'],
            'better' => $def['better'],
            'entity' => $def['entity'],
            'family' => $def['family'],
            'trust' => self::trust($key),
            'data_since' => $source !== null ? MetricRegistry::DATA_SINCE[$source] : null,
            'data_since_label' => $source !== null ? MetricRegistry::DATA_SINCE_LABELS[$source] : null,
            'filters' => MetricRegistry::filters($key),
            'breakdowns' => $this->breakdowns->dimensionsFor($key),
        ];
    }

    /**
     * Every filter FH One can send: what it is, where to look its values up,
     * and what 0 (or "unassigned") means, since the none bucket is a real
     * answer ("how many sold by agents with no team").
     *
     * @return array<string, array<string, mixed>>
     */
    private function filters(): array
    {
        return [
            'team_id' => ['type' => 'id', 'lookup' => 'teams', 'zero_means' => 'agents with no team'],
            'agent_id' => ['type' => 'id', 'lookup' => null, 'note' => 'An FH agent id, as given by a breakdown by agent.'],
            'city_id' => ['type' => 'id', 'lookup' => 'cities', 'zero_means' => 'no city'],
            'province_id' => ['type' => 'id', 'lookup' => 'provinces', 'zero_means' => 'no province'],
            'psa_region' => ['type' => 'enum', 'values' => [...RegionMap::REGIONS, Geography::UNKNOWN_REGION]],
            'island' => ['type' => 'enum', 'values' => ['luzon', 'visayas', 'mindanao']],
            'office_region' => ['type' => 'enum', 'lookup' => 'regions', 'values' => [...OfficeRegionMap::REGIONS, 'unassigned'], 'unassigned_means' => 'agents with no office region'],
            'type_id' => ['type' => 'id', 'lookup' => 'types', 'zero_means' => 'unspecified type'],
            'project_id' => ['type' => 'id', 'lookup' => 'projects', 'zero_means' => 'not in a project'],
            'project_ids' => ['type' => 'ids', 'lookup' => 'projects', 'note' => 'Comma-separated project ids, up to 200. Everything in any of them.'],
            'category' => ['type' => 'enum', 'values' => array_keys(ReportQueries::CATEGORIES)],
            'status' => ['type' => 'string', 'note' => 'A listing status such as active, sold, rented or leased.'],
            'listing_id' => ['type' => 'id', 'lookup' => null],
            'role' => ['type' => 'string', 'note' => 'For user metrics: client, agent and so on.'],
            'leads' => ['type' => 'enum', 'values' => array_map('strval', array_keys(LeadQueries::BUCKETS)), 'note' => 'How many leads an agent got (agents.with_leads only).'],
        ];
    }
}
