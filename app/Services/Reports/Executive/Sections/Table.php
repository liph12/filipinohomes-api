<?php

namespace App\Services\Reports\Executive\Sections;

use App\Services\Reports\Executive\MetricRegistry;

/**
 * Summary tables share one shape: `columns` (typed, and drillable when they
 * name a metric) and `rows` that each carry the `filters` locating them. A
 * cell drills with its column's metric and its row's filters — so the list
 * behind a cell is the metric's own query narrowed by the cell's own row.
 */
final class Table
{
    /** A column; `$metric` makes its cells clickable. */
    public static function col(string $key, string $label, string $type, ?string $metric = null): array
    {
        if ($metric !== null) {
            MetricRegistry::get($metric); // fail loudly on a typo, not in the browser
        }

        return array_filter(
            ['key' => $key, 'label' => $label, 'type' => $type, 'metric' => $metric],
            fn ($v) => $v !== null,
        );
    }

    /** A median cell: the value and the sample its drill lists. */
    public static function median(?float $value, int $sample): array
    {
        return ['value' => $value === null ? null : round($value, 2), 'sample' => $sample];
    }
}
