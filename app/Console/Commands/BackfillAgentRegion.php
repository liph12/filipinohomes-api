<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\LeuterioreRealty\LrApiService;
use App\Support\OfficeRegionMap;
use Illuminate\Console\Command;

/**
 * Backfill agents.region / agents.lr_state for agents that pre-date the Secretary
 * feature (region is captured at login going forward).
 *
 * Server load: the heavy work is the EXTERNAL LR v1 API (one throttled call per
 * agent); the FH-side work is a point-PK UPDATE on an indexed column. This is off
 * the public hot path — run it off-peak. Use --dry-run first to count unmappable
 * states, then run for real. --remap re-derives region from the stored lr_state
 * with ZERO LR calls (use after an OfficeRegionMap taxonomy change).
 */
class BackfillAgentRegion extends Command
{
    protected $signature = 'agents:backfill-region
        {--limit=0 : Max agents to process (0 = all)}
        {--sleep=200 : Delay in ms between LR API calls (throttle)}
        {--dry-run : Preview without writing}
        {--remap : Re-derive region from existing lr_state, no LR calls}';

    protected $description = 'Backfill agents.region / agents.lr_state from the LR API (or remap from stored lr_state).';

    public function handle(LrApiService $lr): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $remap  = (bool) $this->option('remap');
        $sleep  = max(0, (int) $this->option('sleep'));
        $limit  = max(0, (int) $this->option('limit'));

        $query = Agent::query()->whereNotNull('user_id');
        if ($remap) {
            // Remap mode: rows that have a raw state but no (or stale) region.
            $query->whereNotNull('lr_state');
        } else {
            // Default: only rows missing a region — re-runs resume where they left off.
            $query->whereNull('region');
        }
        $query->with('user:id,email')->orderBy('id');

        $total = (clone $query)->count();
        if ($limit > 0) {
            $total = min($total, $limit);
        }
        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Processing {$total} agent(s)" . ($remap ? ' (remap mode, no LR calls).' : '.'));

        $processed = 0;
        $mapped = 0;
        $unmapped = 0;
        $noState = 0;
        $stop = false;

        $query->chunkById(200, function ($agents) use ($lr, $dryRun, $remap, $sleep, $limit, &$processed, &$mapped, &$unmapped, &$noState, &$stop) {
            foreach ($agents as $agent) {
                if ($limit > 0 && $processed >= $limit) {
                    $stop = true;
                    return false;
                }

                $state = null;
                if ($remap) {
                    $state = $agent->lr_state;
                } else {
                    $email = $agent->user?->email ?? $agent->lr_email;
                    if ($email) {
                        $state = $lr->fetchAgentByEmail($email)['state'] ?? null;
                    }
                    if ($sleep > 0) {
                        usleep($sleep * 1000);
                    }
                }

                $state = $state !== null ? trim((string) $state) : '';
                $region = $state !== '' ? OfficeRegionMap::regionOf($state) : null;

                if ($state === '') {
                    $noState++;
                } elseif ($region !== null) {
                    $mapped++;
                } else {
                    $unmapped++;
                    $this->warn("  agent #{$agent->id}: state \"{$state}\" did not map to an office region.");
                }

                if (!$dryRun) {
                    $update = ['region' => $region];
                    if ($state !== '') {
                        $update['lr_state'] = $state;
                    }
                    Agent::whereKey($agent->id)->update($update);
                }

                $processed++;
            }

            return ! $stop;
        });

        $this->newLine();
        $this->table(
            ['Processed', 'Mapped', 'Unmapped state', 'No state'],
            [[$processed, $mapped, $unmapped, $noState]],
        );
        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Done.');

        return self::SUCCESS;
    }
}
