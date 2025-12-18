<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\AgentResource;

class ListingResource extends JsonResource
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
            'code'           => $this->code,
            'status'         => $this->status,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'price'          => $this->price,
            'featured_photo' => $this->featured_photo,
            'is_featured'    => $this->is_featured,
            'clicks'         => $this->clicks,
            'property_id'    => $this->property_id,
            'category_id'    => $this->category_id,
            'agent'          => new AgentResource($this->agent)

        ];
    }
}
