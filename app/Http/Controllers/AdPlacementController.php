<?php

namespace App\Http\Controllers;

use App\Models\AdPlacement;
use App\Http\Resources\AdPlacementResource;
use Illuminate\Http\Request;

class AdPlacementController extends Controller
{
    public function index(Request $request)
    {
        $query = AdPlacement::with(['ad', 'section']);

        if ($adId = $request->input('ad_id')) {
            $query->where('ad_id', $adId);
        }

        if ($sectionId = $request->input('ad_section_id')) {
            $query->where('ad_section_id', $sectionId);
        }

        $placements = $query->orderByDesc('priority')->paginate($request->input('per_page', 50));

        return AdPlacementResource::collection($placements);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'ad_id' => 'required|exists:ads,id',
            'ad_section_id' => 'required|exists:ad_sections,id',
            'priority' => 'integer|min:0',
            'weight' => 'integer|min:1',
            'is_fixed' => 'boolean',
        ]);

        $validated['priority'] = $validated['priority'] ?? 0;
        $validated['weight'] = $validated['weight'] ?? 1;
        $validated['is_fixed'] = $validated['is_fixed'] ?? false;

        $placement = AdPlacement::create($validated);

        return new AdPlacementResource($placement->load(['ad', 'section']));
    }

    public function show($id)
    {
        $placement = AdPlacement::with(['ad', 'section'])->findOrFail($id);

        return new AdPlacementResource($placement);
    }

    public function update(Request $request, $id)
    {
        $placement = AdPlacement::findOrFail($id);

        $validated = $request->validate([
            'ad_id' => 'sometimes|exists:ads,id',
            'ad_section_id' => 'sometimes|exists:ad_sections,id',
            'priority' => 'integer|min:0',
            'weight' => 'integer|min:1',
            'is_fixed' => 'boolean',
        ]);

        $placement->update($validated);

        return new AdPlacementResource($placement->load(['ad', 'section']));
    }

    public function destroy($id)
    {
        $placement = AdPlacement::findOrFail($id);
        $placement->delete();

        return response()->json(['message' => 'Placement deleted', 'id' => $placement->id]);
    }

    public function bulkStore(Request $request)
    {
        $validated = $request->validate([
            'placements' => 'required|array|min:1',
            'placements.*.ad_id' => 'required|exists:ads,id',
            'placements.*.ad_section_id' => 'required|exists:ad_sections,id',
            'placements.*.priority' => 'integer|min:0',
            'placements.*.weight' => 'integer|min:1',
            'placements.*.is_fixed' => 'boolean',
        ]);

        $created = [];
        foreach ($validated['placements'] as $data) {
            $data['priority'] = $data['priority'] ?? 0;
            $data['weight'] = $data['weight'] ?? 1;
            $data['is_fixed'] = $data['is_fixed'] ?? false;

            $created[] = AdPlacement::updateOrCreate(
                ['ad_id' => $data['ad_id'], 'ad_section_id' => $data['ad_section_id']],
                $data
            );
        }

        $ids = array_map(fn($p) => $p->id, $created);

        $placements = AdPlacement::with(['ad', 'section'])->whereIn('id', $ids)->get();

        return AdPlacementResource::collection($placements);
    }
}
