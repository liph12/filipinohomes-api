<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Administrative boundary polygon (city/municipality or barangay) for the admin
 * map. `geom` is a spatial GEOMETRY column (SRID 0) managed via raw SQL, so it is
 * not in $fillable / not Eloquent-cast.
 */
class Boundary extends Model
{
    protected $fillable = [
        'level',
        'name',
        'parent_name',
        'city_id',
        'barangay_id',
    ];
}
