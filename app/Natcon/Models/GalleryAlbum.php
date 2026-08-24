<?php

namespace App\Natcon\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * An album (folder) inside one convention's public gallery.
 *
 * The convention IS the primary album; these nest freely under it via
 * parent_id (NULL = top level), so a photographer's album can hold its own
 * sub-albums and so on. Photos reference an album via
 * natcon_gallery_photos.album_id. delete() is a real delete, unlike
 * GalleryPhoto's status flip (an album owns no S3 object) — and the
 * controller refuses it while photos or sub-albums remain inside.
 */
class GalleryAlbum extends Model implements Auditable
{
    use LogsActivity;

    protected $table = 'natcon_gallery_albums';

    protected string $auditCategory = 'natcon';

    protected array $auditLabelAttributes = ['name'];

    protected $fillable = [
        'natcon_event_id', 'parent_id', 'name', 'sort_order', 'created_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(NatconEvent::class, 'natcon_event_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(GalleryPhoto::class, 'album_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * "Parent › Child › …" for pickers and public section headings. Walks up
     * lazily — album trees are tens of rows, not thousands — with a depth cap
     * so a corrupt self-referencing row can never loop for ever.
     */
    public function path(): string
    {
        $parts = [$this->name];
        $node = $this;
        for ($i = 0; $i < 20 && $node->parent; $i++) {
            $node = $node->parent;
            array_unshift($parts, $node->name);
        }

        return implode(' › ', $parts);
    }
}
