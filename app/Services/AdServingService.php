<?php

namespace App\Services;

use App\Models\AdPlacement;
use App\Models\AdSection;

class AdServingService
{
    public function getAdsForSection(string $sectionKey): array
    {
        $section = AdSection::where('key', $sectionKey)->first();

        if (! $section) {
            return ['ads' => collect(), 'loop_duration' => 5];
        }

        $now = now('Asia/Manila');

        $placements = AdPlacement::where('ad_section_id', $section->id)
            ->whereHas('ad', fn ($q) => $q->where('status', 'active')
                ->where(function ($aq) use ($now) {
                    $aq->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                })
                ->where(function ($aq) use ($now) {
                    $aq->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
                })
                ->whereHas('campaign', fn ($cq) => $cq->active()))
            ->with(['ad.campaign', 'ad' => fn ($q) => $q->withAnalyticsTotals()])
            ->orderByDesc('is_fixed')
            ->orderByDesc('priority')
            ->orderByDesc('weight')
            ->get();

        $loopDuration = 5;
        $ads = $placements->map(function ($placement) use ($section, &$loopDuration) {
            $ad = $placement->ad;
            $ad->section = $section;

            if ($ad->campaign && $ad->campaign->loop_duration) {
                $loopDuration = $ad->campaign->loop_duration;
            }

            return $ad;
        });

        return ['ads' => $ads, 'loop_duration' => $loopDuration];
    }
}
