<?php

namespace App\Http\Resources;

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
            'agent_avatar' => $this->agent?->avatar ?? $this->agent?->user?->avatar ?? null,
            'team_name'    => $this->team?->name,
            'is_leader'    => $this->is_leader,
            'status'       => $this->status,
        ];
    }
}
