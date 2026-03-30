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
            ->whereHas('ad', function ($q) {
                $q->where('status', 'active')
                    ->whereHas('campaign', function ($cq) {
                        $cq->where('status', 'active')
                            ->where(function ($dq) {
                                $dq->whereNull('starts_at')
                                    ->orWhere('starts_at', '<=', now('Asia/Manila'));
                            })
                            ->where(function ($dq) {
                                $dq->whereNull('ends_at')
                                    ->orWhere('ends_at', '>=', now('Asia/Manila'));
                            });
                    });
            })
            ->with(['ad.campaign'])
            ->get();

        $fixed = $placements->where('is_fixed', true)
            ->sortByDesc('priority')
            ->values();

        $pool = $placements->where('is_fixed', false);

        $remainingSlots = max(0, $section->max_ads - $fixed->count());

        $selected = $this->weightedSelect($pool, $remainingSlots);

        return $fixed->merge($selected)->map(function ($placement) use ($section) {
            $ad = $placement->ad;
            $ad->section = $section;
            return $ad;
        });
    }

    private function weightedSelect(Collection $pool, int $slots): Collection
    {
        if ($slots <= 0 || $pool->isEmpty()) {
            return collect();
        }

        $grouped = $pool->groupBy('priority')->sortKeysDesc();
        $selected = collect();

        foreach ($grouped as $tier) {
            if ($selected->count() >= $slots) break;

            $remaining = $slots - $selected->count();
            $candidates = $tier->values();

            if ($candidates->count() <= $remaining) {
                $selected = $selected->merge($candidates);
                continue;
            }

            // Weighted random selection within tier
            $available = $candidates->all();
            for ($i = 0; $i < $remaining && !empty($available); $i++) {
                $totalWeight = array_sum(array_map(fn($p) => $p->weight, $available));
                if ($totalWeight <= 0) break;

                $rand = mt_rand(1, $totalWeight);
                $cumulative = 0;

                foreach ($available as $key => $placement) {
                    $cumulative += $placement->weight;
                    if ($rand <= $cumulative) {
                        $selected->push($placement);
                        unset($available[$key]);
                        break;
                    }
                }
            }
        }

        return $selected;
    }
}
