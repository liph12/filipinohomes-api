<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\User;
use App\Services\Agent\AgentCachePurger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The 45-day dormancy rule, applied to the agents who are actually absent.
 *
 * It used to run at sign-in (User::deactivateAgentIfDormant was called from
 * all four login paths), which pointed it at exactly the wrong people. The
 * check ran before anything stamped presence, so it only ever fired for an
 * agent coming BACK, while an agent who genuinely stayed away was never
 * evaluated at all and kept status = active — publicly listed, in the
 * sitemap — indefinitely. Sign-in now records activity instead
 * (User::recordLoginActivity) and this command owns the rule.
 *
 * ─── Why it only reports unless --force ─────────────────────────────────
 *
 * Deactivating an agent hides their listings from the public site and drops
 * them from the directory and the sitemap. Because the rule was previously
 * never applied to absent agents, the FIRST enforcement run has a backlog
 * reaching back to the policy start and could plausibly cover a large share
 * of the roster — the admin Agents table shows plenty of "Active · Never"
 * rows. Deactivating thousands of agents in one pass would be a site-wide
 * SEO event, so the default is a dry run that reports the cohort and writes
 * nothing. Read the number, then roll it out with --force --limit=N.
 *
 * The scheduled run in routes/console.php is deliberately the dry run: it
 * logs the cohort size daily so the figure is visible in production before
 * anyone commits to enforcing it.
 */
class DeactivateDormantAgents extends Command
{
    protected $signature = 'agents:deactivate-dormant
                            {--force : Apply the changes. Without it the run only reports.}
                            {--limit=0 : Stop after this many agents (0 = no cap) — use it to enforce in batches.}';

    protected $description = 'Deactivate agents with no presence heartbeat for 45+ days (reports only unless --force).';

    /** Per-agent cache purges above this many agents are collapsed to one directory purge. */
    private const PURGE_INDIVIDUALLY_UP_TO = 200;

    public function handle(AgentCachePurger $purger): int
    {
        $force = (bool) $this->option('force');
        $limit = max(0, (int) $this->option('limit'));
        $threshold = Carbon::today()->subDays(User::DORMANT_DAYS);

        $rows = Agent::query()
            ->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->dormantSince($threshold))
            ->orderBy('id')
            ->get(['id', 'user_id']);

        $total = $rows->count();

        if ($total === 0) {
            $this->info('No dormant agents — nothing to do.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%d active agent%s with no heartbeat since %s (%d-day window).',
            $total,
            $total === 1 ? '' : 's',
            $threshold->toDateString(),
            User::DORMANT_DAYS,
        ));

        $this->sample($rows->pluck('user_id')->all());

        if (! $force) {
            $this->newLine();
            $this->warn('Dry run — nothing was changed.');
            $this->line('Deactivating hides an agent\'s listings from the public site, the directory and the sitemap.');
            $this->line(sprintf(
                'Re-run with --force (and --limit=N to go in batches) to apply it to %d agent%s.',
                $total,
                $total === 1 ? '' : 's',
            ));

            Log::info('Dormancy sweep (dry run)', [
                'dormant_agents' => $total,
                'threshold'      => $threshold->toDateString(),
            ]);

            return self::SUCCESS;
        }

        $targets = $limit > 0 ? $rows->take($limit) : $rows;
        $purgeIndividually = $targets->count() <= self::PURGE_INDIVIDUALLY_UP_TO;
        $deactivated = 0;

        foreach ($targets->pluck('user_id')->chunk(500) as $chunk) {
            User::with('agent')->whereIn('id', $chunk->all())->get()->each(
                function (User $user) use (&$deactivated, $purger, $purgeIndividually) {
                    // Re-run the rule itself rather than trusting the scan —
                    // it re-reads this user's heartbeat, so an agent who came
                    // back between the scan and here is left alone.
                    $user->deactivateAgentIfDormant();

                    if ($user->agent?->status !== 'deactivated') {
                        return;
                    }

                    $deactivated++;

                    if ($purgeIndividually) {
                        $purger->purgeAgent((int) $user->agent->id, true);
                    }
                }
            );
        }

        if (! $purgeIndividually && $deactivated > 0) {
            // Each agent's own page still refreshes on the normal 10-minute
            // ISR window; purging the directory is what has to be immediate.
            // One POST per 50 tags makes a per-agent purge of a few thousand
            // agents hundreds of sequential HTTP calls, which is not worth it.
            $purger->purgeDirectory();
        }

        $this->newLine();
        $this->info(sprintf('Deactivated %d agent%s.', $deactivated, $deactivated === 1 ? '' : 's'));

        if ($limit > 0 && $total > $deactivated) {
            $this->line(sprintf('%d still dormant — re-run to continue.', $total - $deactivated));
        }

        Log::info('Dormancy sweep applied', [
            'deactivated' => $deactivated,
            'dormant_agents' => $total,
            'threshold' => $threshold->toDateString(),
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function sample(array $userIds): void
    {
        $sample = User::whereIn('id', array_slice($userIds, 0, 15))
            ->orderBy('id')
            ->get(['id', 'email', 'created_at', 'last_online_at']);

        if ($sample->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->table(
            ['User', 'Email', 'Signed up', 'Last online'],
            $sample->map(fn (User $u) => [
                $u->id,
                $u->email,
                optional($u->created_at)->toDateString() ?? '—',
                optional($u->last_online_at)->toDateString() ?? 'never',
            ])->all(),
        );

        if (count($userIds) > $sample->count()) {
            $this->line(sprintf('… and %d more.', count($userIds) - $sample->count()));
        }
    }
}
