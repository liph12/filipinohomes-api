<?php

namespace App\Console\Commands;

use App\Services\Listing\PropertyGeoResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reverse-geocodes the map pins of INQUIRED properties (those with a listing
 * chat) so the Inquiry Analytics location grouping uses the accurate
 * pin-derived city/barangay instead of the agent-picked address_id. Scoped to
 * inquired properties + cached (geo_geocoded_at) so it's cheap and re-runnable.
 */
class ResolveInquiryGeo extends Command
{
    protected $signature = 'inquiry-analytics:resolve-geo
        {--all : Resolve ALL properties with a pin, not just inquired ones}
        {--force : Re-resolve even if already geocoded}
        {--limit=0 : Max properties to process this run (0 = no cap)}
        {--sleep=120 : Milliseconds to sleep between Google calls}';

    protected $description = 'Reverse-geocode inquired property pins → cache city/barangay for analytics';

    public function handle(PropertyGeoResolver $resolver): int
    {
        $force = (bool) $this->option('force');
        $limit = (int) $this->option('limit');
        $sleepMs = max(0, (int) $this->option('sleep'));

        $query = DB::table('properties')
            ->whereNotNull('geo_coordinates')
            ->where('geo_coordinates', '!=', '');

        if (! $force) {
            $query->whereNull('geo_geocoded_at');
        }

        if (! $this->option('all')) {
            // Only properties that have been inquired (a listing chat).
            $query->whereIn('properties.id', function ($q) {
                $q->select('listings.property_id')
                    ->from('listings')
                    ->join('chats', function ($j) {
                        $j->on('chats.type_id', '=', 'listings.id')
                            ->where('chats.type', '=', 'listing')
                            ->whereNull('chats.deleted_at');
                    })
                    ->whereNull('listings.deleted_at');
            });
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows = $query->get(['id', 'geo_coordinates', 'geo_geocoded_at']);
        $total = $rows->count();
        if ($total === 0) {
            $this->info('Nothing to resolve.');
            return self::SUCCESS;
        }

        $this->info("Resolving {$total} property pin(s)…");
        $bar = $this->output->createProgressBar($total);
        $resolved = 0;

        foreach ($rows as $p) {
            try {
                if ($resolver->resolve($p, $force)) {
                    $resolved++;
                }
            } catch (\Throwable $e) {
                $this->warn("  property {$p->id}: {$e->getMessage()}");
            }
            $bar->advance();
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Resolved {$resolved}/{$total}.");

        return self::SUCCESS;
    }
}
