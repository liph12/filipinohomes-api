<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NearbyFacilityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'property_id'    => $this->property_id,
            'school'         => $this->school ?? [],
            'hospital'       => $this->hospital ?? [],
            'clinic'         => $this->clinic ?? [],
            'pharmacy'       => $this->pharmacy ?? [],
            'fire_station'   => $this->fire_station ?? [],
            'police_station' => $this->police_station ?? [],
        ];
    }
}
