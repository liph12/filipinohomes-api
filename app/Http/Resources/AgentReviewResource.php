<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class AgentReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $authId = Auth::id();
        $isOwn = $authId && (int) $authId === (int) $this->client_user_id;
        $editWindowOpen = $this->edit_window_ends_at
            ? now()->lessThanOrEqualTo($this->edit_window_ends_at)
            : false;

        return [
            'id' => $this->id,
            'agent_user_id' => (int) $this->agent_user_id,
            'client_user_id' => (int) $this->client_user_id,
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name,
                    'avatar' => $this->client->avatar,
                ];
            }),
            'conversation_id' => $this->conversation_id ? (int) $this->conversation_id : null,
            'overall_rating' => (int) $this->overall_rating,
            'tags' => $this->tags ?? [],
            'comment' => $this->comment,
            'status' => $this->status,
            'hidden_at' => $this->hidden_at,
            'hidden_reason' => $this->hidden_reason,
            'edit_window_ends_at' => $this->edit_window_ends_at,
            'is_own_review' => $isOwn,
            'is_editable_for_me' => $isOwn && $editWindowOpen,
            'response' => $this->whenLoaded('response', function () {
                if (!$this->response) {
                    return null;
                }
                return [
                    'id' => $this->response->id,
                    'body' => $this->response->body,
                    'created_at' => $this->response->created_at,
                    'updated_at' => $this->response->updated_at,
                ];
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
