<?php

namespace App\Http\Controllers;

use App\Http\Resources\AdCampaignResource;
use App\Models\AdCampaign;
use Illuminate\Http\Request;

class AdCampaignController extends Controller
{
    public function index(Request $request)
    {
        $query = AdCampaign::withCount('ads');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('advertiser', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $campaigns = $query->latest()->paginate($request->input('per_page', 15));

        return AdCampaignResource::collection($campaigns);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'advertiser' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive,scheduled,expired',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'loop_duration' => 'nullable|integer|min:1|max:60',
        ]);

        $campaign = AdCampaign::create($validated);

        return new AdCampaignResource($campaign->loadCount('ads'));
    }

    public function show($id)
    {
        $campaign = AdCampaign::withCount('ads')
            ->with(['ads' => fn ($q) => $q->withAnalyticsTotals()])
            ->findOrFail($id);

        return new AdCampaignResource($campaign);
    }

    public function update(Request $request, $id)
    {
        $campaign = AdCampaign::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'advertiser' => 'nullable|string|max:255',
            'status' => 'sometimes|in:active,inactive,scheduled,expired',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'loop_duration' => 'nullable|integer|min:1|max:60',
        ]);

        $campaign->update($validated);

        return new AdCampaignResource($campaign->loadCount('ads'));
    }

    public function destroy($id)
    {
        $campaign = AdCampaign::findOrFail($id);
        $campaign->delete();

        return response()->json(['message' => 'Campaign deleted', 'id' => $campaign->id]);
    }
}
