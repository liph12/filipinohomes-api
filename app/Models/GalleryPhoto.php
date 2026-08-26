<?php

namespace App\Models;

use App\Auditing\LogsActivity;
use App\Natcon\Models\NatconEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One published photo in a gallery.
 *
 * Belongs to a convention (natcon_event_id) when it is part of a year's
 * NATCON gallery, or to no event (NULL) when it sits in a public album
 * (/albums/{slug}). Not to be confused with natcon_photo_submissions: those
 * are awardee headshots and must never reach a public page; these are
 * publication photos an admin chose to show.
 *
 * `status` is the whole lifecycle: active | hidden | deleted. No is_published
 * flag (see the sponsors 000002 migration for why two visibility mechanisms
 * lose) and no SoftDeletes — a `deleted` row keeps s3_key findable, because
 * the row is the only pointer to the S3 object.
 */
class GalleryPhoto extends Model implements Auditable
{
    use LogsActivity;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUS_DELETED = 'deleted';

    protected $table = 'gallery_photos';

    protected string $auditCategory = 'gallery';

    protected array $auditLabelAttributes = ['caption'];

    protected $fillable = [
        'natcon_event_id', 'album_id', 'image_url', 'thumb_url', 's3_key', 'caption',
        'width', 'height', 'byte_size', 'status', 'sort_order', 'created_by',
        'face_ids', 'face_count', 'faces_indexed_at', 'index_error',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'byte_size' => 'integer',
        'sort_order' => 'integer',
        'face_ids' => 'array',
        'face_count' => 'integer',
        'faces_indexed_at' => 'datetime',
    ];

    // Rekognition face ids are an implementation detail of deletion; no client
    // needs them.
    protected $hidden = ['face_ids'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(NatconEvent::class, 'natcon_event_id');
    }

    /** Album (folder) the photo is filed in; NULL = the scope's root (legacy). */
    public function album(): BelongsTo
    {
        return $this->belongsTo(GalleryAlbum::class, 'album_id');
    }

    /** Photos of one scope — a convention's, or (null) the public gallery's. */
    public function scopeForEvent(Builder $query, ?NatconEvent $event): Builder
    {
        return $event
            ? $query->where('natcon_event_id', $event->id)
            : $query->whereNull('natcon_event_id');
    }

    /** Public-page order: active only, hand-ordered, oldest first on ties. */
    public function scopeLive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
