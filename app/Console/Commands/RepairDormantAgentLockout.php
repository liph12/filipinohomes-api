<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Audit;
use App\Models\User;
use App\Services\Agent\AgentCachePurger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Lets back in the agents the old login-time dormancy check locked out.
 *
 * While that check ran at sign-in it measured a never-online account from
 * created_at, so on any long-standing agent the window had already closed
 * and the agent was auto-deactivated by the very act of signing in. An admin
 * could set them Active and the agent's next sign-in put them straight back
 * to deactivated — the admin could never win, and nothing said why. The
 * reported case (an agent showing Active in the admin table but "Account
 * Deactivated" at her own login, with Total Logins climbing the whole time)
 * is this.
 *
 * The cause is fixed — signing in now records activity and the rule moved to
 * agents:deactivate-dormant — but agents already sitting in that hole stay
 * there until something lets them out. This is that something.
 *
 * ─── Who it restores, and who it deliberately leaves alone ──────────────
 *
 * Only an agent who is all three of:
 *
 *   1. deactivated now,
 *   2. carrying a heartbeat inside the dormancy window — they demonstrably
 *      came back, which is what being bounced at the door looks like, and
 *   3. deactivated by the automatic rule (audit source `auto_dormant`) with
 *      no admin status change after it.
 *
 * (3) is what keeps an admin's deliberate call intact: a status an admin set
 * from the dropdown is never touched, even when the agent is active again.
 * (2) is what keeps the genuinely absent deactivated — an agent who never
 * came back has no recent heartbeat and is left as they are, for
 * agents:deactivate-dormant to keep owning.
 *
 * Reports only unless --force, like the sweep.
 */
class RepairDormantAgentLockout extends Command
{
    protected $signature = 'agents:repair-dormant-lockout
                            {--force : Apply the changes. Without it the run only reports.}
                            {--limit=0 : Stop after this many agents (0 = no cap).}';

    protected $description = 'Reactivate agents auto-deactivated by the old login-time dormancy check who have since signed back in.';

    public function handle(AgentCachePurger $purger): int
    {
        $force = (bool) $this->option('force');
        $limit = max(0, (int) $this->option('limit'));
        $threshold = Carbon::today()->subDays(User::DORMANT_DAYS);

        // Deactivated, but with a heartbeat inside the window — the shape of
        // an agent who was turned away at their own login.
        $candidates = Agent::query()
            ->where('status', 'deactivated')
            ->whereHas('user', fn ($q) => $q
                ->whereNotNull('last_online_at')
                ->where('last_online_at', '>=', $threshold))
            ->orderBy('id')
            ->get(['id', 'user_id']);

        $targets = $candidates->filter(fn (Agent $agent) => $this->wasAutoDeactivated((int) $agent->id))->values();

        if ($targets->isEmpty()) {
            $this->info('No agents are locked out by the old dormancy check.');

            if ($candidates->isNotEmpty()) {
                $this->line(sprintf(
                    '%d deactivated agent%s active again, but the automatic rule is not what put them there — left alone.',
                    $candidates->count(),
                    $candidates->count() === 1 ? ' is' : 's are',
                ));
            }

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%d agent%s auto-deactivated despite a heartbeat since %s.',
            $targets->count(),
            $targets->count() === 1 ? '' : 's',
            $threshold->toDateString(),
        ));

        $this->sample($targets->pluck('user_id')->all());

        if (! $force) {
            $this->newLine();
            $this->warn('Dry run — nothing was changed.');
            $this->line('Re-run with --force to set them back to Active.');

            Log::info('Dormancy lockout repair (dry run)', ['locked_out_agents' => $targets->count()]);

            return self::SUCCESS;
        }

        $apply = $limit > 0 ? $targets->take($limit) : $targets;
        $restored = 0;

        foreach (Agent::whereIn('id', $apply->pluck('id')->all())->get() as $agent) {
            $agent->auditDescription = 'Reactivated — auto-deactivated by the old login-time dormancy check';
            $agent->auditSource = 'dormancy_lockout_repair';
            $agent->update(['status' => 'active']);

            $purger->purgeAgent((int) $agent->id, true);
            $restored++;
        }

        $this->newLine();
        $this->info(sprintf('Reactivated %d agent%s.', $restored, $restored === 1 ? '' : 's'));

        Log::info('Dormancy lockout repair applied', [
            'restored' => $restored,
            'locked_out_agents' => $targets->count(),
        ]);

        return self::SUCCESS;
    }

    /**
     * True when this agent's current `deactivated` status came from the
     * automatic rule and no admin has set the status since.
     */
    private function wasAutoDeactivated(int $agentId): bool
    {
        $auto = Audit::where('auditable_type', Agent::class)
            ->where('auditable_id', $agentId)
            ->where('source', 'auto_dormant')
            ->where('new_values', 'like', '%"status":"deactivated"%')
            ->max('id');

        if ($auto === null) {
            return false;
        }

        return ! Audit::where('auditable_type', Agent::class)
            ->where('auditable_id', $agentId)
            ->where('source', 'admin_status_dropdown')
            ->where('id', '>', $auto)
            ->exists();
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function sample(array $userIds): void
    {
        $sample = User::whereIn('id', array_slice($userIds, 0, 15))
            ->orderBy('id')
            ->get(['id', 'email', 'last_online_at']);

        if ($sample->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->table(
            ['User', 'Email', 'Last online'],
            $sample->map(fn (User $u) => [
                $u->id,
                $u->email,
                optional($u->last_online_at)->toDateTimeString() ?? 'never',
            ])->all(),
        );

        if (count($userIds) > $sample->count()) {
            $this->line(sprintf('… and %d more.', count($userIds) - $sample->count()));
        }
    }
}
