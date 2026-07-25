<?php

namespace App\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A curated landmark (mall, hospital, school…) used to generate "near {facility}"
 * programmatic-SEO pages. Coordinates are filled by `facilities:geocode-missing`
 * or inline on create from the admin SEO Manage page.
 *
 * Slug is the stable URL identity — never edited directly. Renames go through
 * FacilityRebrandService, which keeps the slug (or, on a deliberate slug
 * change, records the old one in `former_slugs` so the frontend 301s the old
 * URL). Rows are deactivated, never hard-deleted: once a facility page has
 * been indexed, its slug history must survive for 301 correctness.
 */
class Facility extends Model implements Auditable
{
    use LogsActivity;

    protected string $auditCategory = 'seo';
    protected array $auditLabelAttributes = ['name'];

    protected $fillable = [
        'name',
        'slug',
        'category',
        'lat',
        'lng',
        'city',
        'province',
        'is_active',
        'aliases',
        'former_slugs',
    ];

    protected $casts = [
        'lat'          => 'float',
        'lng'          => 'float',
        'is_active'    => 'boolean',
        'aliases'      => 'array',
        'former_slugs' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** A facility is usable for SEO pages only once it has coordinates. */
    public function scopeGeocoded($query)
    {
        return $query->whereNotNull('lat')->whereNotNull('lng');
    }

    /**
     * Is this slug already claimed — as a CURRENT slug or inside any row's
     * former_slugs history? A new/renamed facility must never take a slug
     * that 301-owns another facility's past URL.
     */
    public static function slugInUse(string $slug, ?int $ignoreId = null): bool
    {
        return static::query()
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(function ($q) use ($slug) {
                $q->where('slug', $slug)
                    ->orWhereJsonContains('former_slugs', $slug);
            })
            ->exists();
    }
}
