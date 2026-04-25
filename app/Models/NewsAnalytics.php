<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsAnalytics extends Model
{
    protected $table = 'news_analytics';

    protected $fillable = [
        'identifier',
        'impressions',
        'clicks',
    ];
}

