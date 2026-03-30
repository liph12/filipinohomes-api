<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ad_campaign_id' => $this->ad_campaign_id,
            'campaign' => new AdCampaignResource($this->whenLoaded('campaign')),
            'title' => $this->title,
            'image_path' => $this->image_path,
            'click_url' => $this->click_url,
            'alt_text' => $this->alt_text,
            'status' => $this->status,
            'impressions' => $this->impressions,
            'clicks' => $this->clicks,
            'ctr' => $this->impressions > 0
                ? round(($this->clicks / $this->impressions) * 100, 2)
                : 0,
            'placements' => AdPlacementResource::collection($this->whenLoaded('placements')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
