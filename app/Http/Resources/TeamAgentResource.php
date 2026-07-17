<?php

namespace App\Http\Resources;

use App\Support\AvatarUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamAgentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'team_id'      => $this->team_id,
            'agent_id'     => $this->agent_id,
            'agent_name'   => $this->agent
                ? (collect([$this->agent->first_name, $this->agent->middle_name, $this->agent->last_name])->filter()->join(' ') ?: $this->agent->user?->name)
                : null,
            'agent_avatar' => AvatarUrl::clean($this->agent?->avatar ?? $this->agent?->user?->avatar ?? null),
            'team_name'    => $this->team?->name,
            'is_leader'             => $this->is_leader,
            'status'                => $this->status,
            'agent_login_count'        => (int) ($this->agent_login_count        ?? 0),
            'agent_listings_count'     => (int) ($this->agent_listings_count     ?? 0),
            'agent_transactions_count' => (int) ($this->agent_transactions_count ?? 0),
            'agent_inquiries_count'    => (int) ($this->agent_inquiries_count    ?? 0),
        ];
    }
}
