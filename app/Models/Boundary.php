<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Administrative boundary polygon (city/municipality or barangay) for the admin
 * map, plus province polygons for the listings heatmap. `geom` is a spatial
 * GEOMETRY column (SRID 0) managed via raw SQL, so it is not in $fillable / not
 * Eloquent-cast.
 *
 * `province_id` means two different things by level, both intentional: on a
 * `province` row it is the canonical province the polygon IS; on a `city` row it
 * is the province the polygon geometrically falls inside, written by
 * `boundaries:relink-cities` so the heatmap can fetch one province's cities.
 */
class Boundary extends Model
{
    protected $fillable = [
        'level',
        'name',
        'parent_name',
        'city_id',
        'province_id',
        'barangay_id',
    ];
}
