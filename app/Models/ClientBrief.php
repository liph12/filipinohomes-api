<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One brief sent through a Buyers & Sellers Link: a buyer's or a seller's
 * (`kind`), with the contact fields in columns and the answers in `profile`
 * as the option keys of config/briefs.php. `user_id` is the link's owner
 * (agent or admin) and `agent_id` their agent row when they have one.
 */
class ClientBrief extends Model
{
    public const KINDS = ['buyer', 'seller'];

    protected $fillable = [
        'brief_link_id', 'user_id', 'agent_id', 'kind', 'name', 'phone_code', 'phone', 'email',
        'contact_time', 'budget', 'message', 'profile', 'ip_address', 'user_agent',
    ];

    protected $casts = ['profile' => 'array'];

    public function link()
    {
        return $this->belongsTo(BriefLink::class, 'brief_link_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }
}
