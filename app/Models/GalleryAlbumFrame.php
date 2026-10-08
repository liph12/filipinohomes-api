<?php

namespace App\Models;

use App\Auditing\LogsActivity;
use App\Natcon\Models\NatconEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A decorative frame overlay on a PUBLIC gallery album: a PNG with a
 * transparent photo window, composited client-side over a visitor's chosen
 * photo. window_* are the window's fractions of the frame's own dimensions,
 * auto-detected from the PNG's alpha channel at upload.
 *
 * Like GalleryPhoto, a "deleted" frame is a status flip, never delete() —
 * the row is the only pointer to the S3 object.
 */
class GalleryAlbumFrame extends Model implements Auditable
{
    use LogsActivity;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DELETED = 'deleted';

    protected $table = 'gallery_album_frames';

    protected string $auditCategory = 'gallery';

    protected array $auditLabelAttributes = ['name'];

    protected $fillable = [
        'album_id', 'natcon_event_id', 'award_segments', 'elite_only', 'require_vvip', 'either_vvip', 'vvip_award_segments', 'vvip_elite_only', 'either_elite', 'elite_award_segments', 'audiences', 'vvip', 'vvip_types', 'vvip_category', 'vvip_rank', 'name', 'image_url', 's3_key', 'width', 'height',
        'byte_size', 'window_x', 'window_y', 'window_w', 'window_h',
        'text_x', 'text_y', 'text_w', 'text_h',
        'title_x', 'title_y', 'title_w', 'title_h',
        'sort_order', 'status', 'created_by',
    ];

    protected $casts = [
        // float, NOT decimal:5 — the decimal cast serializes as a STRING,
        // which the frontend's numeric window math would choke on.
        'text_x' => 'float',
        'text_y' => 'float',
        'text_w' => 'float',
        'text_h' => 'float',
        'title_x' => 'float',
        'title_y' => 'float',
        'title_w' => 'float',
        'title_h' => 'float',
        'window_x' => 'float',
        'window_y' => 'float',
        'window_w' => 'float',
        'window_h' => 'float',
        'width' => 'integer',
        'height' => 'integer',
        'byte_size' => 'integer',
        'sort_order' => 'integer',
        'award_segments' => 'array',
        'elite_only' => 'boolean',
        'vvip_rank' => 'integer',
        'vvip' => 'boolean',
        'require_vvip' => 'boolean',
        'either_vvip' => 'boolean',
        'vvip_award_segments' => 'array',
        'vvip_elite_only' => 'boolean',
        'either_elite' => 'boolean',
        'elite_award_segments' => 'array',
        'audiences' => 'array',
        'vvip_types' => 'array',
    ];

    /** Album-level frame (public albums, or a legacy convention album frame). */
    public function album(): BelongsTo
    {
        return $this->belongsTo(GalleryAlbum::class, 'album_id');
    }

    /** Convention-level frame: offered on every photo of that year. */
    public function event(): BelongsTo
    {
        return $this->belongsTo(NatconEvent::class, 'natcon_event_id');
    }

    /** The convention this frame belongs to, whichever way it is attached. */
    public function ownerEvent(): ?NatconEvent
    {
        return $this->natcon_event_id ? $this->event : $this->album?->event;
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
