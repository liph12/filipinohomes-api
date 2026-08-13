<?php

namespace App\Natcon\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per (recipient, kind, calendar day). The UNIQUE index on that triple
 * is the double-send guarantee — the row is claimed BEFORE the send, so a
 * double-clicking admin, a cron double-fire, the frontend axios interceptor's
 * transparent 401 replay, and a drain retry all collapse to exactly one email.
 *
 * A "send batch" is simply the rows sharing a batch_id. There is no batches
 * table: progress is GROUP BY batch_id, and the forensic snapshot of what the
 * admin targeted is written as one audit row instead.
 *
 * ⚠️ Never prune this table. The replay guard on POST /admin/natcon/send-invites
 *    is `where('batch_id', ?)->exists()`, so deleting old rows would let a
 *    replayed request queue a second blast to people who already received one.
 *
 * Deliberately NOT audited: this table's whole job is high-churn status flips,
 * and auditing it would flood the activity feed for no investigative value. The
 * sends themselves are already audited by AuditMailService under 'mailer'.
 */
class Outbox extends Model
{
    protected $table = 'natcon_outbox';

    public const KIND_INVITE   = 'invite';
    public const KIND_REMINDER = 'reminder';
    // Awardee-triggered "send me my link again". Its own kind so it doesn't
    // collide with the daily invite/reminder claim — and so the unique index
    // doubles as a 1-per-day abuse limit on a public endpoint.
    public const KIND_RESEND   = 'resend';

    /** Kinds that render the invite template rather than the reminder one. */
    public const INVITE_LIKE = [self::KIND_INVITE, self::KIND_RESEND];

    public const STATUS_QUEUED    = 'queued';
    public const STATUS_SENT      = 'sent';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'natcon_recipient_id', 'natcon_event_id', 'kind', 'send_date',
        'reminder_index', 'batch_id', 'requested_by', 'status', 'attempts',
        'subject', 'error', 'queued_at', 'sent_at', 'failed_at',
    ];

    protected $casts = [
        'send_date' => 'date',
        'queued_at' => 'datetime',
        'sent_at'   => 'datetime',
        'failed_at' => 'datetime',
        'attempts'  => 'integer',
    ];

    public function recipient()
    {
        return $this->belongsTo(Recipient::class, 'natcon_recipient_id');
    }

    public function event()
    {
        return $this->belongsTo(NatconEvent::class, 'natcon_event_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Progress for one batch: {queued, sent, failed, cancelled, total, status}.
     * Index-only against (batch_id, status).
     */
    public static function batchProgress(string $batchId): array
    {
        $counts = static::where('batch_id', $batchId)
            ->selectRaw('status, COUNT(*) n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $queued = (int) ($counts[self::STATUS_QUEUED] ?? 0);
        $sent   = (int) ($counts[self::STATUS_SENT] ?? 0);
        $failed = (int) ($counts[self::STATUS_FAILED] ?? 0);
        $skip   = (int) ($counts[self::STATUS_CANCELLED] ?? 0);

        return [
            'queued'    => $queued,
            'sent'      => $sent,
            'failed'    => $failed,
            'cancelled' => $skip,
            // "Rows in this batch" — every one of which is reachable, so a
            // progress bar built on sent/total can actually reach 100%.
            'total'     => $queued + $sent + $failed + $skip,
            'status'    => $queued > 0
                ? 'running'
                : ($failed > 0 ? 'completed_with_errors' : 'completed'),
        ];
    }
}
