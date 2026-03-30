<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $impressions = $this->analytics->sum('impressions');
        $totalImpressions = $this->analytics->sum('total_impressions');
        $clicks = $this->analytics->sum('clicks');
        $totalClicks = $this->analytics->sum('total_clicks');

        return [
            'id' => $this->id,
            'ad_campaign_id' => $this->ad_campaign_id,
            'campaign' => new AdCampaignResource($this->whenLoaded('campaign')),
            'title' => $this->title,
            'image_path' => $this->image_path,
            'click_url' => $this->click_url,
            'alt_text' => $this->alt_text,
            'status' => $this->status,
            'impressions' => $impressions,
            'total_impressions' => $totalImpressions,
            'clicks' => $clicks,
            'total_clicks' => $totalClicks,
            'ctr' => $impressions > 0
                ? round(($clicks / $impressions) * 100, 2)
                : 0,
            'placements' => AdPlacementResource::collection($this->whenLoaded('placements')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
