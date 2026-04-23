<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $fillable = ['name', 'leader_id', 'status', 'logo'];

    public function leader()
    {
        return $this->belongsTo(Agent::class, 'leader_id');
    }

    public function agents()
    {
        return $this->hasMany(TeamAgent::class);
    }
}
