<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Auditing\LogsActivity;
use OwenIt\Auditing\Contracts\Auditable;

class Team extends Model implements Auditable
{
    use HasFactory;
    use LogsActivity;

    protected string $auditCategory = 'agents';

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
