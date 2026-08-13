<?php

namespace App\Natcon\Console;

use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\Outbox;
use App\Natcon\Models\Recipient;
use App\Natcon\Services\InviteService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Queues reminder emails for awardees who haven't responded.
 *
 * ─── Reminder days are DATA, not a hardcoded list ────────────────────────────
 *
 * natcon_events.reminder_offsets holds days-before-deadline. [4,3,2] against an
 * Aug 24 deadline gives Aug 20 / 21 / 22 — the days the team asked for. Storing
 * offsets rather than dates means moving the deadline moves the reminders with
 * it, and changing the cadence is an admin edit rather than a code change, a PR
 * and a deploy during the exact week nobody has time for one.
 *
 * The command self-checks and no-ops on every other day, so it can be scheduled
 * daily and then forgotten after the event.
 *
 * ⚠️ It does NOT send. It claims outbox rows; natcon:drain-outbox sends them.
 *    That split is what keeps the send paced and resumable.
 */
class QueueReminders extends Command
{
    protected $signature = 'natcon:queue-reminders
                            {--event=   : NatconEvent slug (default: the active event)}
                            {--force    : Queue today regardless of the offset check}
                            {--limit=0  : Cap how many are queued this run (0 = no cap)}
                            {--dry-run  : List who would be queued without writing}';

    protected $description = 'Queue NATCON photo-deadline reminders for awardees who have not responded';

    public function handle(InviteService $invites): int
    {
        $event = $this->option('event')
            ? NatconEvent::where('slug', $this->option('event'))->first()
            : NatconEvent::active();

        if (! $event) {
            // Never fail the cron just because the campaign is over.
            $this->info('No active NATCON event.');
            return self::SUCCESS;
        }

        if (! $event->photo_deadline_at) {
            $this->warn("Event '{$event->slug}' has no photo deadline set — nothing to schedule against.");
            return self::SUCCESS;
        }

        $tz      = $event->timezone ?: 'Asia/Manila';
        $offsets = $event->offsets();
        $offset  = $event->daysUntilDeadline();

        if (! in_array($offset, $offsets, true) && ! $this->option('force')) {
            $this->info("Not a reminder day (offset {$offset}; configured: " . implode(',', $offsets) . ').');
            return self::SUCCESS;
        }

        // Position in the descending offset list, so copy can say "1st / 2nd / 3rd
        // reminder" in chronological order.
        $index  = array_search($offset, $offsets, true);
        $index  = $index === false ? null : ((int) $index + 1);

        $query  = $invites->reminderTargets($event);
        $limit  = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $queued = $skipped = $failed = 0;
        $today  = Carbon::now($tz);

        $query->orderBy('id')->chunkById(100, function ($chunk) use (
            $invites, $today, $index, $dryRun, $limit, &$queued, &$skipped, &$failed
        ) {
            foreach ($chunk as $recipient) {
                if ($limit > 0 && $queued >= $limit) {
                    return false;
                }

                if ($dryRun) {
                    $this->line("  [dry] reminder -> {$recipient->email}");
                    $queued++;
                    continue;
                }

                try {
                    // Returns null when today's reminder is already claimed —
                    // the DB-level guarantee that a double cron fire, a manual
                    // re-run and a --force can't stack three emails on one person.
                    $claim = $invites->claimSend(
                        $recipient,
                        Outbox::KIND_REMINDER,
                        $today,
                        null,
                        $index,
                    );

                    if (! $claim) {
                        $skipped++;
                        continue;
                    }

                    $invites->ensureToken($recipient);
                    $queued++;
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('NATCON reminder queue failed', [
                        'recipient_id' => $recipient->id,
                        'error'        => $e->getMessage(),
                    ]);
                }
            }

            return true;
        });

        $label = $dryRun ? '[dry-run] would queue' : 'queued';
        // $index is null when --force runs on a day that isn't a configured
        // offset; say so rather than printing an empty "reminder #".
        $which = $index ? "reminder #{$index}" : 'forced, off-schedule';

        $this->info(
            "NATCON reminders (offset {$offset}, {$which}) — {$label}: {$queued}, "
            . "already claimed: {$skipped}, errors: {$failed}."
        );

        if (! $dryRun && $queued > 0) {
            $this->line('Run `php artisan natcon:drain-outbox` (or wait for the scheduler) to send them.');
        }

        return self::SUCCESS;
    }
}
