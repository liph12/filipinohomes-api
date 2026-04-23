<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamAgent extends Model
{
    use HasFactory;

    protected $table = 'team_agents';

    protected $fillable = [
        'team_id',
        'agent_id',
        'is_leader',
        'status',
    ];

    protected $casts = [
        'team_id' => 'integer',
        'agent_id' => 'integer',
        'is_leader' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }
}
