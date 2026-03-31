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

    public function scopeGetAnalytics($q, $groupBy = 'hour', $start = null, $end = null)
    {
        switch ($groupBy) {
            case 'hour':
                $format = '%Y-%m-%d %h:00 %p';
                break;
            case 'day':
                $format = '%Y-%m-%d';
                break;
            case 'week':
                $format = '%Y-%u';
                break;
            case 'month':
                $format = '%Y-%m';
                break;
            default:
                $format = '%Y-%m-%d %h:00 %p';
        }
    
        return $q->with(['analytics' => function ($query) use ($format, $start, $end) {
    
            if ($start && $end) {
                $query->whereBetween('created_at', [$start, $end]);
            }
    
            $query->selectRaw("
                ad_id,
                country,
                state,
                city,
                DATE_FORMAT(created_at, '{$format}') as period,
                SUM(impressions) as impressions,
                SUM(total_impressions) as total_impressions,
                SUM(clicks) as clicks,
                SUM(total_clicks) as total_clicks
            ")
            ->groupBy('ad_id', 'country', 'state', 'city', 'period')
            ->orderBy('period', 'asc');
        },'campaign:id,name,advertiser']);
    }
}
