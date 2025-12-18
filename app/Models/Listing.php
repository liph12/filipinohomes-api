<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Listing extends Model
{
    /** @use HasFactory<\Database\Factories\ListingFactory> */
    use HasFactory;
    protected $fillable = [
        'code',
        'status',
        'name',
        'slug',
        'price',
        'featured_photo',
        'is_featured',
        'clicks',
        'property_id',
        'category_id',
        'agent_id',
    ];

    protected $casts = [
        'price'        => 'decimal:2',
        'is_featured'  => 'boolean',
        'clicks'       => 'integer',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

}
