<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class AgentReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The public /agents/{id}/reviews route doesn't run auth:sanctum
        // middleware (it's cacheable + reachable to visitors), so a
        // bare Auth::id() returns null even for logged-in clients
        // looking at their own review. Fall back to the Sanctum guard
        // directly so the bearer token still resolves the viewer.
        $authId = Auth::id() ?: auth('sanctum')->id();
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
            // Reviewer's account role. Lets the frontend render an
            // "Inquired as agent" badge on the public profile so
            // visitors can read agent-to-agent reviews in context.
            // Falls through to null when 'client.role' isn't loaded.
            'inquirer_role' => $this->whenLoaded('client', function () {
                return $this->client->relationLoaded('role')
                    ? $this->client->role?->name
                    : null;
            }),
            // Listing context for the row chip ("Re: {listing.name}").
            // Reached via conversation → chat → listing; null when the
            // relation chain isn't loaded, the listing was hard-deleted,
            // or the chat isn't a listing-type inquiry (chat.type='agent'
            // DMs reuse type_id for the messaged user, so Eloquent could
            // otherwise resolve a spurious Listing whose id happens to
            // match that user id).
            'listing' => $this->whenLoaded('conversation', function () {
                // Only read the chat when it's actually eager-loaded. Admin
                // endpoints load `conversation` (for chat_id) WITHOUT the chat
                // relation, and touching ->chat there would lazy-load per row.
                $chat = $this->conversation && $this->conversation->relationLoaded('chat')
                    ? $this->conversation->chat
                    : null;
                if (!$chat || $chat->type !== 'listing') return null;
                $listing = $chat->listing ?? null;
                if (!$listing) return null;
                return [
                    'id' => $listing->id,
                    'name' => $listing->name,
                    'slug' => $listing->slug,
                    'featured_photo' => $listing->featured_photo,
                ];
            }),
            // Denormalized helpful counter + per-viewer state. Counter
            // is always present (column has default 0). is_helpful_for_me
            // relies on the controller eager-loading 'helpfulVotes' so
            // the public list endpoint doesn't N+1.
            'helpful_count' => (int) ($this->helpful_count ?? 0),
            'is_helpful_for_me' => $authId && $this->relationLoaded('helpfulVotes')
                ? $this->helpfulVotes->contains('user_id', (int) $authId)
                : false,
            // Agent name + active team — populated by admin endpoints
            // that eager-load 'agent' and 'agent.agent.teamMembers.team'.
            // Falls through to null/undefined when those relations
            // aren't loaded so public profile responses stay slim.
            'agent' => $this->whenLoaded('agent', function () {
                $teamMember = $this->agent->relationLoaded('agent')
                    ? $this->agent->agent?->teamMembers?->firstWhere('status', 'active')
                    : null;
                return [
                    'id' => $this->agent->id,
                    // Canonical agents-table id (NOT the user id) — the
                    // /agents/{slug} profile route resolves by agents.id, so
                    // the agent-name link must build its slug from this.
                    'profile_id' => $this->agent->relationLoaded('agent')
                        ? ($this->agent->agent?->id ?? null)
                        : null,
                    'name' => $this->agent->name,
                    'avatar' => $this->agent->avatar,
                    'team_id' => $teamMember?->team_id ?? null,
                    'team_name' => $teamMember?->team?->name ?? null,
                ];
            }),
            'conversation_id' => $this->conversation_id ? (int) $this->conversation_id : null,
            // Chat thread the rating came from — powers the admin "view
            // conversation" deep-link (/admin/listing-inquiries?chat=<chat_id>).
            // Null when the conversation was hard-deleted or isn't eager-loaded.
            'chat_id' => $this->relationLoaded('conversation') && $this->conversation
                ? (int) $this->conversation->chat_id
                : null,
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
