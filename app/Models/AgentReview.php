<?php

namespace App\Models;

use App\Auditing\LogsActivity;
use App\Services\AgentRatingRollupService;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class AgentReview extends Model implements Auditable
{
    use LogsActivity;

    protected string $auditCategory = 'reviews';
    protected array $auditLabelAttributes = ['overall_rating', 'status'];

    protected $fillable = [
        'agent_user_id',
        'client_user_id',
        'conversation_id',
        'overall_rating',
        'tags',
        'comment',
        'status',
        'hidden_by',
        'hidden_at',
        'hidden_reason',
        'edit_window_ends_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'overall_rating' => 'integer',
        'hidden_at' => 'datetime',
        'edit_window_ends_at' => 'datetime',
    ];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function hiddenByUser()
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }

    public function response()
    {
        return $this->hasOne(AgentReviewResponse::class);
    }

    /**
     * Keep agents.avg_rating + total_reviews in sync. The rollup query
     * is cheap (single grouped aggregate per agent) so we don't defer
     * to a queue.
     */
    protected static function booted(): void
    {
        static::saved(function (self $review) {
            app(AgentRatingRollupService::class)->recomputeFor((int) $review->agent_user_id);
        });
        static::deleted(function (self $review) {
            app(AgentRatingRollupService::class)->recomputeFor((int) $review->agent_user_id);
        });
    }
}
