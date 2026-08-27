<?php

namespace App\Models;

use App\Auditing\LogsActivity;
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
        'album_id', 'name', 'image_url', 's3_key', 'width', 'height',
        'byte_size', 'window_x', 'window_y', 'window_w', 'window_h',
        'sort_order', 'status', 'created_by',
    ];

    protected $casts = [
        // float, NOT decimal:5 — the decimal cast serializes as a STRING,
        // which the frontend's numeric window math would choke on.
        'window_x' => 'float',
        'window_y' => 'float',
        'window_w' => 'float',
        'window_h' => 'float',
        'width' => 'integer',
        'height' => 'integer',
        'byte_size' => 'integer',
        'sort_order' => 'integer',
    ];

    public function album(): BelongsTo
    {
        return $this->belongsTo(GalleryAlbum::class, 'album_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
