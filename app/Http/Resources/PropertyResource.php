<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\PropertyAttributesResource;
use App\Http\Resources\FurnishingResource;
use App\Http\Resources\NearbyFacilityResource;
use App\Http\Resources\ProjectResource;
use App\Support\VariantUrl;
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
        $barangay  = optional($this->barangay);
        $city      = optional($barangay->city);
        $province  = optional($city->province);
         return [
            'id'                    => $this->id,
            'name'                  => $this->name,
            "status"                => $this->status,
            'status_change_date'     => $this->status_change_date,
            'status_remark'          => $this->status_remark,
            'ats_expiration_date'    => $this->ats_expiration_date,
            'ats_attachments'        => $this->ats_attachments,
            'ats_remarks'            => $this->ats_remarks,
            'agent_ats_remarks'      => $this->agent_ats_remarks,
            'ats_status'            => $this->ats_status,
            'reviewed_by'           => $this->reviewed_by,
            'address'               => $this->address,
            'photos'                => $this->photos,       
            'amenities'             => $this->amenities,   
            'address_id'            => [
                'id'   => $this->address_id,
                'name' => $barangay->name,
                'city' => [
                    'id'         => $city->id,
                    'name'       => $city->name,
                    'postalcode' => $city->postalcode,
                ],
                'province' => [
                    'id'   => $province->id,
                    'name' => $province->name,
                    'code' => $province->code,
                ],
            ],
            'description'           => $this->description,
            // Responsive srcset per gallery photo (index-aligned with `photos`),
            // emitted only when this property's variants exist on S3. null →
            // frontend falls back to the single original.
            'photos_srcset'         => $this->photos_variants_generated_at
                ? collect($this->photos ?? [])
                    ->map(fn ($u) => is_string($u) ? VariantUrl::srcset($u) : null)
                    ->all()
                : null,
            'geo_coordinates'       => $this->geo_coordinates,
            'is_project'            => $this->is_project,
            'project_id'            => $this->project_id,
            // Parent project — only when eager-loaded (detail page). Feeds the
            // project card under the listing agent.
            'project'               => $this->whenLoaded('project', fn () => new ProjectResource($this->project)),
            'property'              => $attributes,
            'furnishing'            => new FurnishingResource($this->furnishing),
            'nearby_facilities'     => NearbyFacilityResource::make($this->whenLoaded('nearbyFacility')),
        ];
    }
}
