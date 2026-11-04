<?php

namespace App\Http\Resources;

use App\Support\Briefs;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientBriefResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'name' => $this->name,
            'phone_code' => $this->phone_code,
            'phone' => $this->phone,
            'email' => $this->email,
            'contact_time' => $this->contact_time,
            'budget' => $this->budget,
            'message' => $this->message,
            'profile' => $this->profile ?? (object) [],
            // Buyers only: who to call first, from the answers.
            'grade' => $this->kind === 'buyer' ? Briefs::grade($this->budget, $this->profile ?? []) : null,
            'created_at' => $this->created_at,
            'agent' => $this->whenLoaded('agent', fn () => [
                'id' => $this->agent->id,
                'full_name' => Briefs::agentName($this->agent),
            ]),
        ];
    }
}
