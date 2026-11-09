<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Buyers & Sellers Link per signed-in user (an agent or an admin): the
 * readable address of their two public brief pages (/buy-with/{slug},
 * /sell-with/{slug}) and the internal code the forms post to. Created the
 * first time they open the page. `agent_id` is the owner's agent row when
 * they have one; an admin who is not an agent has only `user_id`.
 */
class BriefLink extends Model
{
    protected $fillable = ['user_id', 'agent_id', 'slug', 'code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    public function briefs()
    {
        return $this->hasMany(ClientBrief::class);
    }
}
