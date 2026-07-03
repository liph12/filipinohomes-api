<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BuyerFormResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id'          => $this->id,
            'slug'        => $this->slug,
            'title'       => $this->title,
            'description' => $this->description,
            'location'    => $this->location,
            'created_at'  => $this->created_at,
            'category'    => $this->propertyType ? [
                'id'   => $this->propertyType->id,
                'name' => $this->propertyType->name,
            ] : null,
            'project'     => $this->project ? [
                'id'               => $this->project->id,
                'name'             => $this->project->name,
                'complete_address' => $this->project->complete_address,
                'image'            => $this->projectImage(),
            ] : null,
            'agent'       => $this->agent ? new AgentPageResource($this->agent) : null,
            'registrations_count' => $this->whenCounted('registrations'),
        ];

        return $data;
    }

    /**
     * Best available project image (featured photo first, then any gallery
     * photo). Columns are array-cast on the Project model but may be null.
     */
    private function projectImage(): ?string
    {
        $featured = $this->project->featured_photo;
        $gallery  = $this->project->photos_url;

        if (is_array($featured) && count($featured)) {
            return $featured[0];
        }
        if (is_string($featured) && $featured !== '') {
            return $featured;
        }
        if (is_array($gallery) && count($gallery)) {
            return $gallery[0];
        }

        return null;
    }
}
