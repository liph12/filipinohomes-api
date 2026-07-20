<?php

namespace App\Http\Controllers;

use App\Http\Resources\AdResource;
use App\Models\Ad;
use Illuminate\Http\Request;

class AdController extends Controller
{
    /**
     * Base query for ad responses: relations the resource renders plus DB-side
     * analytics totals (never the full analytics relation — see
     * Ad::scopeWithAnalyticsTotals).
     */
    private function baseQuery()
    {
        return Ad::query()
            ->with(['campaign', 'placements.section'])
            ->withAnalyticsTotals();
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery();

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
            // Optional — ad creatives without a click target are valid
            // (e.g. brand-impression ads). When provided it still has
            // to be a syntactically valid URL.
            'click_url' => 'nullable|url|max:2048',
            'alt_text' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]);

        $ad = Ad::create($validated);

        return new AdResource($this->baseQuery()->findOrFail($ad->id));
    }

    public function show($id)
    {
        $ad = $this->baseQuery()->findOrFail($id);

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
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]);

        $ad->update($validated);

        return new AdResource($this->baseQuery()->findOrFail($ad->id));
    }

    public function destroy($id)
    {
        $ad = Ad::findOrFail($id);
        $ad->delete();

        return response()->json(['message' => 'Ad deleted', 'id' => $ad->id]);
    }
}
