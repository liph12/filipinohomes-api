<?php

namespace App\Http\Controllers;

use App\Models\Ad;
use App\Http\Resources\AdResource;
use Illuminate\Http\Request;

class AdController extends Controller
{
    public function index(Request $request)
    {
        $query = Ad::with(['campaign', 'placements.section', 'analytics']);

        if ($campaignId = $request->input('campaign_id')) {
            $query->where('ad_campaign_id', $campaignId);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhereHas('campaign', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $ads = $query->latest()->paginate($request->input('per_page', 15));

        return AdResource::collection($ads);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'ad_campaign_id' => 'required|exists:ad_campaigns,id',
            'title' => 'required|string|max:255',
            'image_path' => 'required|string',
            'click_url' => 'required|url|max:2048',
            'alt_text' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
        ]);

        $ad = Ad::create($validated);

        return new AdResource($ad->load(['campaign', 'placements.section', 'analytics']));
    }

    public function show($id)
    {
        $ad = Ad::with(['campaign', 'placements.section', 'analytics'])->findOrFail($id);

        return new AdResource($ad);
    }

    public function update(Request $request, $id)
    {
        $ad = Ad::findOrFail($id);

        $validated = $request->validate([
            'ad_campaign_id' => 'sometimes|exists:ad_campaigns,id',
            'title' => 'sometimes|string|max:255',
            'image_path' => 'sometimes|string',
            'click_url' => 'sometimes|url|max:2048',
            'alt_text' => 'nullable|string|max:255',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $ad->update($validated);

        return new AdResource($ad->load(['campaign', 'placements.section', 'analytics']));
    }

    public function destroy($id)
    {
        $ad = Ad::findOrFail($id);
        $ad->delete();

        return response()->json(['message' => 'Ad deleted', 'id' => $ad->id]);
    }
}
