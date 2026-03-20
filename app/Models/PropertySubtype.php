<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertySubtype extends Model
{
    protected $fillable = [
        'name',
        'property_type_id',
    ];

    public $timestamps = false;

    public function delete()
    {
        return false;
    }

    public function forceDelete()
    {
        return false;
    }

    public function type()
    {
        return $this->belongsTo(PropertyType::class, 'property_type_id');
    }

    public function save(array $options = [])
    {
        if ($this->exists) {
            return false;
        }

        return parent::save($options); 
    }

    public function update(array $attributes = [], array $options = [])
    {
        return false; 
    }
}
