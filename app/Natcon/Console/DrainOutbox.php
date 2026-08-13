<?php

namespace App\Natcon\Console;

use App\Natcon\Mail\PhotoInviteMailer;
use App\Natcon\Models\Outbox;
use App\Natcon\Models\Recipient;
use App\Natcon\Models\Suppression;
use App\Services\AuditMailService;
use App\Natcon\Services\InviteService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends whatever is sitting in natcon_outbox. This is the ONLY place in the
 * NATCON feature that calls Mail::send.
 *
 * ─── Why a cron drain and not a queue ────────────────────────────────────────
 *
 * Production has no `php artisan queue:work` worker. That isn't a guess — it's
 * documented in app/Mail/MessageNotificationMailer.php, where a previous
 * `implements ShouldQueue` mailer meant every message silently inserted a row
 * into the `jobs` table and no email ever went out. Anything dispatched to the
 * queue here would vanish the same way, and we'd find out days later.
 *
 * Sending inline from the admin request is no better: api2 is behind Cloudflare
 * (~100s origin timeout), so at ~1-3s per SMTP round trip the request 524s
 * somewhere around 40-80 recipients while PHP keeps sending in the background —
 * and the admin, seeing an error, clicks Send again.
 *
 * So the admin's Send button only writes rows, and the scheduler that already
 * runs drains them in small paced batches. No new infrastructure, inherently
 * rate-limited, and resumable: if this dies halfway the next tick continues.
 *
 * Idempotency is not this command's job — natcon_outbox has
 * UNIQUE(recipient, kind, send_date), and the row is claimed before the send.
 */
class DrainOutbox extends Command
{
    protected $signature = 'natcon:drain-outbox
                            {--limit=   : Messages to send this run (default: config natcon.drain_limit)}
                            {--dry-run  : Show what would be sent without sending}';

    protected $description = 'Send queued NATCON invite/reminder emails (paced, resumable)';

