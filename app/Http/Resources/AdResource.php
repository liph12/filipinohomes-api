<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $impressions = $this->analyticsTotal('impressions');
        $totalImpressions = $this->analyticsTotal('total_impressions');
        $clicks = $this->analyticsTotal('clicks');
        $totalClicks = $this->analyticsTotal('total_clicks');

        return [
            'id' => $this->id,
            'ad_campaign_id' => $this->ad_campaign_id,
            'campaign' => new AdCampaignResource($this->whenLoaded('campaign')),
            'title' => $this->title,
            'image_path' => $this->image_path,
            'click_url' => $this->click_url,
            'alt_text' => $this->alt_text,
            'status' => $this->status,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
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

    /**
     * Resolve an analytics total without ever triggering a lazy load.
     * Prefers the DB-side aggregate column added by Ad::scopeWithAnalyticsTotals
     * (analytics_sum_<column>); falls back to summing an already eager-loaded
     * analytics collection for callers that still load the full relation.
     */
    private function analyticsTotal(string $column): int
    {
        $aggregate = "analytics_sum_{$column}";
        $attributes = $this->resource->getAttributes();

        if (array_key_exists($aggregate, $attributes)) {
            return (int) ($attributes[$aggregate] ?? 0);
        }

        if ($this->resource->relationLoaded('analytics')) {
            return (int) $this->resource->analytics->sum($column);
        }

        return 0;
    }
}
