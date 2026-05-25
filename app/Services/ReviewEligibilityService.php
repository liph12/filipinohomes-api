<?php

namespace App\Services;

use App\Models\AgentReview;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "can this client rate this agent on this
 * conversation right now?". Both the frontend's eligibility probe and
 * the AgentReviewController::store guard call this so the answer is
 * always consistent.
 *
 * Rules (return ['eligible' => false, 'reason' => ...] on first miss):
 *   - Conversation must have an assigned agent.
 *   - Caller must own the chat row that wraps the conversation (the
 *     reviewer is always the inquiring client, never the agent).
 *   - Self-review is rejected (client_user_id !== agent_user_id).
 *   - Engagement gate satisfied: status === 'closed' OR
 *     (client_messages >= 3 AND agent_messages >= 1 AND last_message
 *      is >= 48 hours old).
 *
 * Returns existing_review_id (if any) so the frontend can switch the
 * inline card between Add-mode and Edit-mode without a second roundtrip.
 *
 * already_shown reports whether the rate prompt has been rendered for
 * this client on this conversation before (drives "card is dismissed"
 * UI on the client side; not enforced server-side — re-submitting via
 * the dialog is always allowed if the underlying gate is satisfied).
 */
class ReviewEligibilityService
{
    private const ENGAGEMENT_CLIENT_MIN = 3;
    private const ENGAGEMENT_AGENT_MIN = 1;
    private const ENGAGEMENT_QUIET_MINUTES = 48 * 60;

    /**
     * @return array{eligible:bool, reason:?string, existing_review_id:?int, already_shown:bool}
     */
    public function check(int $clientUserId, Conversation $conv): array
    {
        $conv->loadMissing('chat');

        if (!$conv->agent_user_id) {
            return $this->fail('Conversation has no assigned agent.', null, false);
        }

        if ((int) $conv->chat?->user_id !== $clientUserId) {
            return $this->fail('Only the inquiring client can rate this agent.', null, false);
        }

        if ($clientUserId === (int) $conv->agent_user_id) {
            return $this->fail('You cannot rate yourself.', null, false);
        }

        $alreadyShown = $this->alreadyShown($clientUserId, (int) $conv->id);
        $existingReviewId = AgentReview::where('client_user_id', $clientUserId)
            ->where('agent_user_id', $conv->agent_user_id)
            ->value('id');

        if ($conv->status === 'closed') {
            return [
                'eligible' => true,
                'reason' => null,
                'existing_review_id' => $existingReviewId ? (int) $existingReviewId : null,
                'already_shown' => $alreadyShown,
            ];
        }

        // Count messages by author + locate the most recent timestamp.
        // One grouped query keeps this cheap; the conversation_id index
        // backs the lookup.
        $counts = Message::query()
            ->where('conversation_id', $conv->id)
            ->whereNotIn('status', ['deleted', 'unsent'])
            ->selectRaw('
                SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END) as client_msgs,
                SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END) as agent_msgs,
                MAX(created_at) as last_at
            ', [$clientUserId, $conv->agent_user_id])
            ->first();

        $clientMsgs = (int) ($counts->client_msgs ?? 0);
        $agentMsgs = (int) ($counts->agent_msgs ?? 0);
        $lastAt = $counts->last_at ? \Carbon\Carbon::parse($counts->last_at) : null;

        if ($clientMsgs < self::ENGAGEMENT_CLIENT_MIN) {
            return $this->fail('Send a few more messages before rating.', $existingReviewId, $alreadyShown);
        }
        if ($agentMsgs < self::ENGAGEMENT_AGENT_MIN) {
            return $this->fail('Wait for the agent to reply before rating.', $existingReviewId, $alreadyShown);
        }
        if (!$lastAt || $lastAt->diffInMinutes(now()) < self::ENGAGEMENT_QUIET_MINUTES) {
            return $this->fail('Conversation is still active — rate after 48 hours of quiet.', $existingReviewId, $alreadyShown);
        }

        return [
            'eligible' => true,
            'reason' => null,
            'existing_review_id' => $existingReviewId ? (int) $existingReviewId : null,
            'already_shown' => $alreadyShown,
        ];
    }

    private function alreadyShown(int $userId, int $conversationId): bool
    {
        return DB::table('conversation_users')
            ->where('user_id', $userId)
            ->where('conversation_id', $conversationId)
            ->whereNotNull('rate_prompt_shown_at')
            ->exists();
    }

    /**
     * @return array{eligible:bool, reason:string, existing_review_id:?int, already_shown:bool}
     */
    private function fail(string $reason, ?int $existingReviewId, bool $alreadyShown): array
    {
        return [
            'eligible' => false,
            'reason' => $reason,
            'existing_review_id' => $existingReviewId ? (int) $existingReviewId : null,
            'already_shown' => $alreadyShown,
        ];
    }
}
