<?php

namespace App\Services\FhOne;

use App\Services\Reports\Executive\DrillService;
use App\Services\Reports\Executive\ExecutiveReportService;
use App\Services\Reports\Executive\MetricRegistry;
use App\Services\Reports\Executive\ReportRange;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * The numbers FH One asks for. Every one comes from the same code as FH's AI
 * Report (ReportContext, DrillService, MetricEngine), so what FH One shows is
 * what the report shows for the same dates and filters, and the records
 * behind a number are exactly as many as the number.
 *
 * Values and breakdowns are kept for a few minutes (config fhone.cache_seconds),
 * each stamped with when it was computed so FH One can say "as of 9:05 AM".
 * Records are cached by the drill itself.
 */
final class MetricService
{
    public function __construct(
        private readonly ExecutiveReportService $reports,
        private readonly DrillService $drill,
        private readonly BreakdownService $breakdowns,
    ) {}

    /**
     * One metric's value for the dates and filters, optionally with the
     * previous period beside it.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     *
     * @throws InvalidAsk when a filter doesn't apply to the metric
     */
    public function value(string $key, ReportRange $range, array $filters, bool $compare): array
    {
        $this->checkFilters($key, $filters);

        return $this->remember('value', [$key, $range->key(), $filters, $compare], function (Carbon $now) use ($key, $range, $filters, $compare) {
            $def = MetricRegistry::get($key);
            $stat = $this->reports->context($range, $now, $filters)->stat($key, $compare);

            return [
                'metric' => $key,
                'label' => $def['label'],
                'definition' => $def['hint'],
                'unit' => $def['unit'],
                'kind' => $def['kind'],
                'measured' => $def['as_of'],
                'better' => $def['better'],
                'trust' => MetricCatalog::trust($key),
                'value' => $stat['value'],
                // For a median: how many records it was taken over.
                'sample' => $stat['sample'] ?? null,
                // The dates start before FH began recording this, so the number runs low.
                'partial' => $stat['partial'],
                'data_since' => $def['source'] !== null ? MetricRegistry::DATA_SINCE[$def['source']] : null,
                'compare' => isset($stat['previous']) ? [
                    'range' => $range->previous()->toArray(),
                    'value' => $stat['previous'],
                    'change_pct' => $stat['change_pct'],
                    'partial' => $stat['previous_partial'],
                ] : null,
                'range' => $range->toArray(),
                'filters' => (object) $filters,
                'filter_labels' => (object) $this->drill->filterLabels($filters),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     *
     * @throws InvalidAsk when a filter doesn't apply, or the metric can't be split that way
     */
    public function breakdown(string $key, string $by, ReportRange $range, array $filters, int $top): array
    {
        $this->checkFilters($key, $filters);

        return $this->remember('breakdown', [$key, $by, $range->key(), $filters, $top], function (Carbon $now) use ($key, $by, $range, $filters, $top) {
            $def = MetricRegistry::get($key);

            return [
                'metric' => $key,
                'label' => $def['label'],
                'unit' => $def['unit'],
                'measured' => $def['as_of'],
                'trust' => MetricCatalog::trust($key),
                'partial' => MetricRegistry::partial($key, $range),
                'data_since' => $def['source'] !== null ? MetricRegistry::DATA_SINCE[$def['source']] : null,
            ] + $this->breakdowns->build($key, $by, $range, $now, $filters, $top) + [
                'range' => $range->toArray(),
                'filters' => (object) $filters,
                'filter_labels' => (object) $this->drill->filterLabels($filters),
            ];
        });
    }

    /**
     * The records behind a number, a page at a time.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     *
     * @throws InvalidAsk when a filter doesn't apply to the metric
     */
    public function records(string $key, ReportRange $range, array $filters, int $page, int $perPage, ?string $sort, ?string $dir): array
    {
        $this->checkFilters($key, $filters);

        $result = $this->drill->drill($key, $range, Carbon::now(), $filters, $page, $perPage, $sort, $dir);

        // Same as the drill, with FH One's names: `measured`, `trust`.
        $measured = $result['as_of'];
        unset($result['as_of']);

        return ['measured' => $measured, 'trust' => MetricCatalog::trust($key)] + $result;
    }

    /** @throws InvalidAsk on `filters` when one doesn't apply to the metric */
    private function checkFilters(string $key, array $filters): void
    {
        try {
            $this->drill->assertFilters($key, $filters);
        } catch (InvalidArgumentException $e) {
            throw new InvalidAsk($e->getMessage(), 'filters');
        }
    }

    /**
     * @param  list<mixed>  $parts
     * @param  Closure(Carbon): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private function remember(string $kind, array $parts, Closure $build): array
    {
        $seconds = max(0, (int) config('fhone.cache_seconds', 300));

        return Cache::remember('fhone:v1:'.$kind.':'.sha1(json_encode($parts)), $seconds, function () use ($build) {
            $now = Carbon::now();

            return $build($now) + ['generated_at' => $now->copy()->setTimezone(ReportRange::TZ)->toIso8601String()];
        });
    }
}
