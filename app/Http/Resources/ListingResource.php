<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\AgentResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\PropertyResource;
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
            'visibility'     => $this->visibility,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'price'          => $this->price,
            'featured_photo' => $this->featured_photo,
            'is_featured'    => $this->is_featured,
            'clicks'         => $this->clicks,
            'seo_tags'       => $this->seo_tags,
            'created_at'          => $this->updated_at->diffForHumans(),
            'date_added'          => $this->created_at->toDateString(),
            'verification_status'  => $this->verification_status,
            'audit_notes'          => $this->audit_notes,
            'audit_checklist'      => $this->audit_checklist,
            'audited_at'           => $this->audited_at?->toDateTimeString(),
            'agent_edited_fields'  => $this->agent_edited_fields,
            'audit_edited_fields'  => $this->audit_edited_fields,
            're_submitted_at'      => $this->re_submitted_at?->toDateTimeString(),
            'property'            => new PropertyResource($this->property),
            'category'            => new CategoryResource($this->category),
            'agent'               => new AgentResource($this->agent)
        ];
    }
}
