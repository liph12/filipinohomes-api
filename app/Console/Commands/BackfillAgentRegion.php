<?php

namespace App\Console\Commands;

use App\Jobs\BackfillAgentRegionJob;
use App\Models\Agent;
use Illuminate\Console\Command;

/**
 * Backfill agents.region / agents.lr_state for agents that pre-date the
 * Secretary feature (region is captured at login going forward).
 *
 * Server load: the heavy work is the EXTERNAL LR v1 API (one throttled call per
 * agent); the FH-side work is a point-PK UPDATE on an indexed column. Off the
 * public hot path. Use --dry-run first to count unmappable states.
 *
 * Two run modes (mirrors images:generate-variants):
 *   inline   (default) — process in this CLI process; blocks the terminal.
 *   --queue            — dispatch chunked jobs to the 'region-backfill' queue
 *                        and return immediately; a worker drains them in the
 *                        background:  php artisan queue:work --queue=region-backfill
 *
 * --remap re-derives region from the stored lr_state with ZERO LR calls (use
 * after an OfficeRegionMap taxonomy change, e.g. the Davao provinces fix).
 */
class BackfillAgentRegion extends Command
{
    protected $signature = 'agents:backfill-region
        {--queue     : Dispatch chunked jobs to the region-backfill queue instead of running inline}
        {--chunk=200 : Agents per job / per DB chunk}
        {--limit=0   : Max agents to process (0 = all)}
        {--sleep=200 : Delay in ms between LR API calls (throttle)}
        {--dry-run   : Preview without writing}
        {--remap     : Re-derive region from existing lr_state, no LR calls}';

    protected $description = 'Backfill agents.region / agents.lr_state from the LR API (or remap from stored lr_state). Runs inline or on the region-backfill queue (--queue).';

    public function handle(): int
    {
        $useQueue = (bool) $this->option('queue');
        $chunk    = max(1, (int) $this->option('chunk'));
        $limit    = max(0, (int) $this->option('limit'));
        $sleep    = max(0, (int) $this->option('sleep'));
        $dryRun   = (bool) $this->option('dry-run');
        $remap    = (bool) $this->option('remap');

        // Resumable set: only agents still missing a region (or, in --remap mode,
        // those with a raw state to re-derive from).
        $query = Agent::query()->select('id')->whereNotNull('user_id');
        if ($remap) {
            $query->whereNotNull('lr_state');
        } else {
            $query->whereNull('region');
        }
        $query->orderBy('id');

        $total = (clone $query)->count();
        if ($limit > 0) {
            $total = min($total, $limit);
        }

        $this->info(sprintf(
            '%sMode: %s | Chunk: %d | Sleep: %dms%s — %d agent(s).',
            $dryRun ? '[DRY RUN] ' : '',
            $useQueue ? "queue dispatch ('region-backfill')" : 'inline',
            $chunk,
            $sleep,
            $remap ? ' | remap' : '',
            $total,
        ));

        $taken = 0;
        $dispatched = 0;
        $mapped = 0;
        $unmapped = 0;
        $noState = 0;

        $query->chunkById($chunk, function ($agents) use (
            $useQueue, $remap, $dryRun, $sleep, $limit,
            &$taken, &$dispatched, &$mapped, &$unmapped, &$noState
        ) {
            $ids = [];
            foreach ($agents as $agent) {
                if ($limit > 0 && $taken >= $limit) {
                    break;
                }
                $ids[] = $agent->id;
                $taken++;
            }

            if (! empty($ids)) {
                if ($useQueue) {
                    BackfillAgentRegionJob::dispatch($ids, $remap, $dryRun, $sleep)
                        ->onQueue('region-backfill');
                    $dispatched += count($ids);
                } else {
                    $r = (new BackfillAgentRegionJob($ids, $remap, $dryRun, $sleep))->handleInline($this);
                    $dispatched += $r['processed'];
                    $mapped += $r['mapped'];
                    $unmapped += $r['unmapped'];
                    $noState += $r['no_state'];
                }
            }

            // Stop chunking once the limit is reached.
            return ! ($limit > 0 && $taken >= $limit);
        });

        $this->newLine();
        if ($useQueue) {
            $this->info(($dryRun ? '[DRY RUN] ' : '')
                . "Dispatched {$dispatched} agent(s) across " . (int) ceil($dispatched / $chunk)
                . " job(s) to queue 'region-backfill'.");
            $this->line("Run a worker to drain it:  php artisan queue:work --queue=region-backfill");
        } else {
            $this->table(
                ['Processed', 'Mapped', 'Unmapped state', 'No state'],
                [[$dispatched, $mapped, $unmapped, $noState]],
            );
            $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Done.');
        }

        return self::SUCCESS;
    }
}
