<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\LeuterioreRealty\LrApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fill agents.birthdate from Leuterio Realty for agents who don't have one.
 *
 * Why this exists: birthdate only arrives via LrAgentBackfillService, which
 * runs on LOGIN. Coverage therefore tracks logins, not headcount — before the
 * first run, 5 of 6,065 agents had a birthday on file. Without this command
 * birthdays:send-greetings greets almost nobody, and an agent who never signs
 * in would never be greeted at all. Measured on a real 8-agent batch, LR
 * returned a birthday for 5 of them.
 *
 * ─── Shape of the run ───────────────────────────────────────────────────────
 * LR's detail endpoint is per-email with no bulk equivalent, and LR rate
 * limits to 60 requests/minute from a single IP. So: chunked (`--limit`),
 * paced (`--sleep-ms`), and resumable — scheduled hourly, it drains over a
 * couple of days and then costs almost nothing.
 *
 * `birthdate_checked_at` is what makes it resumable. "We asked and LR didn't
 * know" is a different state from "we have never asked", and without the
 * distinction every run re-queries the same dead ends and never reaches the
 * agents further down the list.
 */
class BackfillAgentBirthdates extends Command
{
    protected $signature = 'birthdays:backfill-birthdates
        {--limit= : How many agents to attempt this run}
        {--sleep-ms=1100 : Pause between LR calls, to stay under 60 req/min}
        {--dry-run : Report what would be written, write nothing}';

    protected $description = 'Backfill agents.birthdate from Leuterio Realty for agents missing one.';

    public function handle(LrApiService $lr): int
    {
        $limit = (int) ($this->option('limit') ?: config('birthdays.backfill.limit', 200));
        $sleepUs = max(0, (int) $this->option('sleep-ms')) * 1000;
        $dryRun = (bool) $this->option('dry-run');
        $recheckDays = (int) config('birthdays.backfill.recheck_days', 90);
        $cutoff = now()->subDays($recheckDays);

        $agents = Agent::query()
            ->join('users', 'users.id', '=', 'agents.user_id')
            ->whereNull('agents.birthdate')
            ->where('agents.status', 'active')
            ->where('users.email', 'like', '%@%')
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('agents.birthdate_checked_at')
                    ->orWhere('agents.birthdate_checked_at', '<', $cutoff);
            })
            ->orderBy('agents.id')
            ->limit($limit)
            ->get(['agents.id', 'agents.first_name', 'users.email']);

        if ($agents->isEmpty()) {
            $this->info('Nothing to backfill — every active agent has a birthdate or was checked recently.');

            return self::SUCCESS;
        }

        $this->line("Attempting {$agents->count()} agent(s)".($dryRun ? ' (dry run)' : '').'…');

        // A long paced run can outlast the default limit.
        @set_time_limit(0);

        $filled = 0;
        $unknown = 0;
        $rejected = 0;
        $errors = 0;

        foreach ($agents as $row) {
            $email = (string) $row->email;

            try {
                $detail = $lr->agentDetail($email);
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('Birthdate backfill: LR lookup failed', ['email' => $email, 'error' => $e->getMessage()]);
                // Not stamped: a transport error says nothing about whether LR
                // knows this agent's birthday, so the next run should retry.
                $this->pause($sleepUs);
                continue;
            }

            $raw = trim((string) ($detail['birthday'] ?? ''));
            $birthdate = $this->parse($raw);

            if ($raw !== '' && $birthdate === null) {
                $rejected++;
                $this->line("  <fg=yellow>reject</> {$email} — unusable birthday '{$raw}'");
            } elseif ($birthdate === null) {
                $unknown++;
            } else {
                $filled++;
                $this->line("  <fg=green>fill</>   {$email} — {$birthdate}");
            }

            if (! $dryRun) {
                // Fill blanks only, never overwrite — same rule as
                // LrAgentBackfillService. checked_at is stamped either way, so
                // an agent LR has nothing for drops out of the next run.
                $updates = ['birthdate_checked_at' => now()];
                if ($birthdate !== null) {
                    $updates['birthdate'] = $birthdate;
                }
                Agent::whereKey($row->id)->whereNull('birthdate')->update($updates);
            }

            $this->pause($sleepUs);
        }

        Log::info('Birthdate backfill finished', [
            'attempted' => $agents->count(),
            'filled' => $filled,
            'unknown' => $unknown,
            'rejected' => $rejected,
            'errors' => $errors,
            'dry_run' => $dryRun,
        ]);
        $this->info("Backfill: filled {$filled}, LR had none for {$unknown}, rejected {$rejected}, errors {$errors}.");

        return self::SUCCESS;
    }

    /**
     * A birthday we're willing to store.
     *
     * Rejects the 1970-01-01 epoch default (junk from an earlier import, not a
     * birthday — it is already filtered at read time, and letting it in here
     * would mean mailing a whole cohort every January 1) and anything outside
     * a plausible human range for a working agent.
     */
    private function parse(string $raw): ?string
    {
        if ($raw === '' || str_starts_with($raw, '0000')) {
            return null;
        }

        try {
            $date = Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }

        if ($date->format('Y-m-d') === '1970-01-01') {
            return null;
        }

        $age = $date->diffInYears(now());
        if ($age < 15 || $age > 100) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function pause(int $microseconds): void
    {
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }
}
