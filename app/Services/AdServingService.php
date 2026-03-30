<?php

namespace App\Services;

use App\Models\AdSection;
use App\Models\AdPlacement;
use Illuminate\Support\Collection;

class AdServingService
{
    public function getAdsForSection(string $sectionKey): Collection
    {
        $section = AdSection::where('key', $sectionKey)->first();

        if (!$section) {
            return collect();
        }

        $placements = AdPlacement::where('ad_section_id', $section->id)
            ->whereHas('ad', fn($q) => $q->where('status', 'active')
                ->whereHas('campaign', fn($cq) => $cq->active()))
            ->with(['ad.campaign'])
            ->orderByDesc('is_fixed')
            ->orderByDesc('priority')
            ->orderByDesc('weight')
            ->get();

        return $placements->map(function ($placement) use ($section) {
            $ad = $placement->ad;
            $ad->section = $section;
            return $ad;
        });
    }
}
