<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One "helpful" vote left by a user on an agent review. The unique
 * constraint on (agent_review_id, user_id) is enforced at the DB
 * level; the controller toggles by deleting existing rows rather
 * than incrementing a counter on the vote row.
 */
class AgentReviewHelpfulVote extends Model
{
    protected $fillable = ['agent_review_id', 'user_id'];

    public function review()
    {
        return $this->belongsTo(AgentReview::class, 'agent_review_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
