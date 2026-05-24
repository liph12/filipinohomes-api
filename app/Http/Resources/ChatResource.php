<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class ChatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Per-participant archive / trash flags. Derived from the
        // conversation_users pivot for (Auth::id(), active_conversation_id).
        // Both default to false when the viewer isn't in the pivot
        // (e.g. an admin acting on a chat they were never attached to —
        // ChatController::mutateViewerPivot attaches lazily before the
        // first action, so this only stays false pre-action).
        [$isArchivedForMe, $isTrashedForMe] = $this->resolveViewerPivotFlags();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_id' => $this->type_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'listing' => $this->when($this->type === 'listing' && $this->relationLoaded('listing') && $this->listing !== null, function () {
                return [
                    'id' => $this->listing->id,
                    'name' => $this->listing->name,
                    'slug' => $this->listing->slug,
                    'price' => $this->listing->price,
                    'featured_photo' => $this->listing->featured_photo,
                    'property_status' => $this->listing->property?->status ?? 'active',
                ];
            }),
            'active_conversation' => new ConversationResource($this->whenLoaded('activeConversation')),
            'is_archived_for_me' => $isArchivedForMe,
            'is_trashed_for_me'  => $isTrashedForMe,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * @return array{0: bool, 1: bool} [is_archived_for_me, is_trashed_for_me]
     */
    private function resolveViewerPivotFlags(): array
    {
        $viewerId = Auth::id();
        if (!$viewerId || !$this->relationLoaded('activeConversation') || !$this->activeConversation) {
            return [false, false];
        }
        if (!$this->activeConversation->relationLoaded('users')) {
            return [false, false];
        }
        $me = $this->activeConversation->users->firstWhere('id', $viewerId);
        if (!$me) {
            return [false, false];
        }
        return [
            $me->pivot?->archived_at !== null,
            $me->pivot?->removed_at !== null,
        ];
    }
}
