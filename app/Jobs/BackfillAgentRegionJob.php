<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Services\LeuterioreRealty\LrApiService;
use App\Support\OfficeRegionMap;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Backfills agents.region / lr_state for a batch of agent ids.
 *
 * Dispatched in chunks by `agents:backfill-region --queue` (queue
 * 'region-backfill'); also runnable inline via handleInline(). Each agent needs
 * one LR v1 lookup to read its `state` (skipped in --remap, which re-derives
 * region from the already-stored lr_state), so calls are throttled by $sleepMs
 * to stay gentle on the external LR API.
 */
class BackfillAgentRegionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];
    public int $timeout = 1800;

    /** @param array<int> $agentIds */
    public function __construct(
        public array $agentIds,
        public bool $remap = false,
        public bool $dryRun = false,
        public int $sleepMs = 200,
    ) {
    }

    public function handle(): void
    {
        $r = $this->process(null);
        Log::info('agents:backfill-region batch', $r + [
            'remap' => $this->remap,
            'dry_run' => $this->dryRun,
        ]);
    }

    /**
     * Run synchronously from the console. Returns the per-batch tally so the
     * command can aggregate a summary.
     *
     * @return array{processed:int,mapped:int,unmapped:int,no_state:int}
     */
    public function handleInline(?Command $cmd = null): array
    {
        return $this->process($cmd);
    }

    /** @return array{processed:int,mapped:int,unmapped:int,no_state:int} */
    private function process(?Command $cmd): array
    {
        $lr = app(LrApiService::class);
        $processed = $mapped = $unmapped = $noState = 0;

        $agents = Agent::with('user:id,email')->whereIn('id', $this->agentIds)->get();
        foreach ($agents as $agent) {
            if ($this->remap) {
                $state = $agent->lr_state;
            } else {
                $email = $agent->user?->email ?? $agent->lr_email;
                $state = $email ? ($lr->fetchAgentByEmail($email)['state'] ?? null) : null;
                if ($this->sleepMs > 0) {
                    usleep($this->sleepMs * 1000);
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
                $cmd?->warn("  agent #{$agent->id}: state \"{$state}\" did not map to an office region.");
            }

            if (!$this->dryRun) {
                $update = ['region' => $region];
                if ($state !== '') {
                    $update['lr_state'] = $state;
                }
                Agent::whereKey($agent->id)->update($update);
            }

            $processed++;
        }

        return [
            'processed' => $processed,
            'mapped' => $mapped,
            'unmapped' => $unmapped,
            'no_state' => $noState,
        ];
    }
}
