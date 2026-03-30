<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ad extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'ad_campaign_id',
        'title',
        'image_path',
        'click_url',
        'alt_text',
        'status',
    ];

    public function campaign()
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    public function placements()
    {
        return $this->hasMany(AdPlacement::class);
    }

    public function sections()
    {
        return $this->belongsToMany(AdSection::class, 'ad_placements')
            ->withPivot('priority', 'weight', 'is_fixed')
            ->withTimestamps();
    }

    public function analytics()
    {
        return $this->hasMany(AdAnalytics::class);
    }
}
