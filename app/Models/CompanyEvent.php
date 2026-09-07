<?php

namespace App\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A company event managed from the dashboard: title, when, where, pictures.
 *
 * Deliberately NOT named Event — NatconEvent owns that word one namespace
 * away, and two same-named models one namespace apart is how the wrong one
 * gets imported (the Announcement/NatconAnnouncement lesson).
 *
 * `status` is the lifecycle: active | deleted. No SoftDeletes — a `deleted`
 * row keeps its photos' s3_keys findable (gallery convention).
 *
 * starts_at/ends_at are stored UTC and edited as wall clocks in `timezone`
 * (see the controller's parsing — the walked-deadline bug, twice).
 */
class CompanyEvent extends Model implements Auditable
{
    use LogsActivity;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DELETED = 'deleted';

    protected $table = 'company_events';

    protected string $auditCategory = 'company_events';

    protected array $auditLabelAttributes = ['title'];

    protected $fillable = [
        'title',
        'description',
        'starts_at',
        'ends_at',
        'timezone',
        'place',
        'status',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function photos(): HasMany
    {
        return $this->hasMany(CompanyEventPhoto::class, 'company_event_id');
    }

    public function livePhotos(): HasMany
    {
        return $this->photos()
            ->where('status', CompanyEventPhoto::STATUS_ACTIVE)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** Upcoming first among the living; the controller orders the rest. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
