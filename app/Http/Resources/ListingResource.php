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
        $user = auth('sanctum')->user();
        $isAdmin = $user && optional($user->role)->name === 'admin';
        $isOwner = $user && $this->agent_id === optional($user->agent)->id;
        $canSeeAudit = $isAdmin || $isOwner;

        return [
            'id'             => $this->id,
            'code'           => $this->code,
            'visibility'     => $this->visibility,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'price'          => $this->price,
            'featured_photo' => $this->featured_photo,
            'is_featured'    => $this->is_featured,
            'featured_until' => $this->featured_until,
            'clicks'         => $this->clicks,
            'inquiries_count' => $this->inquiries_count ?? 0,
            'seo_tags'       => $this->seo_tags,
            'created_at'          => $this->updated_at->diffForHumans(),
            'date_added'          => $this->created_at->toDateString(),
            'is_new'              => $this->created_at?->gte(now()->subDays(14)) ?? false,
            'verification_status'  => $this->verification_status,
            'audit_notes'          => $this->when($canSeeAudit, $this->audit_notes),
            'audit_checklist'      => $this->when($canSeeAudit, $this->audit_checklist),
            'audited_at'           => $this->when($canSeeAudit, $this->audited_at?->toDateTimeString()),
            'agent_edited_fields'  => $this->when($canSeeAudit, $this->agent_edited_fields),
            'audit_edited_fields'  => $this->when($canSeeAudit, $this->audit_edited_fields),
            're_submitted_at'      => $this->when($canSeeAudit, $this->re_submitted_at?->toDateTimeString()),
            'property'            => new PropertyResource($this->property),
            'category'            => new CategoryResource($this->category),
            'agent'               => new AgentResource($this->agent)
        ];
    }
}
