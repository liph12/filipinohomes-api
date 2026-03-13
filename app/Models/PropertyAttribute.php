<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class PropertyAttribute extends Model
{
    use HasFactory;
    protected $fillable = [
        'bedroom_count',
        'bathroom_count',
        'garage_count',
        'lot_area',
        'floor_area',
        'property_subtype_id',
    ];

    protected $casts = [
        'lot_area'          => 'decimal:2',
        'floor_area'         => 'decimal:2',
    ];

    public function subtype()
    {
        return $this->belongsTo(PropertySubtype::class, 'property_subtype_id');
    }
}
