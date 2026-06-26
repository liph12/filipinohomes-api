<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A curated landmark (mall, hospital, school…) used to generate "near {facility}"
 * programmatic-SEO pages. Coordinates are filled by `facilities:geocode-missing`.
 */
class Facility extends Model
{
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
}