    public function handle(InviteService $invites): int
    {
        $mode = (string) config('natcon.send_mode', 'off');

        if (! in_array($mode, ['off', 'whitelist', 'live'], true)) {
            $this->error("Unknown NATCON_SEND_MODE '{$mode}'. Expected off, whitelist or live.");
            return self::FAILURE;
        }

        $limit  = (int) ($this->option('limit') ?: config('natcon.drain_limit', 40));
        $dryRun = (bool) $this->option('dry-run') || $mode === 'off';

        // Rescue rows abandoned by a drain that died mid-send (deploy, OOM,
        // killed process). Without this they'd sit in `sending` forever and the
        // awardee would never be emailed.
        $this->recoverStaleClaims();

        $pending = Outbox::with(['recipient.event'])
            ->where('status', Outbox::STATUS_QUEUED)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        if ($pending->isEmpty()) {
            // Silent on the happy path: this runs every minute, and an "outbox
            // empty" line per minute would bury anything real in the cron log.
            if ($this->getOutput()->isVerbose()) {
                $this->info('NATCON outbox is empty.');
            }
            return self::SUCCESS;
        }

        if ($mode === 'off') {
            $this->warn("NATCON_SEND_MODE=off — {$pending->count()} message(s) queued but nothing will be sent.");
        }

        // One suppression query for the whole batch rather than one per recipient.
        $suppressed = Suppression::lookup(
            $pending->pluck('recipient.email')->filter()->all()
        );

        $sent = $skipped = $failed = 0;

        foreach ($pending as $send) {
            $recipient = $send->recipient;

            $skip = $this->skipReason($send, $recipient, $suppressed);

            if ($skip !== null) {
                $send->forceFill([
                    'status' => Outbox::STATUS_CANCELLED,
                    'error'  => $skip,
                ])->save();

                $this->line("  skip  {$recipient?->email} — {$skip}");
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("  [dry] {$send->kind} -> {$recipient->email}");
                $skipped++;
                continue;
            }

            // ★ Atomically take ownership of this row before touching SMTP.
            //   Returns false if another drain already claimed it.
            if (! $this->claim($send)) {
                $skipped++;
                continue;
            }

            try {
                $mailable = $invites->buildMailable($recipient, $send->kind);

                // whitelist mode redirects everything to the test addresses but
                // keeps the real recipient visible in the subject, so a QA pass
                // can tell whose message is whose.
                //
                // The prefix is a constructor property, NOT $mailable->subject():
                // Mailable::subject() is ignored whenever the class defines
                // envelope(), so setting it that way fails silently.
                if ($mode === 'whitelist') {
                    $to = (array) config('natcon.test_recipients');

                    if (! $to) {
                        $this->error('NATCON_SEND_MODE=whitelist but NATCON_TEST_RECIPIENTS is empty.');
                        return self::FAILURE;
                    }

                    $mailable->subjectPrefix = "[TEST -> {$recipient->email}]";
                    Mail::to($to)->send($mailable);
                } else {
                    Mail::to($recipient->email)->send($mailable);
                }

                // Log the real subject, without the QA prefix.
                $subject = $mailable->envelope()->subject;
                if ($mailable->subjectPrefix) {
                    $subject = trim(str_replace($mailable->subjectPrefix, '', $subject));
                }

                $invites->markSent($send, $subject);
                $this->applySentState($recipient, $send);

                Log::info('NATCON mail sent', [
                    'recipient_id' => $recipient->id,
                    'kind'         => $send->kind,
                    'to'           => $recipient->email,
                    'mode'         => $mode,
                ]);

                $sent++;
            } catch (\Throwable $e) {
                // Laravel has no MessageFailed event, so the failure side is
                // recorded explicitly — matching every other Mail::send call site
                // in this codebase.
                app(AuditMailService::class)->recordFailure(
                    $e,
                    // ⚠️ class_basename, NOT ::class. recordFailure stores this
                    //    raw as `audits.source`, while the success path derives
                    //    the same column from the X-FH-Mailer header — which is
                    //    already class_basename. Passing the FQCN here put sends
                    //    and failures for the same mailer into two different
                    //    filter buckets in the activity log.
                    class_basename(PhotoInviteMailer::class),
                    [$recipient->email],
                    'NATCON ' . $send->kind,
                    // Attributes the audit row to the recipient so the failure is
                    // reachable from their record, not just from the mailer feed.
                    // user_id will be null: this runs on the scheduler, where
                    // there is no authenticated user. That's expected — the admin
                    // who queued the batch is on natcon_outbox.requested_by.
                    [
                        'auditable_type' => Recipient::class,
                        'auditable_id'   => $recipient->id,
                    ],
                );

                $invites->markFailed($send, $e);
                $recipient->forceFill([
                    'send_failures' => $recipient->send_failures + 1,
                    'last_error'    => mb_substr($e->getMessage(), 0, 500),
                ])->save();

                Log::warning('NATCON mail failed', [
                    'recipient_id' => $recipient->id,
                    'kind'         => $send->kind,
                    'attempts'     => $send->fresh()->attempts,
                    'error'        => $e->getMessage(),
                ]);

                $this->error("  fail  {$recipient->email} — {$e->getMessage()}");
                $failed++;
            }
        }


        $this->info("NATCON drain [{$mode}] — sent: {$sent}, skipped: {$skipped}, failed: {$failed}.");

        return self::SUCCESS;
    }

    /**
     * Re-validate at send time, not at queue time. Rows can sit in the outbox for
     * minutes, and the most important case is the third one: somebody who responds
     * after a reminder was queued must not still receive it.
     */
    private function skipReason(Outbox $send, ?Recipient $recipient, array $suppressed): ?string
    {
        if (! $recipient || ! $recipient->email) {
            return 'recipient no longer exists';
        }

        if ($recipient->trashed()) {
            return 'recipient deleted';
        }

        if ($recipient->status === Recipient::STATUS_EXCLUDED) {
            return 'recipient excluded';
        }

        if (! $recipient->event || ! $recipient->event->is_active) {
            return 'event inactive';
        }

        if (isset($suppressed[$recipient->email])) {
            return 'address suppressed (bounce/complaint/unsubscribe)';
        }

        if ($send->kind === Outbox::KIND_REMINDER && $recipient->responded_at) {
            return 'already responded';
        }

        return null;
    }

