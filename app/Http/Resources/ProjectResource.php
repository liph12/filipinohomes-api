<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $geoCoordinates = null;

        if ($this->latitude !== null && $this->longitude !== null) {
            $geoCoordinates = [
                'lat' => (float) $this->latitude,
                'lng' => (float) $this->longitude,
            ];
        }

        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'featured_photo' => $this->featured_photo,
            'photos_url' => $this->photos_url,
            'complete_address' => $this->complete_address,
            'street' => $this->street,
            'brgy_id' => $this->brgy_id,
            'city_id' => $this->city_id,
            'prov_id' => $this->prov_id,
            'mapaddress' => $this->mapaddress,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'geo_coordinates' => $geoCoordinates,
            'views' => $this->views,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'deleted_by' => $this->deleted_by,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
        ];

        if (isset($this->properties_count)) {
            $data['properties_count'] = (int) $this->properties_count;
        }

        return $data;
    }
}
