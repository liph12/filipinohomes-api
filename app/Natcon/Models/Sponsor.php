<?php

namespace App\Natcon\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One sponsor logo on the public landing page.
 *
 * Belongs to a convention (natcon_event_id) — sponsorship is per year — and to
 * a tier: 'major', 'minor' or 'star' (star benefactors). The image is uploaded
 * through the shared /upload route into S3, same as announcement art.
 */
class Sponsor extends Model implements Auditable
{
    use LogsActivity;

    /** Tiers shown on the public page. */
    public const TIERS = ['copresentor', 'major', 'minor', 'star'];

    /**
     * All storable tiers. 'library' is the admin's upload pool — logos land
     * there once and get copied into a public tier on assignment, so it must
     * never be served by the public endpoint.
     */
    public const ALL_TIERS = ['copresentor', 'major', 'minor', 'star', 'library'];

    // The class name drops the module prefix, so Eloquent would infer `sponsors`.
    protected $table = 'natcon_sponsors';

    protected string $auditCategory = 'natcon';

    protected array $auditLabelAttributes = ['name', 'tier'];

    protected $fillable = [
        'natcon_event_id', 'tier', 'name', 'image_url',
        'sort_order', 'created_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(NatconEvent::class, 'natcon_event_id');
    }

    /** Public-page order: grouped by tier upstream; hand-ordered, oldest first.
     *  No draft state — being in a public tier IS being published. */
    public function scopeLive($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
