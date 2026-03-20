<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PropertyType extends Model
{
    protected $fillable = [
        'name',
    ];

    public function delete()
    {
        return false;
    }

    public function forceDelete()
    {
        return false;
    }

    public function subTypes()
    {
        return $this->hasMany(PropertySubtype::class, 'property_type_id');
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
