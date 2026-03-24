<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentPageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->user;
        
         return [
            'id'             => $this->id,
            'first_name'   => $this->first_name,
            'middle_name'  => $this->middle_name,
            'last_name'    => $this->last_name,
            'full_name'    => collect([$this->first_name, $this->middle_name, $this->last_name])
                                ->filter()
                                ->join(' ')
                             ?: $user?->name
                             ?: 'Guest User',
            'avatar'       => $this->avatar ?? $user?->avatar,
            'mobile_no'    => $this->mobile_no ?? $user?->mobile_no,
            'socials'      => $this->socials,
        ];
    }
}
