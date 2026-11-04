<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One brief sent through an agent's Buyers & Sellers Link: a buyer's or a
 * seller's (`kind`), with the contact fields in columns and the answers in
 * `profile` as the option keys of config/briefs.php.
 */
class ClientBrief extends Model
{
    public const KINDS = ['buyer', 'seller'];

    protected $fillable = [
        'brief_link_id', 'agent_id', 'kind', 'name', 'phone_code', 'phone', 'email',
        'contact_time', 'budget', 'message', 'profile', 'ip_address', 'user_agent',
    ];

    protected $casts = ['profile' => 'array'];

    public function link()
    {
        return $this->belongsTo(BriefLink::class, 'brief_link_id');
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }
}
