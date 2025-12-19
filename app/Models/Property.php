<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Property extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'address',
        'photos',
        'amenities',
        'description',
        'geo_coordinates',
        'is_project',
        'property_attribute_id',
        'furnishing_id',
    ];

    protected $casts = [
        'photos'     => 'array',
        'amenities'  => 'array',
        'is_project' => 'boolean',
    ];

    public function propertyAttribute()
    {
        return $this->belongsTo(PropertyAttribute::class, 'property_attribute_id');
    }

    public function furnishing()
    {
        return $this->belongsTo(Furnishing::class, 'furnishing_id');
    }
}
