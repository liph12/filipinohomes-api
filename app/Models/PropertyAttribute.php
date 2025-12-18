<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyAttribute extends Model
{
    /** @use HasFactory<\Database\Factories\PropertyAttributeFactory> */
    use HasFactory;
    protected $fillable = [
        'bedroom_count',
        'bathroom_count',
        'garage_count',
        'lot_area',
        'floor_area'
    ];
}
