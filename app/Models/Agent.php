<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Agent extends Model
{
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
        'member_since',
        'user_id'
    ];

    protected $casts = [
        'socials' => 'array',
        'avatar' => 'array',
        'geo_location' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function listings()
    {
        return $this->hasMany(Listing::class, 'agent_id');
    }

    public function pageBuilder()
    {
        return $this->hasOne(PageBuilder::class, 'agent_id');
    }

    public function teamMembers()
    {
        return $this->hasMany(TeamAgent::class, 'agent_id');
    }

    public function teamLeader()
    {
        return $this->hasOne(TeamAgent::class, 'agent_id')
            ->where('is_leader', true);
    }
}
