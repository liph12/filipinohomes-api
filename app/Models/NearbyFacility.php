<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NearbyFacility extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id',
        'school',
        'hospital',
        'clinic',
        'pharmacy',
        'fire_station',
        'police_station',
    ];

    protected $casts = [
        'school'         => 'array',
        'hospital'       => 'array',
        'clinic'         => 'array',
        'pharmacy'       => 'array',
        'fire_station'   => 'array',
        'police_station' => 'array',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }
}
