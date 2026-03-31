<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inquiry extends Model
{
    protected $fillable = [
        'name',
        'email',
        'message',
        'device',
        'country',
        'state',
        'city'
    ];

    protected $casts = [
        'device' => 'json'
    ];
}
