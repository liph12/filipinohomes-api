<?php

namespace App\Http\Controllers;

use App\Models\Ad;
use App\Services\AdServingService;
use App\Http\Resources\AdResource;
use App\Http\Resources\AdSectionResource;

class PublicAdController extends Controller
{
    public function __construct(
        private AdServingService $adServingService
    ) {}

    public function show(string $key)
    {
        $ads = $this->adServingService->getAdsForSection($key);
        $section = \App\Models\AdSection::where('key', $key)->first();

        if ($ads->isEmpty()) {
            return response()->json([
                'data' => [],
                'section' => $section ? new AdSectionResource($section) : null,
            ]);
        }

        return response()->json([
            'data' => AdResource::collection($ads),
            'section' => $section ? new AdSectionResource($section) : null,
        ]);
    }

    public function trackImpression(int $id)
    {
        $ad = Ad::find($id);
        if (!$ad) {
            return response()->json(['message' => 'Ad not found'], 404);
        }

        $ad->increment('impressions');

        return response()->json(['success' => true]);
    }

    public function trackClick(int $id)
    {
        $ad = Ad::find($id);
        if (!$ad) {
            return response()->json(['message' => 'Ad not found'], 404);
        }

        $ad->increment('clicks');

        return response()->json([
            'success' => true,
            'click_url' => $ad->click_url,
        ]);
    }
}
