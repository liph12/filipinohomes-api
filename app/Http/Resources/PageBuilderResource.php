<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PageBuilderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'slug'        => $this->slug,
            'seo_tags'    => $this->seo_tags,
            'description' => $this->description,
            'banner'      => $this->banner,
            'gallery'     => $this->gallery,
            'video_url'   => $this->video_url,
            'clicks'      => $this->clicks,
            'impressions' => $this->impressions,
            'created_at'  => $this->created_at,
            'agent'       => new AgentPageResource($this->agent)
        ];
    }
}
