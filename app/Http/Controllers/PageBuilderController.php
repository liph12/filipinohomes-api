<?php

namespace App\Http\Controllers;

use App\Http\Resources\PageBuilderResource;
use App\Models\PageBuilder;
use Illuminate\Http\Request;
use App\Models\Agent;
class PageBuilderController extends Controller
{
    public function index()
    {
        return PageBuilderResource::collection(PageBuilder::paginate(10));
    }

    public function show(string $slug)
    {
        $pageBuilder = PageBuilder::where('slug', $slug)->firstOrFail();
        return new PageBuilderResource($pageBuilder);
    }

public function store(Request $request)
{
    $user = $request->user();

    $agent = Agent::where('user_id', $user->id)->first();

    if (!$agent) {
        return response()->json([
            'success' => false,
            'message' => 'You must have an agent profile to create a page.',
        ], 403);
    }

    // Check if agent already has a page
    if (PageBuilder::where('agent_id', $agent->id)->exists()) {
        return response()->json([
            'success' => false,
            'message' => 'You can only have one page.',
        ], 403);
    }

    $data = $request->validate([
        'title'       => 'required|string',
        'slug'        => 'nullable|string|unique:page_builder,slug',
        'seo_tags'    => 'nullable|array',
        'description' => 'nullable|string',
        'banner'      => 'nullable|array',
        'gallery'     => 'nullable|array',
        'youtube'     => 'nullable|array',
        'is_featured' => 'boolean',
    ]);

    $data['agent_id'] = $agent->id;

    $page = PageBuilder::create($data);

    return new PageBuilderResource($page);
}

    public function update(Request $request, $id)
    {
        $pageBuilder = PageBuilder::findOrFail($id);
        $this->authorize('update', $pageBuilder);

        $data = $request->validate([
            'title'       => 'sometimes|required|string',
            'slug'        => 'sometimes|nullable|string|unique:page_builder,slug,' . $pageBuilder->id,
            'seo_tags'    => 'nullable|array',
            'description' => 'nullable|string',
            'banner'      => 'nullable|array',
            'gallery'     => 'nullable|array',
            'youtube'     => 'nullable|array',
            'is_featured' => 'boolean',
        ]);

        $pageBuilder->update($data);

        return new PageBuilderResource($pageBuilder);
    }
}
