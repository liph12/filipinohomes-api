<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiSearchLog extends Model
{
    protected $fillable = [
        'user_id',
        'user_info',
        'country',
        'month'
    ];

    protected $casts = [
        'user_info' => 'json',
        'searches' => 'array'
    ];
}
