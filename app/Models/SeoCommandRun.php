<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One execution of an SEO pipeline command (see SeoCommandRegistry), whether
 * triggered manually from the admin SEO Manage page or by the nightly
 * scheduler. Deliberately NOT audited via LogsActivity — status flips would
 * spam the activity log; the human trigger action is audited once by
 * AuditSeoService instead.
 */
class SeoCommandRun extends Model
{
    public const STATUS_QUEUED  = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
    // A `running` row whose worker died without finishing. Swept by
    // RunSeoCommand::sweepStale() (opportunistic, no extra cron).
    public const STATUS_STALE   = 'stale';

    public const SOURCE_MANUAL   = 'manual';
    public const SOURCE_SCHEDULE = 'schedule';

    /**
     * A `running` row older than this is presumed crashed → stale. A `queued`
     * row older than this is presumed lost (worker down / job dropped with
     * $tries=1) — both age out of scopeActive so a bookkeeping orphan can
     * never permanently 409 manual triggers or ->skip() the nightly schedule.
     */
    public const STALE_AFTER_HOURS = 2;

    protected $fillable = [
        'command',
        'status',
        'trigger_source',
        'triggered_by',
        'queued_at',
        'started_at',
        'finished_at',
        'duration_ms',
        'exit_code',
        'output',
        'error',
    ];

    protected $casts = [
        'queued_at'   => 'datetime',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Named so Eloquent serializes it as `triggered_by_user` — NOT
     * `triggeredBy`, whose snake-cased key would collide with (and clobber)
     * the `triggered_by` FK attribute in the JSON payload.
     */
    public function triggeredByUser()
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * Queued or running, and not yet presumed lost/crashed. BOTH branches are
     * age-bounded: an orphaned row (worker SIGKILLed, job dropped) must age
     * out on its own, because this scope gates the manual-trigger 409 AND the
     * scheduler's ->skip() — an unbounded branch would deadlock the command
     * forever with no code path left to clear it.
     */
    public function scopeActive($query)
    {
        $cutoff = now()->subHours(self::STALE_AFTER_HOURS);

        return $query->where(function ($q) use ($cutoff) {
            $q->where(function ($q) use ($cutoff) {
                $q->where('status', self::STATUS_QUEUED)
                    ->where('queued_at', '>=', $cutoff);
            })->orWhere(function ($q) use ($cutoff) {
                $q->where('status', self::STATUS_RUNNING)
                    ->where('started_at', '>=', $cutoff);
            });
        });
    }

    public static function hasActiveRun(string $command): bool
    {
        return static::query()->where('command', $command)->active()->exists();
    }
}
