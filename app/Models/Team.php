<?php

namespace App\Models;

<<<<<<< HEAD
use Illuminate\Database\Eloquent\Factories\HasFactory;
=======
>>>>>>> a33c6835224198fc4e2d39a48d09b3db4b377542
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
<<<<<<< HEAD
    use HasFactory;

    protected $fillable = [
        'name',
        'status',
        'logo',
    ];

    public function leader()
    {
        return $this->hasOne(TeamAgent::class, 'team_id')
            ->where('is_leader', true);
    }

    public function teamAgents()
    {
        return $this->hasMany(TeamAgent::class, 'team_id');
=======
    protected $fillable = ['name', 'leader_id', 'status', 'logo'];

    public function leader()
    {
        return $this->belongsTo(Agent::class, 'leader_id');
    }

    public function agents()
    {
        return $this->hasMany(TeamAgent::class);
>>>>>>> a33c6835224198fc4e2d39a48d09b3db4b377542
    }
}
