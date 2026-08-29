<?php

namespace App\Natcon\Console;

use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\Recipient;
use App\Natcon\Services\FormService;
use App\Natcon\Services\PhotoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recompute completion for awardees whose recorded state contradicts itself.
 *
 * `syncResponseState()` only runs when somebody acts — a photo lands, a set is
 * kept, the form is submitted. So a row that went wrong stays wrong until that
 * person touches their page again, and near the deadline most of them never
 * will. This is the only way to apply a fix to rows already sitting in the
 * table.
 *
 * ─── What went wrong ────────────────────────────────────────────────────────
 *
 * A retain that owned no submission row used to hit a branch that set `status`
 * and nothing else. It froze the row at status=responded_retain with
 * responded_at NULL, and it intercepted before the arm that would have cleared
 * the stale `response`. The admin list showed a green "Retained" badge on
 * somebody the counts filed under "Sent, but not submitted", and no amount of
 * further activity could move them.
 *
 * ⚠️ This command must never write `response` / `responded_at` / `status`
 *    itself. `PhotoService::syncResponseState()` is their single owner (see
 *    app/Natcon/CLAUDE.md §3) — two writers is how the original bug got in.
 *    Everything here either calls it or reports on it.
 *
 * Idempotent: a second run reports every row unchanged.
 */
class RepairResponseState extends Command
{
    protected $signature = 'natcon:repair-response-state
                            {--event=     : NatconEvent slug (default: the active event)}
                            {--limit=0    : Cap the rows examined (0 = no cap)}
                            {--dry-run    : Show what would change, write nothing}';

    protected $description = 'Recompute NATCON completion for awardees whose recorded response state contradicts itself';

    public function handle(PhotoService $photos, FormService $forms): int
    {
        $event = $this->resolveEvent();

        if (! $event) {
            $this->info('No active NATCON event — nothing to repair.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit  = max(0, (int) $this->option('limit'));

        /**
         * Everyone who is NOT marked finished but carries a retain of some
         * kind. Deliberately wider than the exact broken shape: the point of a
         * repair pass is to let syncResponseState() re-decide, and a row that
         * is already right costs one recompute and reports "unchanged".
         */
        $query = Recipient::query()
            ->where('natcon_event_id', $event->id)
            ->whereNull('responded_at')
            ->where(fn ($q) => $q
                ->where('response', Recipient::RESPONSE_RETAIN)
                ->orWhere('status', Recipient::STATUS_RESPONDED_RETAIN));

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Nothing to repair — no unfinished retains on this event.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Examining {$total} awardee(s) on {$event->name}.");
        $this->newLine();

        $completed = $needDetails = $reopened = $unchanged = $errored = 0;
        $seen      = 0;

        $query->orderBy('id')->chunkById(100, function ($chunk) use (
            $photos, $forms, $dryRun, $limit,
            &$completed, &$needDetails, &$reopened, &$unchanged, &$errored, &$seen
        ) {
            foreach ($chunk as $recipient) {
                if ($limit > 0 && $seen >= $limit) {
                    return false;
                }

                $seen++;

                try {
                    $before = [
                        'status'       => $recipient->status,
                        'response'     => $recipient->response,
                        'responded_at' => $recipient->responded_at?->toIso8601String(),
                    ];

                    // The three inputs the decision is made from, printed so a
                    // dry run explains ITSELF rather than just naming an outcome.
                    $photoCount = $recipient->activePhotos()->count();
                    $printable  = $recipient->finalPhotoUrl() !== null;
                    $answers    = $forms->hasRequiredAnswers($recipient);

                    /**
                     * The preview runs the REAL rule and rolls it back, rather
                     * than reimplementing the decision here. A second copy of
                     * "what counts as complete" is exactly the kind of drift
                     * that produced the bug this command exists to repair — and
                     * a dry run that predicts something the live path would not
                     * do is worse than no dry run at all.
                     */
                    if ($dryRun) {
                        DB::beginTransaction();
                    }

                    try {
                        $photos->syncResponseState($recipient);
                        $recipient->refresh();

                        $after = [
                            'status'       => $recipient->status,
                            'response'     => $recipient->response,
                            'responded_at' => $recipient->responded_at?->toIso8601String(),
                        ];
                    } finally {
                        if ($dryRun) {
                            DB::rollBack();
                        }
                    }

                    if ($after === $before) {
                        $unchanged++;

                        continue;
                    }

                    $this->line(sprintf(
                        '  %-38s %-16s photos:%d printable:%s answers:%s  =>  %s%s',
                        mb_strimwidth($recipient->email, 0, 38, '…'),
                        $before['status'],
                        $photoCount,
                        $printable ? 'y' : 'n',
                        $answers ? 'y' : 'n',
                        $after['status'],
                        $after['responded_at'] ? ' (submitted)' : '',
                    ));

                    match (true) {
                        $after['responded_at'] !== null => $completed++,
                        $after['status'] === Recipient::STATUS_DETAILS_PENDING => $needDetails++,
                        default => $reopened++,
                    };
                } catch (\Throwable $e) {
                    $errored++;
                    Log::warning('NATCON response-state repair failed', [
                        'recipient_id' => $recipient->id,
                        'error'        => $e->getMessage(),
                    ]);
                    $this->warn("  {$recipient->email}: {$e->getMessage()}");
                }
            }

            return true;
        });

        $this->newLine();
        $label = $dryRun ? '[dry-run] would be' : 'now';
        $this->info(
            "NATCON response repair — {$label} submitted: {$completed}, "
            . "needing details: {$needDetails}, reopened: {$reopened}, "
            . "unchanged: {$unchanged}, errors: {$errored}."
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn('Nothing was written. Re-run without --dry-run to apply.');
            $this->line('Note: "needing details" is a REMINDABLE status — those awardees will be chased for the answer they still owe.');
        }

        if ($errored > 0) {
            $this->warn("{$errored} row(s) errored and were left untouched.");
        }

        return self::SUCCESS;
    }

    private function resolveEvent(): ?NatconEvent
    {
        $slug = $this->option('event');

        return $slug
            ? NatconEvent::where('slug', $slug)->first()
            : NatconEvent::active();
    }
}
