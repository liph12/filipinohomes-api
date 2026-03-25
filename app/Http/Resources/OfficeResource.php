<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfficeResource extends JsonResource
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
            'slug' => $this->slug,
            'title' => $this->title,
            'contact' => $this->contact,
            'phone' => $this->phone,
            'address' => $this->address,
            'photo' => $this->photo,
            'geo_coordinates' => $this->geo_coordinates,
        ];
    }
}
