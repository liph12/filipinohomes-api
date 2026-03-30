<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'key',
        'description',
        'max_ads',
        'width',
        'height',
    ];

    public function placements()
    {
        return $this->hasMany(AdPlacement::class);
    }

    public function ads()
    {
        return $this->belongsToMany(Ad::class, 'ad_placements')
            ->withPivot('priority', 'weight', 'is_fixed')
            ->withTimestamps();
    }

    public function getPageAttribute()
    {
        $parts = explode('.', $this->key);

        return $parts[0];
    }
}
