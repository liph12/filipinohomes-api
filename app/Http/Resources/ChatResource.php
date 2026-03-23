<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
                ];
            }),
            'active_conversation' => new ConversationResource($this->whenLoaded('activeConversation')),
            'created_at' => $this->created_at,
        ];
    }
}
