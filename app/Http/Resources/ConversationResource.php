<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $authUserId = Auth::id();
        $unreadCount = 0;

        if ($this->relationLoaded('users') && $authUserId) {
            $pivot = $this->users->firstWhere('id', $authUserId)?->pivot;
            $lastReadAt = $pivot?->last_read_at;

            if ($this->relationLoaded('messages')) {
                $unreadCount = $this->messages
                    ->where('user_id', '!=', $authUserId)
                    ->when($lastReadAt, fn ($q) => $q->where('created_at', '>', $lastReadAt))
                    ->count();
            }
        }

        return [
            'id' => $this->id,
            'chat_id' => $this->chat_id,
            'status' => $this->status,
            'latest_message' => new MessageResource($this->whenLoaded('latestMessage')),
            'users' => UserResource::collection($this->whenLoaded('users')),
            'unread_count' => $unreadCount,
            'created_at' => $this->created_at,
        ];
    }
}
