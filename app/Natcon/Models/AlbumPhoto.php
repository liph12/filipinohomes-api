<?php

namespace App\Natcon\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One photo in an event's face-search ALBUM (/admin/natcon/{slug}).
 *
 * NOT the landing page's GalleryPhoto: that is a hand-curated, captioned,
 * hand-ordered strip of publication photos; this is the full event album —
 * hundreds of shots, bulk-uploaded or imported, indexed into Rekognition so
 * anyone can find the photos they appear in. The two collided on one filename
 * once (both were "the gallery"); they are split on purpose.
 */
class AlbumPhoto extends Model implements Auditable
{
    // The class name drops the module prefix, so Eloquent would otherwise infer
    // `album_photos`. The table keeps the natcon_ prefix because it shares a
    // schema with the rest of the product.
    protected $table = 'natcon_album_photos';

    use LogsActivity;

    protected string $auditCategory = 'natcon';

    protected array $auditLabelAttributes = ['photo_url'];

    protected $fillable = [
        'natcon_event_id', 's3_key', 'photo_url', 'original_filename',
        'byte_size', 'width', 'height',
        'face_ids', 'face_count', 'faces_indexed_at', 'index_error',
        'uploaded_by',
    ];

    protected $casts = [
        'face_ids' => 'array',
        'face_count' => 'integer',
        'faces_indexed_at' => 'datetime',
        'byte_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    // Rekognition face ids are an implementation detail of deletion; no client
    // needs them and serialising them would bloat every album page by ~40
    // UUIDs per group shot.
    protected $hidden = ['face_ids'];

    public function event()
    {
        return $this->belongsTo(NatconEvent::class, 'natcon_event_id');
    }
}
