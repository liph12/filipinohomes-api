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
 * `boundaries:relink-cities` so the heatmap can fetch one province's cities. On
 * a `barangay` row it is the province of the city the row was linked to.
 *
 * `psgc_code` / `parent_psgc` are the source file's PSGC codes (own code, and
 * the code of the town the polygon is in); `grandparent_name` is the province
 * name the source files a barangay's town under; `link_how` records how
 * `boundaries:relink-barangays` placed the row's town. See the 2026_10_02
 * migration for the full semantics.
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
        'grandparent_name',
        'psgc_code',
        'parent_psgc',
        'link_how',
    ];
}
