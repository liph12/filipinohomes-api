<?php

namespace App\Models;

use App\Auditing\LogsActivity;
use App\Natcon\Models\NatconEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * An album (folder) in a photo gallery.
 *
 * Two kinds share the table, told apart by natcon_event_id:
 *   - NULL  → a PUBLIC album, served at /albums/{slug}. Carries a slug.
 *   - set   → a convention album inside that year's /natcon/{year}/gallery.
 *             No slug — the convention page is the only doorway.
 *
 * Albums nest freely via parent_id (NULL = top level) within the same scope,
 * so a photographer's album can hold its own sub-albums and so on. Photos
 * reference an album via gallery_photos.album_id. delete() is a real delete,
 * unlike GalleryPhoto's status flip (an album owns no S3 object) — and the
 * controller refuses it while sub-albums remain inside.
 */
class GalleryAlbum extends Model implements Auditable
{
    use LogsActivity;

    protected $table = 'gallery_albums';

    protected string $auditCategory = 'gallery';

    protected array $auditLabelAttributes = ['name'];

    protected $fillable = [
        'natcon_event_id', 'parent_id', 'slug', 'name', 'sort_order', 'created_by',
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

    /** True for a public (non-convention) album. */
    public function isPublic(): bool
    {
        return $this->natcon_event_id === null;
    }

    /**
     * Albums of one scope: a convention's, or (null) the public gallery's.
     * Every read that lists albums goes through this so a convention album
     * can never leak into /albums and vice versa.
     */
    public function scopeForEvent(Builder $query, ?NatconEvent $event): Builder
    {
        return $event
            ? $query->where('natcon_event_id', $event->id)
            : $query->whereNull('natcon_event_id');
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

    /**
     * Root → parent chain (excluding this album), for public breadcrumbs.
     *
     * @return array<int, self>
     */
    public function ancestors(): array
    {
        $chain = [];
        $node = $this;
        for ($i = 0; $i < 20 && $node->parent; $i++) {
            $node = $node->parent;
            array_unshift($chain, $node);
        }

        return $chain;
    }

    /**
     * A site-wide unique slug for a PUBLIC album's URL. "Awards Night" →
     * awards-night, then awards-night-2, -3 … on collision. Set once at
     * creation and never regenerated on rename — the URL is what search
     * engines and shared links hold on to.
     */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($name, 140, '')) ?: 'album';
        $slug = $base;

        for ($i = 2; $i < 1000; $i++) {
            $taken = static::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();
            if (! $taken) {
                return $slug;
            }
            $slug = $base.'-'.$i;
        }

        return $base.'-'.Str::lower(Str::random(6));
    }
}
