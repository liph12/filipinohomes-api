<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An agent's one Buyers & Sellers Link: the readable address of their two
 * public brief pages (/buy-with/{slug}, /sell-with/{slug}) and the internal
 * code the forms post to. Created the first time the agent opens the page.
 */
class BriefLink extends Model
{
    protected $fillable = ['agent_id', 'slug', 'code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    public function briefs()
    {
        return $this->hasMany(ClientBrief::class);
    }
}
