<?php

namespace App\Jobs;

use App\Models\SeoCommandRun;
use App\Services\Seo\SeoCommandRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Executes one SEO pipeline command on the queue worker for a MANUAL admin
 * trigger. The controller creates the seo_command_runs row (status=queued)
 * BEFORE dispatching so the UI shows "queued" instantly; this job walks it
 * through running → success|failed.
 *
 * Never run these synchronously in an HTTP request — the compute commands
 * take minutes and have RDS-spike history.
 *
 * $tries = 1 on purpose: the computes are idempotent (delete+insert in a
 * transaction) but an automatic retry of a heavy job risks piling load onto
 * an already-struggling DB; the admin can re-trigger from the UI.
 */
class RunSeoCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    // 15 min ceiling (the computes take 1–5 min). NOTE: the database queue's
    // retry_after must exceed this or a second worker re-pops the job mid-run
    // (Laravel invariant) — set DB_QUEUE_RETRY_AFTER >= 930 in the API .env.
    public int $timeout = 900;

    /** Max bytes of command output persisted on the run row. */
    private const OUTPUT_LIMIT = 20_000;

    public function __construct(public int $runId)
    {
    }

    public function handle(): void
    {
        $run = SeoCommandRun::find($this->runId);
        if (! $run || $run->status !== SeoCommandRun::STATUS_QUEUED) {
            return; // already handled / cancelled
        }

        $command = $run->command;

        // Whitelist re-check at execution time (the controller already
        // validated, but the queue payload outlives deploys).
        if (! SeoCommandRegistry::isRunnable($command)) {
            $this->finish($run, SeoCommandRun::STATUS_FAILED, error: "Unknown command: {$command}");

            return;
        }

        // Stale sweep: a `running` row whose worker died — or a `queued` row
        // whose job was lost — would otherwise linger. Opportunistic (no extra
        // cron); scopeActive() also age-bounds both states so a lingering row
        // can never block triggers/schedules even before this sweep runs.
        $cutoff = now()->subHours(SeoCommandRun::STALE_AFTER_HOURS);
        SeoCommandRun::query()
            ->where('command', $command)
            ->where('id', '!=', $run->id)
            ->where(function ($q) use ($cutoff) {
                $q->where(function ($q) use ($cutoff) {
                    $q->where('status', SeoCommandRun::STATUS_RUNNING)->where('started_at', '<', $cutoff);
                })->orWhere(function ($q) use ($cutoff) {
                    $q->where('status', SeoCommandRun::STATUS_QUEUED)->where('queued_at', '<', $cutoff);
                });
            })
            ->update(['status' => SeoCommandRun::STATUS_STALE]);

        // Overlap guard #1 — the run table sees BOTH sources (scheduled runs
        // write rows via SeoCommandRunRecorder's ->before hook).
        $overlapping = SeoCommandRun::query()
            ->where('command', $command)
            ->where('id', '!=', $run->id)
            ->where('status', SeoCommandRun::STATUS_RUNNING)
            ->where('started_at', '>=', now()->subHours(SeoCommandRun::STALE_AFTER_HOURS))
            ->exists();
        if ($overlapping) {
            $this->finish($run, SeoCommandRun::STATUS_FAILED, error: 'Another run of this command is already in progress.');

            return;
        }

        // Overlap guard #2 — atomic lock for manual-vs-manual races the
        // row check can miss between read and write.
        $lock = Cache::lock("seo:run:{$command}", $this->timeout);
        if (! $lock->get()) {
            $this->finish($run, SeoCommandRun::STATUS_FAILED, error: 'Could not acquire the run lock — another run is in progress.');

            return;
        }

        try {
            $run->update([
                'status'     => SeoCommandRun::STATUS_RUNNING,
                'started_at' => now(),
            ]);

            $out = new BufferedOutput();
            $exitCode = Artisan::call($command, [], $out);

            $this->finish(
                $run,
                $exitCode === 0 ? SeoCommandRun::STATUS_SUCCESS : SeoCommandRun::STATUS_FAILED,
                exitCode: $exitCode,
                output: Str::limit($out->fetch(), self::OUTPUT_LIMIT),
            );
        } catch (Throwable $e) {
            // Swallow rather than rethrow: with $tries=1 and no failed_jobs
            // table in this app, the run row IS the failure record.
            $this->finish($run, SeoCommandRun::STATUS_FAILED, error: Str::limit($e->getMessage(), 2000));
        } finally {
            $lock->release();
            // The overview caches freshness/counts for 10 min — bust it so
            // the dashboard reflects a finished run immediately.
            Cache::forget('admin:seo:overview');
        }
    }

    /**
     * Framework-level failure hook: fires when the queue infrastructure kills
     * the job WITHOUT handle() reaching its own catch (timeout kill, max
     * attempts exceeded after a mid-reservation worker crash, serialization
     * failure). Flips the run row so it never lingers as queued/running.
     */
    public function failed(?Throwable $e = null): void
    {
        $run = SeoCommandRun::find($this->runId);
        if (! $run || in_array($run->status, [SeoCommandRun::STATUS_SUCCESS, SeoCommandRun::STATUS_FAILED, SeoCommandRun::STATUS_STALE], true)) {
            return;
        }
        $this->finish($run, SeoCommandRun::STATUS_FAILED, error: Str::limit($e?->getMessage() ?? 'Job was dropped by the queue worker.', 2000));
    }

    private function finish(
        SeoCommandRun $run,
        string $status,
        ?int $exitCode = null,
        ?string $output = null,
        ?string $error = null,
    ): void {
        $startedAt = $run->started_at;

        $run->update([
            'status'      => $status,
            'finished_at' => now(),
            'duration_ms' => $startedAt ? (int) $startedAt->diffInMilliseconds(now()) : null,
            'exit_code'   => $exitCode,
            'output'      => $output,
            'error'       => $error,
        ]);
    }
}
