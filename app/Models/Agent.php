<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    /** @use HasFactory<\Database\Factories\AgentFactory> */
    use HasFactory;

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'mobile_no',
        'whats_app_no',
        'address',
        'socials',
        "bio",
        'avatar',
        'geo_location',
        'user_id'
    ];

    protected $casts = [
        'socials' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(Agent::class, 'user_id');
    }
}
