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
        $attributes = new PropertyAttributesResource($this->propertyAttribute);
         return [
            'id'                    => $this->id,
            'name'                  => $this->name,
            "status"                => $this->status,
            'status_change_date'     => $this->status_change_date,
            'status_remark'          => $this->status_remark,
            'ats_expiration_date'    => $this->ats_expiration_date,
            'address'               => $this->address,
            'photos'                => $this->photos,       
            'amenities'             => $this->amenities,   
            'address_id'            => $this->address_id,
            'description'           => $this->description,
            'geo_coordinates'       => $this->geo_coordinates,
            'is_project'            => $this->is_project,
            'property'              => $attributes,
            'furnishing'            => new FurnishingResource($this->furnishing),
        ];
    }
}
