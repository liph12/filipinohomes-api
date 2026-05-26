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
 * Common rules (failure paths short-circuit before any trigger fires):
 *   - Conversation must have an assigned agent.
 *   - Caller must own the chat row that wraps the conversation (the
 *     reviewer is always the inquiring client, never the agent).
 *   - Self-review is rejected (client_user_id !== agent_user_id).
 *
 * Trigger gates (any one fires eligibility, recorded via reason_code):
 *   A) closed       — conversation.status === 'closed'.
 *   B) listing_sold — listing's property in a terminal state
 *                     (sold / rented / leased). Highest-signal moment.
 *   C) client_quiet — accepted + >= 1 client message + >= 7 days since
 *                     the client's most recent message. Catches the
 *                     "visited and went silent" pattern that the 48h
 *                     all-sides gate misses.
 *   D) engagement   — client_messages >= 3 AND agent_messages >= 1 AND
 *                     >= 48h since the last message from any side.
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
    private const CLIENT_QUIET_DAYS = 7;
    // open_relationship — fires for active, established conversations
    // that the existing quiet-period gates miss. Caught the "we've been
    // chatting daily for a week, let me rate now" pattern.
    private const OPEN_RELATIONSHIP_DAYS = 5;
    private const OPEN_RELATIONSHIP_CLIENT_MIN = 5;
    private const OPEN_RELATIONSHIP_AGENT_MIN = 3;
    // canSubmit() — looser gate used by the manual rate entries
    // (chat-header kebab, agent profile button). The auto-prompt gates
    // above stay strict so we don't spam clients with surfaces.
    private const SUBMIT_CLIENT_MIN = 1;
    private const SUBMIT_AGENT_MIN = 1;

    /**
     * @return array{eligible:bool, reason:?string, reason_code:?string, existing_review_id:?int, already_shown:bool}
     */
    public function check(int $clientUserId, Conversation $conv): array
    {
        // Ensure we have enough relations to evaluate every trigger. The
        // listing-status trigger needs chat → listing → property; missing
        // relations are quietly skipped so the trigger just won't fire.
        $conv->loadMissing(['chat', 'chat.listing.property:id,status']);

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

        // (A) Closed conversation — moderation-driven hard end.
        if ($conv->status === 'closed') {
            return $this->eligible('closed', $existingReviewId, $alreadyShown);
        }

        // (B) Listing transactional state — the deal closed off-platform.
        // Strongest real-world signal available since most PH inquiries
        // never close via the chat's Close button.
        $listingStatus = $conv->chat?->listing?->property?->status ?? null;
        if (in_array($listingStatus, ['sold', 'rented', 'leased'], true)) {
            return $this->eligible('listing_sold', $existingReviewId, $alreadyShown);
        }

        // For (C) and (D) we need message activity. One grouped query.
        $counts = Message::query()
            ->where('conversation_id', $conv->id)
            ->whereNotIn('status', ['deleted', 'unsent'])
            ->selectRaw('
                SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END) as client_msgs,
                SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END) as agent_msgs,
                MAX(created_at) as last_at,
                MAX(CASE WHEN user_id = ? THEN created_at END) as last_client_at
            ', [$clientUserId, $conv->agent_user_id, $clientUserId])
            ->first();

        $clientMsgs = (int) ($counts->client_msgs ?? 0);
        $agentMsgs = (int) ($counts->agent_msgs ?? 0);
        $lastAt = $counts->last_at ? \Carbon\Carbon::parse($counts->last_at) : null;
        $lastClientAt = $counts->last_client_at
            ? \Carbon\Carbon::parse($counts->last_client_at)
            : null;

        // (C) Accepted + client has been silent for >= 7 days. Don't
        // require the AGENT to also be silent — the agent might've
        // followed up multiple times since; what we care about is that
        // the client has stopped engaging, which usually means the
        // experience already concluded (toured / declined / found
        // elsewhere).
        if ($conv->status === 'accepted'
            && $clientMsgs >= 1
            && $lastClientAt
            && $lastClientAt->diffInDays(now()) >= self::CLIENT_QUIET_DAYS) {
            return $this->eligible('client_quiet', $existingReviewId, $alreadyShown);
        }

        // (E) open_relationship — established back-and-forth that the
        // quiet-period gates can't catch. Accepted conversation that's
        // been alive for several days with substantial messaging from
        // both sides means the client clearly knows what they think of
        // the agent; we don't need to wait for silence to ask.
        if ($conv->status === 'accepted'
            && $conv->created_at
            && $conv->created_at->diffInDays(now()) >= self::OPEN_RELATIONSHIP_DAYS
            && $clientMsgs >= self::OPEN_RELATIONSHIP_CLIENT_MIN
            && $agentMsgs >= self::OPEN_RELATIONSHIP_AGENT_MIN) {
            return $this->eligible('open_relationship', $existingReviewId, $alreadyShown);
        }

        // (F) Original engagement gate. Keep all three sub-conditions
        // intact so we don't regress the existing behavior.
        if ($clientMsgs < self::ENGAGEMENT_CLIENT_MIN) {
            return $this->fail('Send a few more messages before rating.', $existingReviewId, $alreadyShown);
        }
        if ($agentMsgs < self::ENGAGEMENT_AGENT_MIN) {
            return $this->fail('Wait for the agent to reply before rating.', $existingReviewId, $alreadyShown);
        }
        if (!$lastAt || $lastAt->diffInMinutes(now()) < self::ENGAGEMENT_QUIET_MINUTES) {
            return $this->fail('Conversation is still active — rate after 48 hours of quiet.', $existingReviewId, $alreadyShown);
        }

        return $this->eligible('engagement', $existingReviewId, $alreadyShown);
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
     * @return array{eligible:bool, reason:null, reason_code:string, existing_review_id:?int, already_shown:bool}
     */
    private function eligible(
        string $reasonCode,
        ?int $existingReviewId,
        bool $alreadyShown,
    ): array {
        return [
            'eligible' => true,
            'reason' => null,
            'reason_code' => $reasonCode,
            'existing_review_id' => $existingReviewId ? (int) $existingReviewId : null,
            'already_shown' => $alreadyShown,
        ];
    }

    /**
     * @return array{eligible:bool, reason:string, reason_code:null, existing_review_id:?int, already_shown:bool}
     */
    private function fail(string $reason, ?int $existingReviewId, bool $alreadyShown): array
    {
        return [
            'eligible' => false,
            'reason' => $reason,
            'reason_code' => null,
            'existing_review_id' => $existingReviewId ? (int) $existingReviewId : null,
            'already_shown' => $alreadyShown,
        ];
    }

    /**
     * Looser submission gate used by the manual rate entries — chat
     * header kebab, agent profile button, etc. The auto-prompt gates
     * in check() decide whether to SURFACE a prompt to the client; this
     * decides whether to ACCEPT a submission once they've clicked. A
     * client who explicitly wants to rate shouldn't have to wait for
     * the conversation to go quiet — but we still require a real
     * relationship (one message each way) so we never accept drive-by
     * ratings from a chat the agent never replied to.
     *
     * Returns the same array shape as check() so AgentReviewController
     * can swap one for the other without touching its callers.
     *
     * @return array{can_submit:bool, reason:?string, existing_review_id:?int}
     */
    public function canSubmit(int $clientUserId, Conversation $conv): array
    {
        $conv->loadMissing('chat');

        if (!$conv->agent_user_id) {
            return $this->submitFail('Conversation has no assigned agent.', null);
        }
        if ((int) $conv->chat?->user_id !== $clientUserId) {
            return $this->submitFail('Only the inquiring client can rate this agent.', null);
        }
        if ($clientUserId === (int) $conv->agent_user_id) {
            return $this->submitFail('You cannot rate yourself.', null);
        }

        $existingReviewId = AgentReview::where('client_user_id', $clientUserId)
            ->where('agent_user_id', $conv->agent_user_id)
            ->value('id');

        $counts = Message::query()
            ->where('conversation_id', $conv->id)
            ->whereNotIn('status', ['deleted', 'unsent'])
            ->selectRaw('
                SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END) as client_msgs,
                SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END) as agent_msgs
            ', [$clientUserId, $conv->agent_user_id])
            ->first();

        $clientMsgs = (int) ($counts->client_msgs ?? 0);
        $agentMsgs = (int) ($counts->agent_msgs ?? 0);

        if ($clientMsgs < self::SUBMIT_CLIENT_MIN) {
            return $this->submitFail('Send a message first before rating.', $existingReviewId);
        }
        if ($agentMsgs < self::SUBMIT_AGENT_MIN) {
            return $this->submitFail('Wait for the agent to reply at least once.', $existingReviewId);
        }

        return [
            'can_submit' => true,
            'reason' => null,
            'existing_review_id' => $existingReviewId ? (int) $existingReviewId : null,
        ];
    }

    /**
     * @return array{can_submit:bool, reason:string, existing_review_id:?int}
     */
    private function submitFail(string $reason, ?int $existingReviewId): array
    {
        return [
            'can_submit' => false,
            'reason' => $reason,
            'existing_review_id' => $existingReviewId ? (int) $existingReviewId : null,
        ];
    }
}
