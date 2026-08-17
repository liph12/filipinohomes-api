<?php

namespace App\Natcon\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A post on the public NATCON landing page.
 *
 * ⚠️ Keeps the module prefix, unlike every other class in App\Natcon\Models.
 *
 * `App\Models\Announcement` already exists and is a completely different thing —
 * a push-notification broadcast with an audience and a recipients count. Two
 * models called `Announcement` one namespace apart is how the wrong one gets
 * imported in a file that uses both, with PHP raising nothing because both
 * resolve. Same reasoning as NatconEvent keeping its prefix.
 */
class NatconAnnouncement extends Model implements Auditable
{
    use SoftDeletes;
    use LogsActivity;

    protected $table = 'natcon_announcements';

    protected string $auditCategory = 'natcon';
    protected array $auditLabelAttributes = ['title'];

    protected $fillable = [
        'natcon_event_id', 'title', 'body', 'image_url',
        'is_published', 'published_at', 'is_pinned', 'created_by',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'is_pinned'    => 'boolean',
        'published_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(NatconEvent::class, 'natcon_event_id');
    }

    public function author()
    {
        // FQCN: User is not in this namespace, and a bare User::class here
        // resolves to App\Natcon\Models\User and throws at call time.
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /**
     * What the public feed shows: published, in the order the page renders them.
     *
     * A row with is_published true but published_at in the future is scheduled,
     * not live — that distinction is free here and saves a "why did that appear
     * early" conversation later.
     */
    public function scopeLive($query)
    {
        return $query->where('is_published', true)
            ->where(function ($q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }
}
