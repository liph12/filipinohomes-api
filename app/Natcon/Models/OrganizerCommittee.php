<?php

namespace App\Natcon\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One committee card on the public Organizers chart.
 *
 * Belongs to a convention (natcon_event_id) — the committee is rebuilt every
 * year — and to a phase of the event. Its people are OrganizerMember rows.
 */
class OrganizerCommittee extends Model implements Auditable
{
    use LogsActivity;

    /** The three columns of the chart, in page order. */
    public const PHASES = ['pre_program', 'program_proper', 'post_program'];

    // The class name drops the module prefix, so Eloquent would infer the wrong table.
    protected $table = 'natcon_organizer_committees';

    protected string $auditCategory = 'natcon';

    protected array $auditLabelAttributes = ['title', 'phase'];

    protected $fillable = [
        'natcon_event_id', 'phase', 'title', 'note',
        'sort_order', 'created_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(NatconEvent::class, 'natcon_event_id');
    }

    /** Heads first, then assistants, each hand-ordered. */
    public function members(): HasMany
    {
        return $this->hasMany(OrganizerMember::class, 'committee_id')
            ->orderByRaw("CASE WHEN role = 'head' THEN 0 ELSE 1 END")
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** Public-page order: grouped by phase upstream; hand-ordered, oldest first.
     *  No draft state — a card that exists is a card that shows. */
    public function scopeLive($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
