<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PacesLrCalls;
use App\Models\Agent;
use App\Services\LeuterioreRealty\LrApiService;
use App\Services\LeuterioreRealty\LrEmploymentRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ask Leuterio Realty about each agent and RECORD what it says.
 *
 * LR is where an agent's termination is entered first. Its login-time payload
 * (the one TeamSyncService already downloads at every sign-in and ignores) has
 * a top-level `status`: active, inactive, terminated, or nothing. This command
 * writes that into agents.lr_employment_*. It NEVER changes agents.status —
 * that is agents:apply-lr-status, behind a review — so it is safe to leave
 * running while nobody has decided what to do with the answers.
 *
 * ─── Shape of the run ───────────────────────────────────────────────────────
 * One call per agent; no bulk endpoint. A hit takes 2–12 s and a miss ~0.3 s,
 * and LR allows 60 requests a minute per IP — a budget shared with real
 * sign-ins, where an LR failure registers a first-time agent as a CLIENT. So it
 * is chunked (`--limit`), reads LR's X-RateLimit-Remaining, gives way when the
 * budget runs low, and stops after three 429s in a row. It also stops on its
 * own after `max_run_minutes`: a slow LR (30 s timeouts) could otherwise carry a
 * run past its lock and into the next hour's. Resumable: what is due is decided
 * by agents.lr_employment_checked_at (config/lr_status.php).
 *
 * ─── What it will not do ────────────────────────────────────────────────────
 * - "Not found" is not "left": ~58% of FH's agents aren't in LR under their
 *   address. It is recorded as a dead end and asked about rarely.
 * - A failed lookup is recorded (retried tomorrow) so one agent that keeps
 *   failing can't sit at the front of the queue forever — but a 429 is not
 *   recorded, because it says nothing about the agent. An agent whose answer
 *   can't be WRITTEN (the database refused it) is logged and skipped, never
 *   fatal, for the same reason.
 * - Only role `agent` is asked about. Admins, secretaries and editors also have
 *   agents rows, and are never demoted by LR.
 */
class SyncLrAgentStatus extends Command
{
    use PacesLrCalls;

    protected $signature = 'agents:sync-lr-status
        {--limit= : How many agents to ask LR about this run}
        {--sleep-ms= : Pause between LR calls}
        {--dry-run : Ask LR and report, record nothing}';

    protected $description = 'Record what Leuterio Realty says about each agent (never changes agents.status).';

    public function handle(LrApiService $lr, LrEmploymentRecorder $recorder): int
    {
        $limit = $this->wholeOption('limit');
        $sleepMs = $this->wholeOption('sleep-ms');
        if ($limit === false || $limit === 0 || $sleepMs === false) {
            return $this->refuse('--limit must be a whole number of 1 or more, and --sleep-ms a whole number of milliseconds.');
        }

        // The scheduler's own overlap lock is a file, so it is per host; this
        // one is shared (config/lr_status.php).
        $lock = Cache::store((string) config('lr_status.lock_store', 'database'))
            ->lock('lr-status-sync', (int) config('lr_status.lock_seconds', 5400));

        if (! $lock->get()) {
            $this->warn('Another LR status sync is already running — nothing to do.');

            return self::SUCCESS;
        }

        try {
            return $this->sync(
                $lr,
                $recorder,
                $limit ?? max(1, (int) config('lr_status.limit', 300)),
                $sleepMs ?? max(0, (int) config('lr_status.sleep_ms', 1000)),
            );
        } finally {
            $lock->release();
        }
    }

    private function sync(LrApiService $lr, LrEmploymentRecorder $recorder, int $limit, int $sleepMs): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deadline = now()->addMinutes(max(1, (int) config('lr_status.max_run_minutes', 60)));

        $rows = $this->due($limit);

        if ($rows->isEmpty()) {
            $this->info('Nothing due — every agent was checked recently.');

            return self::SUCCESS;
        }

        $this->line("Asking LR about {$rows->count()} agent(s)".($dryRun ? ' (dry run)' : '').'…');

        // A long paced run can outlast the default limit.
        @set_time_limit(0);

        $found = $notFound = $errors = $throttledInARow = 0;
        $stoppedBecause = null;

