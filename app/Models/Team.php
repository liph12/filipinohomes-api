<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
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
    }
}
