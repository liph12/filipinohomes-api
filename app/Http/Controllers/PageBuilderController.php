<?php

namespace App\Http\Controllers;

use App\Http\Resources\PageBuilderResource;
use App\Models\PageBuilder;
use Illuminate\Http\Request;

class PageBuilderController extends Controller
{
    public function index()
    {
        return PageBuilderResource::collection(PageBuilder::paginate(10));
    }

    public function show(PageBuilder $pageBuilder)
    {
        return new PageBuilderResource($pageBuilder);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string',
            'slug' => 'required|string|unique:page_builder,slug',
            'seo_tags' => 'nullable|array',
            'description' => 'nullable|string',
            'banner' => 'nullable|array',
            'gallery' => 'nullable|array',
            'youtube' => 'nullable|array',
            'is_featured' => 'boolean',
            'agent_id' => 'nullable|exists:agents,id',
        ]);

        $page = PageBuilder::create($data);

        return new PageBuilderResource($page);
    }

    public function update(Request $request, PageBuilder $pageBuilder)
    {
        $data = $request->validate([
            'title' => 'sometimes|required|string',
            'slug' => 'sometimes|required|string|unique:page_builder,slug,' . $pageBuilder->id,
            'seo_tags' => 'nullable|array',
            'description' => 'nullable|string',
            'banner' => 'nullable|array',
            'gallery' => 'nullable|array',
            'youtube' => 'nullable|array',
            'is_featured' => 'boolean',
            'agent_id' => 'nullable|exists:agents,id',
        ]);

        $pageBuilder->update($data);

        return new PageBuilderResource($pageBuilder);
    }

    public function destroy(PageBuilder $pageBuilder)
    {
        $pageBuilder->delete();

        return response()->noContent();
    }
}
