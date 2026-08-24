<?php

namespace App\Natcon\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One event photo in the public landing page's gallery.
 *
 * Belongs to a convention (natcon_event_id) — the gallery is per year. Not to
 * be confused with natcon_photo_submissions: those are awardee headshots and
 * must never reach the public page; these are publication photos an admin
 * chose to show.
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

    // The class name drops the module prefix, so Eloquent would infer `gallery_photos`.
    protected $table = 'natcon_gallery_photos';

    protected string $auditCategory = 'natcon';

    protected array $auditLabelAttributes = ['caption'];

    protected $fillable = [
        'natcon_event_id', 'album_id', 'image_url', 'thumb_url', 's3_key', 'caption',
        'width', 'height', 'byte_size', 'status', 'sort_order', 'created_by',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'byte_size' => 'integer',
        'sort_order' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(NatconEvent::class, 'natcon_event_id');
    }

    /** Secondary album (folder) within the event's gallery; NULL = event root. */
    public function album(): BelongsTo
    {
        return $this->belongsTo(GalleryAlbum::class, 'album_id');
    }

    /** Public-page order: active only, hand-ordered, oldest first on ties. */
    public function scopeLive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
