<?php

namespace App\Console\Commands;

use App\Models\Agent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DeactivateDormantAgents extends Command
{
    protected $signature   = 'agents:deactivate-dormant';
    protected $description = 'Auto-deactivate active agents with no login in the last 45 days (policy counts from 2026-07-02)';

    public const DORMANT_DAYS = 45;

    /**
     * The date the dormancy policy started counting. Everyone is credited
     * with activity on this date, so no one — however old their last real
     * login — can be deactivated before POLICY_START + 45 days (2026-08-16).
     */
    public const POLICY_START = '2026-07-02';

    public function handle(): int
    {
        $threshold = Carbon::today()->subDays(self::DORMANT_DAYS);

        if ($threshold->lte(Carbon::parse(self::POLICY_START))) {
            $this->info('Still inside the initial 45-day grace window — nothing to do.');
            return self::SUCCESS;
        }

        $deactivated = 0;

        Agent::query()
            ->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->dormantSince($threshold))
            ->chunkById(100, function ($agents) use (&$deactivated) {
                foreach ($agents as $agent) {
                    $agent->update(['status' => 'deactivated']);
                    Log::info('Agent auto-deactivated — no activity in 45 days', [
                        'agent_id' => $agent->id,
                        'user_id'  => $agent->user_id,
                    ]);
                    $deactivated++;
                }
            });

        $this->info("Deactivated {$deactivated} dormant agent(s).");
        return self::SUCCESS;
    }
}
