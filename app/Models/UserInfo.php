<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserInfo extends Model
{
    protected $fillable = [
        'user_id',
        'device',
        'country',
        'state',
        'city'
    ];

    protected $casts = [
        'device' => 'json'
    ];
}
