<?php

namespace App\Http\Controllers;

use App\Models\AdSection;
use App\Http\Resources\AdSectionResource;
use Illuminate\Http\Request;

class AdSectionController extends Controller
{
    public function index(Request $request)
    {
        $query = AdSection::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('key', 'like', "%{$search}%");
            });
        }

        if ($page = $request->input('page_prefix')) {
            $query->where('key', 'like', "{$page}.%");
        }

        $sections = $query->orderBy('key')->paginate($request->input('per_page', 50));

        return AdSectionResource::collection($sections);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'key' => 'required|string|max:255|unique:ad_sections,key',
            'description' => 'nullable|string',
            'max_ads' => 'required|integer|min:1',
            'width' => 'nullable|integer|min:1',
            'height' => 'nullable|integer|min:1',
        ]);

        $section = AdSection::create($validated);

        return new AdSectionResource($section);
    }

    public function show($id)
    {
        $section = AdSection::findOrFail($id);

        return new AdSectionResource($section);
    }

    public function update(Request $request, $id)
    {
        $section = AdSection::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'key' => "sometimes|string|max:255|unique:ad_sections,key,{$id}",
            'description' => 'nullable|string',
            'max_ads' => 'sometimes|integer|min:1',
            'width' => 'nullable|integer|min:1',
            'height' => 'nullable|integer|min:1',
        ]);

        $section->update($validated);

        return new AdSectionResource($section);
    }

    public function destroy($id)
    {
        $section = AdSection::findOrFail($id);
        $section->delete();

        return response()->json(['message' => 'Section deleted', 'id' => $section->id]);
    }
}
