<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdAnalytics extends Model
{
    protected $fillable = [
        'ad_id',
        'country',
        'state',
        'city',
        'created_hour_at',
        'created_date_at',
        'impressions',
        'clicks',
    ];

    public function ad()
    {
        return $this->belongsTo(Ad::class);
    }
}
