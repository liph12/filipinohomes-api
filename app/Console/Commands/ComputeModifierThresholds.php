<?php

namespace App\Console\Commands;

use App\Services\Sitemap\ModifierThresholdService;
use Illuminate\Console\Command;

/**
 * Recomputes cohort-relative price thresholds for the "affordable" programmatic
 * SEO modifier. Scheduled daily (catalog changes slowly); the result feeds
 * /sitemap/modifier-thresholds and the modifier category pages.
 */
class ComputeModifierThresholds extends Command
{
    protected $signature = 'seo:compute-modifier-thresholds';

    protected $description = 'Recompute per-cohort price thresholds for "affordable" programmatic SEO pages.';

    public function handle(ModifierThresholdService $service): int
    {
        $stats = $service->recompute();

        $this->info(sprintf(
            'Scanned %d cohort(s); wrote %d affordable threshold(s).',
            $stats['cohorts_scanned'],
            $stats['thresholds_written'],
        ));

        return self::SUCCESS;
    }
}
