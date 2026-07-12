<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class ChatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Per-participant archive / trash / purge flags. Derived from
        // the conversation_users pivot for
        // (Auth::id(), active_conversation_id). All default to false
        // when the viewer isn't in the pivot (e.g. an admin acting on
        // a chat they were never attached to — mutateViewerPivot
        // attaches lazily before the first action, so this only stays
        // false pre-action). is_purged_for_me will only ever be true
        // in admin-recovery contexts; the index() query strips purged
        // rows from regular viewers.
        [$isArchivedForMe, $isTrashedForMe, $isPurgedForMe] = $this->resolveViewerPivotFlags();

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
                    // True when the listing has been soft-deleted. Only ever
                    // surfaces on callers that load the relation withTrashed
                    // (e.g. the team dashboard's ?with_reply_stats=1); the
                    // default scope drops trashed listings so this stays false.
                    'is_deleted' => $this->listing->deleted_at !== null,
                ];
            }),
            // The listing's owning agent, surfaced as a second participant
            // alongside the inquirer (chat.user). This is the listing OWNER
            // (listings.agent_id), which is well-defined even before a
            // conversation is accepted/assigned — distinct from the
            // conversation's assigned agent_user_id. Only present for listing
            // inquiries with the relation chain loaded (see ChatController@show).
            'agent' => $this->when(
                $this->type === 'listing'
                    && $this->relationLoaded('listing')
                    && $this->listing?->relationLoaded('agent')
                    && $this->listing->agent?->relationLoaded('user')
                    && $this->listing->agent->user !== null,
                fn () => new UserResource($this->listing->agent->user),
            ),
            'active_conversation' => new ConversationResource($this->whenLoaded('activeConversation')),
            // The team the assigned agent belongs to — only populated when the
            // team relations are eager-loaded (admin "Team" scope). Drives the
            // team-grouped inbox view. Null otherwise.
            'team' => $this->resolveAssignedAgentTeam(),
            'is_archived_for_me' => $isArchivedForMe,
            'is_trashed_for_me' => $isTrashedForMe,
            'is_purged_for_me' => $isPurgedForMe,
            // Reply-monitoring aggregates — only present when the caller opted in
            // via ?with_reply_stats=1 (see ChatController@index). Drive the team
            // dashboard's "Agent replied?" + "Client last reply" columns.
            'agent_replied' => $this->when(
                array_key_exists('agent_replied', $this->resource->getAttributes()),
                fn () => (bool) $this->resource->getAttribute('agent_replied'),
            ),
            'client_last_reply_at' => $this->when(
                array_key_exists('client_last_reply_at', $this->resource->getAttributes()),
                fn () => $this->resource->getAttribute('client_last_reply_at'),
            ),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * The team the conversation's assigned agent actively belongs to.
     * Returns null unless the chain
     * activeConversation.agentUser.agent.teamMembers.team is eager-loaded
     * (done only for the admin "Team" scope to avoid extra joins elsewhere).
     */
    private function resolveAssignedAgentTeam(): ?array
    {
        if (! $this->relationLoaded('activeConversation') || ! $this->activeConversation) {
            return null;
        }
        $conv = $this->activeConversation;
        if (! $conv->relationLoaded('agentUser') || ! $conv->agentUser) {
            return null;
        }
        $agentUser = $conv->agentUser;
        if (! $agentUser->relationLoaded('agent') || ! $agentUser->agent) {
            return null;
        }
        $agent = $agentUser->agent;
        if (! $agent->relationLoaded('teamMembers')) {
            return null;
        }
        $membership = $agent->teamMembers->firstWhere('status', 'active');
        $team = $membership && $membership->relationLoaded('team') ? $membership->team : null;
        if (! $team) {
            return null;
        }

        return [
            'id' => $team->id,
            'name' => $team->name,
            'logo' => $team->logo,
        ];
    }

    /**
     * @return array{0: bool, 1: bool, 2: bool} [archived, trashed, purged]
     */
    private function resolveViewerPivotFlags(): array
    {
        $viewerId = Auth::id();
        if (! $viewerId || ! $this->relationLoaded('activeConversation') || ! $this->activeConversation) {
            return [false, false, false];
        }
        if (! $this->activeConversation->relationLoaded('users')) {
            return [false, false, false];
        }
        $me = $this->activeConversation->users->firstWhere('id', $viewerId);
        if (! $me) {
            return [false, false, false];
        }

        return [
            $me->pivot?->archived_at !== null,
            $me->pivot?->removed_at !== null,
            $me->pivot?->purged_at !== null,
        ];
    }
}