        foreach ($rows as $row) {
            if (now()->greaterThanOrEqualTo($deadline)) {
                $stoppedBecause = 'its time budget is used up';
                break;
            }

            $key = LrEmploymentRecorder::lookupKey($row->lr_email, $row->login_email);

            if ($key === null) {
                // No usable address: nothing to ask LR, and nothing will change
                // until the address does — so stamp it and let it queue up behind
                // the rest rather than sit at the front.
                $errors++;
                if (! $dryRun) {
                    $this->record($recorder, (int) $row->id, ['result' => LrApiService::LOOKUP_ERROR, 'status' => null]);
                }

                continue;
            }

            $lookup = $lr->lookupAgentStatus($key);

            if ($lookup['result'] === LrApiService::LOOKUP_ERROR && $lookup['http_status'] === 429) {
                // LR is throttling us — about nobody in particular. Not recorded.
                if (++$throttledInARow >= 3) {
                    $stoppedBecause = 'LR is rate-limiting us';
                    break;
                }

                $this->backOff();

                continue;
            }
            $throttledInARow = 0;

            if (! $dryRun && ! $this->record($recorder, (int) $row->id, $lookup)) {
                $errors++; // the answer couldn't be written down — it comes round again, and the run carries on
            } else {
                match ($lookup['result']) {
                    LrApiService::LOOKUP_FOUND => $found++,
                    LrApiService::LOOKUP_NOT_FOUND => $notFound++,
                    default => $errors++,
                };
            }

            // Give way before real sign-ins run out of budget.
            $this->giveWayWhenLow($lookup['remaining']);
            $this->pause($sleepMs * 1000);
        }

        if ($stoppedBecause !== null) {
            $this->warn("Stopping: {$stoppedBecause} — the next run picks up where this one left off.");
        }

        Log::info('LR status sync finished', [
            'attempted' => $rows->count(),
            'found' => $found,
            'not_found' => $notFound,
            'errors' => $errors,
            'dry_run' => $dryRun,
            'stopped' => $stoppedBecause,
        ]);
        $this->info("LR status sync: {$found} found, {$notFound} not found, {$errors} errors".($dryRun ? ' (dry run — nothing recorded)' : '').'.');

        return self::SUCCESS;
    }

    /**
     * Write one answer down. A failure (the database refused it) is logged and
     * skipped: one agent that can't be recorded must not end the run — it would
     * be first in line again next time, and the whole sync would starve behind it.
     */
    private function record(LrEmploymentRecorder $recorder, int $agentId, array $lookup): bool
    {
        try {
            $recorder->record($agentId, $lookup);

            return true;
        } catch (\Throwable $e) {
            Log::warning('LR status sync: could not record an agent', [
                'agent_id' => $agentId,
                'error' => substr($e->getMessage(), 0, 300),
            ]);

            return false;
        }
    }

    /**
     * Agents due for a lookup: never-checked first (active before blocked), then
     * the oldest. NULLs sort first in ascending order on MySQL and SQLite alike.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function due(int $limit)
    {
        $cutoff = fn (string $kind) => now()->subDays((int) config("lr_status.recheck_days.{$kind}"));

        return Agent::query() // SoftDeletes: removed agents are excluded
            ->join('users', 'users.id', '=', 'agents.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'agent')
            ->where('users.email', 'like', '%@%')
            // An address another agent holds as their lr_email is ambiguous:
            // LR's answer would be about that agent, not this one.
            ->where(function ($q) {
                $q->whereNotNull('agents.lr_email')
                    ->orWhereNotExists(function ($other) {
                        $other->select(DB::raw(1))
                            ->from('agents as other')
                            ->whereColumn('other.id', '<>', 'agents.id')
                            ->whereRaw('LOWER(other.lr_email) = LOWER(users.email)');
                    });
            })
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('agents.lr_employment_checked_at')
                    // Agents FH has already switched off: the sync can't demote them.
                    ->orWhere(fn ($q) => $q->where('agents.status', '!=', 'active')
                        ->where('agents.lr_employment_checked_at', '<', $cutoff('blocked')))
                    ->orWhere(fn ($q) => $q->where('agents.status', 'active')->where(function ($q) use ($cutoff) {
                        foreach (['found', 'not_found', 'error'] as $kind) {
                            $q->orWhere(fn ($q) => $q->where('agents.lr_employment_lookup', $kind)
                                ->where('agents.lr_employment_checked_at', '<', $cutoff($kind)));
                        }
                    }));
            })
            ->orderByRaw('CASE WHEN agents.lr_employment_checked_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw("CASE WHEN agents.status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('agents.lr_employment_checked_at')
            ->orderBy('agents.id')
            ->limit($limit)
            ->get([
                'agents.id',
                'agents.lr_email',
                'users.email as login_email',
            ]);
    }
}
