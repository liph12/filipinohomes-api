<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdPlacementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ad_id' => $this->ad_id,
            'ad_section_id' => $this->ad_section_id,
            'priority' => $this->priority,
            'weight' => $this->weight,
            'is_fixed' => $this->is_fixed,
            'ad' => new AdResource($this->whenLoaded('ad')),
            'section' => new AdSectionResource($this->whenLoaded('section')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
