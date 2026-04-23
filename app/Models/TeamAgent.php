<?php

namespace App\Models;

<<<<<<< HEAD
use Illuminate\Database\Eloquent\Factories\HasFactory;
=======
>>>>>>> a33c6835224198fc4e2d39a48d09b3db4b377542
use Illuminate\Database\Eloquent\Model;

class TeamAgent extends Model
{
<<<<<<< HEAD
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
=======
    protected $fillable = ['name', 'team_id', 'agent_id', 'status'];

    public function team()
    {
        return $this->belongsTo(Team::class);
>>>>>>> a33c6835224198fc4e2d39a48d09b3db4b377542
    }

    public function agent()
    {
<<<<<<< HEAD
        return $this->belongsTo(Agent::class, 'agent_id');
=======
        return $this->belongsTo(Agent::class);
>>>>>>> a33c6835224198fc4e2d39a48d09b3db4b377542
    }
}
