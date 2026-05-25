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

        // Per-viewer "am I blocked from messaging in this conversation?"
        // signal. Drives the frontend's composer-to-blocked-panel swap so
        // the client sees a clear notice instead of a still-typeable input.
        // Only the participant client can be blocked here — agents,
        // moderators, and admins always see false even if a per-agent row
        // happens to exist with their user_id (they're the blocker, not
        // the blockee). The check is scope-aware via BlockedUser::isBlocking.
        $isBlockedForMe = false;
        if ($authUserId && $this->agent_user_id && $authUserId !== (int) $this->agent_user_id) {
            $isBlockedForMe = BlockedUser::isBlocking((int) $authUserId, (int) $this->agent_user_id);
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
            'created_at' => $this->created_at,
        ];
    }
}
