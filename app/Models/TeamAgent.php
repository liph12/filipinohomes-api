<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamAgent extends Model
{
    protected $fillable = ['name', 'team_id', 'agent_id', 'status'];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }
}
