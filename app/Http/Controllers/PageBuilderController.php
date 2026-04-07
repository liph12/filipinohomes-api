<?php

namespace App\Http\Controllers;

use App\Http\Resources\PageBuilderResource;
use App\Models\PageBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Agent;
use Illuminate\Support\Facades\Cache;
class PageBuilderController extends Controller
{
    private function trackingIdentifier(Request $request): string
    {
        if ($request->user()) {
            return 'user_' . $request->user()->id;
        }

        $deviceId = $request->input('device_id')
            ?? $request->header('X-Device-Id')
            ?? $request->header('x-device-id')
            ?? $request->cookie('device_id');

        if ($deviceId) {
            return 'dev_' . (string) $deviceId . '|' . (string) $request->ip();
        }

        $ua = (string) ($request->userAgent() ?? 'unknown');
        $ip = (string) $request->ip();
        return 'guest_' . substr(hash('sha256', $ip . '|' . $ua), 0, 32);
    }
    public function index()
    {
        return PageBuilderResource::collection(PageBuilder::paginate(10));
    }

    public function checkSlug(Request $request): JsonResponse
    {
        $slug = $request->input('slug', '');
        $excludeId = $request->input('exclude_id');

        $query = PageBuilder::where('slug', $slug);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return response()->json([
            'available' => !$query->exists(),
        ]);
    }

    public function show(string $slug)
    {
        $pageBuilder = PageBuilder::where('slug', $slug)->firstOrFail();
        return new PageBuilderResource($pageBuilder);
    }

    public function showByAgent(string $agentId)
    {
        $pageBuilder = PageBuilder::where('agent_id', $agentId)->firstOrFail();
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
        'video_url'   => 'nullable|array',
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
            'video_url'   => 'nullable|array',
        ]);

        $pageBuilder->update($data);

        return new PageBuilderResource($pageBuilder);
    }

    public function destroy(Request $request, $id)
    {
        $pageBuilder = PageBuilder::findOrFail($id);
        $this->authorize('delete', $pageBuilder);

        $pageBuilder->delete();

        return response()->json([
            'success' => true,
            'message' => 'Page deleted successfully.'
        ]);
    }

    public function restore(Request $request, $id)
    {
        $pageBuilder = PageBuilder::withTrashed()->findOrFail($id);
        $this->authorize('delete', $pageBuilder);
        $pageBuilder->restore();

        return response()->json([
            'success' => true,
            'message' => 'Page restored successfully.'
        ]);
    }

    public function deleted(Request $request)
    {
        $this->authorize('viewDeleted', PageBuilder::class);

        $perPage = (int)($request->input('per_page', 10));
        $q = PageBuilder::onlyTrashed()->orderByDesc('deleted_at');
        return PageBuilderResource::collection($q->paginate($perPage));
    }

public function trackImpression(Request $request, string $slug)
{
    $page = PageBuilder::where('slug', $slug)->first();
    if (!$page) {
        return response()->json(['message' => 'Page not found'], 404);
    }

    $identifier = $this->trackingIdentifier($request);

    $now = now('Asia/Manila');
    $today = $now->toDateString();
    $cacheKey = "{$identifier}_page_{$slug}_imp_{$today}";

    if (!Cache::has($cacheKey)) {
        $page->increment('impressions');
        Cache::put($cacheKey, true, $now->copy()->endOfDay());
    }

    return response()->json(['success' => true]);
}

public function trackClick(Request $request, string $slug)
{
    $identifier = $this->trackingIdentifier($request);

    $page = PageBuilder::where('slug', $slug)->first();
    if (!$page) return response()->json(['message' => 'Page not found'], 404);

    $now = now('Asia/Manila');
    $today = $now->toDateString();

    $clickKey = "{$identifier}_page_{$slug}_click";
    $impKey   = "{$identifier}_page_{$slug}_imp_{$today}";
    // Ensure impression counted if somehow missed
    if (!Cache::has($impKey)) {
        $page->increment('impressions');
        Cache::put($impKey, true, $now->copy()->endOfDay());
    }

    // Count click once per day per device
    $lastClicked = Cache::get($clickKey);
    if ($lastClicked !== $today) {
        $page->increment('clicks');
        Cache::put($clickKey, $today, $now->copy()->endOfDay());
    }

    return response()->json(['success' => true]);
}
}
