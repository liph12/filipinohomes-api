<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\PropertyAttributesResource;
use App\Http\Resources\FurnishingResource;
class PropertyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
         return [
            'id'                    => $this->id,
            'name'                  => $this->name,
            "status"                => $this->status,
            'address'               => $this->address,
            'photos'                => $this->photos,       
            'amenities'             => $this->amenities,   
            'address_id'            => $this->address_id,
            'description'           => $this->description,
            'geo_coordinates'       => $this->geo_coordinates,
            'is_project'            => $this->is_project,
            'property'              => new PropertyAttributesResource($this->propertyAttribute),
            'furnishing'            => new FurnishingResource($this->furnishing),
        ];
    }
}
