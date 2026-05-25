<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentReviewResponse extends Model
{
    protected $fillable = ['agent_review_id', 'agent_user_id', 'body'];

    public function review()
    {
        return $this->belongsTo(AgentReview::class, 'agent_review_id');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }
}