    private function applySentState(Recipient $recipient, Outbox $send): void
    {
        if ($send->kind === Outbox::KIND_REMINDER) {
            $recipient->forceFill([
                'status'           => Recipient::STATUS_REMINDED,
                'last_reminded_at' => Carbon::now(),
                'reminders_sent'   => $recipient->reminders_sent + 1,
            ])->save();

            return;
        }

        // invite / resend. Never downgrade someone who has already responded —
        // a resend is just them asking for the link again.
        if (! $recipient->responded_at) {
            $recipient->forceFill([
                'status'     => Recipient::STATUS_INVITED,
                'invited_at' => $recipient->invited_at ?? Carbon::now(),
            ])->save();
        }
    }

    /**
     * Take exclusive ownership of one outbox row.
     *
     * ─── Why this exists, given ->withoutOverlapping() ──────────────────────
     * The schedule already declares withoutOverlapping(), but that lock lives in
     * the cache store — and on api2 the cache driver is `file`, whose lock files
     * have silently become unwritable before (root-owned cache dirs from an
     * artisan run that wasn't `sudo -u www-data`). When that happens
     * withoutOverlapping degrades to a no-op with no error, two drains overlap,
     * both SELECT the same `queued` rows, and both send. Every minute for twelve
     * days, to real awardees.
     *
     * This is a conditional UPDATE, so MySQL's row lock decides the winner:
     * exactly one caller sees 1 affected row. It holds whether or not the cache
     * lock worked, which is the point — the guarantee stops depending on
     * filesystem permissions.
     *
     * Note the outbox UNIQUE index does NOT cover this. That prevents a row
     * being CLAIMED twice; this prevents an already-claimed row being SENT twice.
     */
    private function claim(Outbox $send): bool
    {
        $claimed = Outbox::where('id', $send->id)
            ->where('status', Outbox::STATUS_QUEUED)
            ->update([
                'status'     => Outbox::STATUS_SENDING,
                'attempts'   => $send->attempts + 1,
                'updated_at' => Carbon::now(),
            ]);

        if ($claimed === 1) {
            $send->status   = Outbox::STATUS_SENDING;
            $send->attempts = $send->attempts + 1;

            return true;
        }

        return false;
    }

    /**
     * Return rows abandoned mid-send to the queue.
     *
     * A drain killed between claim and send (deploy, OOM, cron kill) leaves its
     * row in `sending`. Without recovery that awardee is never emailed and
     * nothing reports it — the silent failure mode this whole feature is built
     * to avoid. `attempts` was already incremented at claim time, so a row that
     * keeps dying still exhausts its retries rather than looping forever.
     */
    private function recoverStaleClaims(): void
    {
        $cutoff = Carbon::now()->subMinutes(Outbox::SENDING_STALE_MINUTES);

        $recovered = Outbox::where('status', Outbox::STATUS_SENDING)
            ->where('updated_at', '<', $cutoff)
            ->where('attempts', '<', (int) config('natcon.max_attempts', 3))
            ->update(['status' => Outbox::STATUS_QUEUED]);

        if ($recovered > 0) {
            Log::warning('NATCON recovered stale outbox claims', ['count' => $recovered]);
            $this->warn("Recovered {$recovered} message(s) abandoned by an interrupted run.");
        }
    }

    // NOTE: there is deliberately no batch-counter update here any more.
    // Batch progress is derived on read via Outbox::batchProgress(), so
    // this per-minute cron no longer does a SELECT + UPDATE per touched batch —
    // and the queue-time `skipped` count can no longer be destroyed by the first
    // drain tick overwriting it with the drain-time cancelled count.
}
