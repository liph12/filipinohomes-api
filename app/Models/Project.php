<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $table = 'projects';
    /**
     * Allow mass-assignment for the expected project columns
     */
    protected $fillable = [
        'name',
        'prov_id',
        'prop_type_id',
        'city_id',
        'brgy_id',
        'street',
        'mapaddress',
        'latitude',
        'longitude',
        'complete_address',
        'date_added',
        'date_updated',
        'added_by',
        'devid',
        'featured_photo',
        'photos_url',
    ];

    /**
     * Useful attribute casting (arrays, numbers, dates)
     */
    protected $casts = [
        'photos_url'   => 'array',
        'featured_photo'   => 'array',
        'latitude'     => 'float',
        'longitude'    => 'float',
        'date_added'   => 'date',
        'date_updated' => 'date',
        'prov_id'      => 'integer',
        'prop_type_id' => 'integer',
        'city_id'      => 'integer',
        'brgy_id'      => 'integer',
        'added_by'     => 'integer',
    ];

    public $timestamps = false;
}
