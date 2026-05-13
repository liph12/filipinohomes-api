<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'logo' => $this->logo,
            'leader' => new TeamAgentResource($this->leader),
            'team_agents' => $this->whenLoaded(
                'teamAgents',
                fn () => new TeamAgentResourceCollection($this->teamAgents)
            ),
            'login_count'        => (int) ($this->login_count ?? 0),
            'listings_count'     => (int) ($this->listings_count ?? 0),
            'transactions_count' => (int) ($this->transactions_count ?? 0),
            'inquiries_count'    => (int) ($this->inquiries_count ?? 0),
            'members_count'      => $this->teamAgents ? $this->teamAgents->count() : 0,
        ];
    }
}
