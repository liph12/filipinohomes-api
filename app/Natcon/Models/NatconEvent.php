<?php

namespace App\Natcon\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One convention year. NATCON 2026, 2027, 2028 … each get a row, and every
 * other natcon_* table hangs off natcon_event_id — so a finished year stays
 * intact as an archive while the next one runs.
 *
 * Everything the emails and the public page say about "this year" lives here as
 * a column (short_name, hashtag, banner, thank-you copy), not as a literal in
 * PHP or the JS bundle. Rolling over to a new year is data entry.
 */
class NatconEvent extends Model implements Auditable
{
    use LogsActivity;

    protected string $auditCategory = 'natcon';

    protected array $auditLabelAttributes = ['name'];

    protected $fillable = [
        'slug', 'year', 'name', 'short_name', 'starts_on', 'ends_on', 'venue',
        'hashtag', 'photo_deadline_at', 'timezone', 'update_profile_url',
        'banner_base', 'email_banner_url', 'sponsor_display', 'thank_you_message',
        'reminder_offsets', 'is_active', 'sales_breakpoint',
        'reactions_enabled',
    ];

    protected $casts = [
        'year' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'photo_deadline_at' => 'datetime',
        'reminder_offsets' => 'array',
        'sponsor_display' => 'array',
        'is_active' => 'boolean',
        'reactions_enabled' => 'boolean',
        'sales_breakpoint' => 'decimal:2',
    ];

    public function recipients()
    {
        return $this->hasMany(Recipient::class, 'natcon_event_id');
    }

    public function formFields()
    {
        return $this->hasMany(FormField::class, 'natcon_event_id');
    }

    /**
     * The convention currently being run.
     *
     * ⚠️ Ordered newest-first, and that is load-bearing. An earlier version used
     *    orderBy('id') — the OLDEST active event — so seeding 2027 before
     *    remembering to deactivate 2026 would have left every caller silently
     *    serving 2026 while the admin list (which orders desc) showed 2027.
     *    is_active has no partial-unique constraint, so overlap is possible and
     *    the tie-break has to be deterministic.
     */
    public static function active(): ?self
    {
        return static::where('is_active', true)
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
    }

    public static function forYear(int $year): ?self
    {
        return static::where('year', $year)->first();
    }

    /**
     * Reminder offsets with a sane default, sorted furthest-out first so the
     * reminder index reads 1, 2, 3 in chronological order.
     */
    public function offsets(): array
    {
        $offsets = array_values(array_filter(
            array_map('intval', $this->reminder_offsets ?? []),
            fn ($n) => $n >= 0,
        ));

        if (! $offsets) {
            $offsets = [4, 3, 2];
        }

        rsort($offsets);

        return $offsets;
    }

    /**
     * The sales figure that divides the invite waves.
     *
     * The event's own value wins; config is only the fallback for an event
     * nobody has set one on yet. Returned as a float because every comparison
     * against total_sales is numeric — passing the decimal cast's string
     * straight into a where() works by coercion and reads like a bug.
     */
    public function salesBreakpoint(): float
    {
        return (float) ($this->sales_breakpoint ?: config('natcon.sales_breakpoint', 61000000));
    }

    /** Deadline rendered in the event's own timezone, for display and date math. */
    public function deadlineLocal(): ?Carbon
    {
        return $this->photo_deadline_at?->copy()->setTimezone($this->timezone ?: 'Asia/Manila');
    }

    /**
     * Whole days from today until the deadline, in the event's timezone.
     * Negative once past. Both sides are floored to midnight so the answer is a
     * calendar-day count, which is what the email copy and the offsets mean.
     */
    public function daysUntilDeadline(?Carbon $from = null): ?int
    {
        $deadline = $this->deadlineLocal();
        if (! $deadline) {
            return null;
        }

        $tz = $this->timezone ?: 'Asia/Manila';
        $today = ($from ? $from->copy()->setTimezone($tz) : Carbon::now($tz))->startOfDay();

        return (int) $today->diffInDays($deadline->copy()->startOfDay(), false);
    }

    /** True once the photo-collection window has closed. */
    public function isPhotoWindowClosed(): bool
    {
        return $this->photo_deadline_at !== null
            && Carbon::now()->greaterThan($this->photo_deadline_at);
    }

    /** e.g. "October 18–19, 2026" — one date when the event is a single day. */
    public function dateLabel(): string
    {
        if (! $this->starts_on) {
            return '';
        }

        if (! $this->ends_on || $this->starts_on->isSameDay($this->ends_on)) {
            return $this->starts_on->format('F j, Y');
        }

        if ($this->starts_on->isSameMonth($this->ends_on)) {
            return $this->starts_on->format('F j').'–'.$this->ends_on->format('j, Y');
        }

        return $this->starts_on->format('F j').' – '.$this->ends_on->format('F j, Y');
    }

    public function displayShortName(): string
    {
        return $this->short_name ?: ('NATCON '.$this->year);
    }

    public function thankYou(): string
    {
        return $this->thank_you_message
            ?: "Thank you very much for your cooperation, see you at {$this->displayShortName()}";
    }

    /**
     * Where this year's uploaded photos land. Derived from the slug rather than
     * configured, so a new year cannot accidentally inherit the previous year's
     * S3 folder — which is exactly what a config default did before.
     *
     * One method for every per-year S3 folder — awardee headshots ('photos'),
     * the landing gallery ('gallery') — so no upload path can inherit another
     * year's folder by building its own prefix. Defaulted so PhotoService's
     * bare call keeps meaning what it always did.
     */
    public function s3Prefix(string $kind = 'photos'): string
    {
        return 'filipinohomes-new/' . $this->slug . '/' . $kind;
    }

    /**
     * Where this year's face-search ALBUM photos land — the full event album
     * the selfie search runs over, distinct from the curated landing gallery
     * (s3Prefix('gallery')) and from awardee headshots (s3Prefix()). Like
     * both, derived from the slug so a new year cannot inherit the previous
     * year's folder.
     */
    public function albumS3Prefix(): string
    {
        return 'natcon/' . $this->slug;
    }

    /**
     * The Rekognition face collection holding this year's album vectors.
     * Collection ids are account-global, hence the namespace; the slug keeps
     * each convention's album searchable — and deletable — on its own.
     * ⚠️ The 'fh-gallery-' literal predates the gallery/album split and is
     * kept because live collections (with indexed vectors) already use it.
     */
    public function albumCollectionId(): string
    {
        return 'fh-gallery-' . $this->slug;
    }
}
