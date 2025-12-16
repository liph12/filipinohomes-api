<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'first_name'    => $this->first_name,
            'middle_name'   => $this->middle_name,
            'last_name'     => $this->last_name,
            'mobile_no'     => $this->mobile_no,
            'whats_app_no'  => $this->whats_app_no,
            'address'       => $this->address,
            'socials'       => $this->socials,
            'bio'           => $this->bio,
            'avatar'        => $this->avatar,
            'geo_location'  => $this->geo_location
        ];
    }
}
