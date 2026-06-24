<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $reactions = $this->whenLoaded('reactions', function () {
            return $this->reactions->groupBy('emoji')->map(function ($group, $emoji) {
                return [
                    'emoji' => $emoji,
                    'count' => $group->count(),
                    'users' => $group->map(fn ($r) => [
                        'id' => $r->user->id,
                        'name' => $r->user->name,
                    ])->values(),
                ];
            })->values();
        });

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'type' => $this->type,
            'body' => $this->body,
            'attachments' => $this->attachments ?? [],
            'read_at' => $this->read_at,
            'status' => $this->status,
            'reply_to' => $this->whenLoaded('replyTo', function () {
                if (!$this->replyTo) return null;
                return [
                    'id' => $this->replyTo->id,
                    'body' => $this->replyTo->body,
                    'attachments' => $this->replyTo->attachments ?? [],
                    'user' => [
                        'id' => $this->replyTo->user->id,
                        'name' => $this->replyTo->user->name,
                    ],
                ];
            }),
            'reactions' => $reactions ?? [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
