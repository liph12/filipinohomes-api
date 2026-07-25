<?php

namespace App\Services\Seo;

use App\Models\SeoCommandRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes seo_command_runs rows for SCHEDULED executions (manual runs are
 * recorded by SeoCommandController + RunSeoCommand). Wired as
 * ->before/->onSuccess/->onFailure hooks in routes/console.php.
 *
 * Every method is fully defensive (try/catch → warn): these hooks run inside
 * the scheduler process, and a bookkeeping miss (e.g. code deployed before
 * the migration ran) must NEVER break the nightly compute pipeline itself.
 */
class SeoCommandRunRecorder
{
    /** Used by the schedule's ->skip() so a manual run blocks the nightly one. */
    public static function hasActiveRun(string $command): bool
    {
        try {
            return SeoCommandRun::hasActiveRun($command);
        } catch (Throwable $e) {
            // Table missing / DB hiccup — never skip the real work over bookkeeping.
            return false;
        }
    }

    public static function startScheduled(string $command): void
    {
        try {
            SeoCommandRun::create([
                'command'        => $command,
                'status'         => SeoCommandRun::STATUS_RUNNING,
                'trigger_source' => SeoCommandRun::SOURCE_SCHEDULE,
                'queued_at'      => now(),
                'started_at'     => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('SEO run recorder (start) failed', [
                'command' => $command,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    public static function finishScheduled(string $command, bool $success): void
    {
        try {
            $run = SeoCommandRun::query()
                ->where('command', $command)
                ->where('trigger_source', SeoCommandRun::SOURCE_SCHEDULE)
                ->where('status', SeoCommandRun::STATUS_RUNNING)
                ->latest('started_at')
                ->first();
            if (! $run) {
                return;
            }

            $run->update([
                'status'      => $success ? SeoCommandRun::STATUS_SUCCESS : SeoCommandRun::STATUS_FAILED,
                'finished_at' => now(),
                'duration_ms' => $run->started_at
                    ? (int) $run->started_at->diffInMilliseconds(now())
                    : null,
                'exit_code'   => $success ? 0 : 1,
            ]);
        } catch (Throwable $e) {
            Log::warning('SEO run recorder (finish) failed', [
                'command' => $command,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
