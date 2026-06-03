<?php

namespace App\Http\Resources;

use App\Models\BlockedUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $authUserId = Auth::id();
        $unreadCount = 0;

        // Use precomputed unread_count if available (set by controller)
        if ($this->resource->getAttribute('computed_unread_count') !== null) {
            $unreadCount = (int) $this->resource->getAttribute('computed_unread_count');
        } elseif ($this->relationLoaded('users') && $authUserId) {
            $pivot = $this->users->firstWhere('id', $authUserId)?->pivot;
            $lastReadAt = $pivot?->last_read_at;

            if ($this->relationLoaded('messages')) {
                $unreadCount = $this->messages
                    ->where('user_id', '!=', $authUserId)
                    ->when($lastReadAt, fn ($q) => $q->where('created_at', '>', $lastReadAt))
                    ->count();
            }
        }

        // Resolve which user serves as "the agent" for this
        // conversation's block-check purposes. Listing chats record
        // it as conversation.agent_user_id directly; agent-direct
        // ("Message Me") chats leave that column null and the agent
        // is the chat's target user (chat.type_id). Without this
        // fallback, agent-direct conversations never set
        // is_blocked_for_me=true even when the client is banned —
        // surfaced on prod 2026-06-03 when a globally-blocked client
        // could keep messaging through an existing agent-direct
        // thread.
        $resolvedAgentId = (int) ($this->agent_user_id ?? 0);
        if (!$resolvedAgentId) {
            $this->loadMissing('chat');
            if ($this->chat?->type === 'agent') {
                $resolvedAgentId = (int) $this->chat->type_id;
            }
        }

        // Per-viewer "am I blocked from messaging in this conversation?"
        // signal. Drives the frontend's composer-to-blocked-panel swap so
        // the client sees a clear notice instead of a still-typeable input.
        // Only the participant client can be blocked here — agents,
        // moderators, and admins always see false even if a per-agent row
        // happens to exist with their user_id (they're the blocker, not
        // the blockee). The check is scope-aware via BlockedUser::isBlocking.
        $isBlockedForMe = false;
        if ($authUserId && $resolvedAgentId && $authUserId !== $resolvedAgentId) {
            $isBlockedForMe = BlockedUser::isBlocking((int) $authUserId, $resolvedAgentId);
        }

        // Global-ban awareness banner for everyone EXCEPT the blocked
        // client themselves. Lets agents and team leaders see at a
        // glance that the chat owner is on the platform-wide blocklist
        // (i.e., an admin has banned them site-wide), not just blocked
        // from this specific agent. The client already gets
        // is_blocked_for_me=true above, so they don't need the duplicate
        // signal.
        $isClientGloballyBlocked = false;
        $this->loadMissing('chat');
        $clientUserId = (int) ($this->chat?->user_id ?? 0);
        if ($clientUserId && $authUserId && $authUserId !== $clientUserId) {
            $isClientGloballyBlocked = BlockedUser::where('blocked_user_id', $clientUserId)
                ->where('scope', 'global')
                ->exists();
        }

        return [
            'id' => $this->id,
            'chat_id' => $this->chat_id,
            'status' => $this->status,
            'agent_user_id' => $this->agent_user_id,
            'agent_user' => new UserResource($this->whenLoaded('agentUser')),
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at,
            'reviewed_by_user' => new UserResource($this->whenLoaded('reviewedBy')),
            'closed_at' => $this->closed_at,
            'closed_by' => $this->closed_by,
            'closed_by_user' => new UserResource($this->whenLoaded('closedBy')),
            'latest_message' => new MessageResource($this->whenLoaded('latestMessage')),
            'users' => UserResource::collection($this->whenLoaded('users')),
            'unread_count' => $unreadCount,
            'is_blocked_for_me' => $isBlockedForMe,
            'is_client_globally_blocked' => $isClientGloballyBlocked,
            'created_at' => $this->created_at,
        ];
    }
}
