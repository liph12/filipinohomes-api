<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertySubtype extends Model
{
    protected $fillable = [
        'name',
        'status',
        'property_type_id',
    ];

    public function type()
    {
        return $this->belongsTo(PropertyType::class, 'property_type_id');
    }
}
