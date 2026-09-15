<?php

namespace App\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
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
 *
 * Each event has a PUBLIC PAGE at /events/{slug} with a registration form.
 * The slug is minted once from the title and never regenerated — links get
 * shared. `is_public` takes the page down without deleting the event;
 * `registration_open` closes the form; `capacity` (optional) caps sign-ups.
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
        'slug',
        'description',
        'starts_at',
        'ends_at',
        'timezone',
        'place',
        'status',
        'is_public',
        'registration_open',
        'capacity',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_public' => 'boolean',
        'registration_open' => 'boolean',
        'capacity' => 'integer',
    ];

    protected static function booted(): void
    {
        // Slug once, from the title, uniqued — and only when empty, so an
        // edited title never changes a link that is already out there.
        static::creating(function (CompanyEvent $event) {
            if ($event->slug) {
                return;
            }
            $base = Str::slug((string) $event->title) ?: 'event-'.Str::lower(Str::random(6));
            $slug = $base;
            $n = 2;
            while (static::where('slug', $slug)->exists()) {
                $slug = $base.'-'.$n++;
            }
            $event->slug = $slug;
        });
    }

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

    public function registrations(): HasMany
    {
        return $this->hasMany(CompanyEventRegistration::class, 'company_event_id');
    }

    /** Upcoming first among the living; the controller orders the rest. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** What the public site may show: live AND published. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->live()->where('is_public', true);
    }

    /** Over once it (or its end, when it has one) is behind us. */
    public function isPast(): bool
    {
        $anchor = $this->ends_at ?? $this->starts_at;

        return $anchor !== null && $anchor->isPast();
    }
}
