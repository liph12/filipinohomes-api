<?php

namespace App\Http\Controllers;

use App\Http\Resources\PageBuilderResource;
use App\Models\Agent;
use App\Models\PageBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PageBuilderController extends Controller
{
    private function trackingIdentifier(Request $request): string
    {
        if ($request->user()) {
            return 'user_'.$request->user()->id;
        }

        $deviceId = $request->input('device_id')
            ?? $request->header('X-Device-Id')
            ?? $request->header('x-device-id')
            ?? $request->cookie('device_id');

        if ($deviceId) {
            return 'dev_'.(string) $deviceId.'|'.(string) $request->ip();
        }

        $ua = (string) ($request->userAgent() ?? 'unknown');
        $ip = (string) $request->ip();

        return 'guest_'.substr(hash('sha256', $ip.'|'.$ua), 0, 32);
    }

    public function index(Request $request)
    {
        $perPage = (int) ($request->input('per_page', 12));

        $query = PageBuilder::query();
        $this->applyPageSearch($query, (string) $request->input('search', ''));

        // Default order: most-viewed first (biggest clicks → smallest) when no
        // explicit sort is requested.
        if (! $this->applyPageSort($query, $request)) {
            $query->orderByDesc('clicks');
        }

        return PageBuilderResource::collection($query->paginate($perPage));
    }

    /**
     * Filter agent pages by title or the page agent's name. Shared by the
     * active list (index) and the trashed list (deleted).
     */
    protected function applyPageSearch($query, string $search): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }

        $query->where(function ($q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
                ->orWhereHas('agent', function ($a) use ($search) {
                    $a->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                });
        });
    }

    /**
     * Server-side sort across the WHOLE dataset (not just the current page).
     * Supported: title, views (clicks), agent (by the page agent's name).
     * Returns false (no order applied) when no/unknown sort is requested so
     * the caller can fall back to its default ordering.
     */
    protected function applyPageSort($query, Request $request): bool
    {
        $sortBy = (string) $request->input('sort_by', '');
        $sortDir = strtolower((string) $request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        switch ($sortBy) {
            case 'title':
                $query->orderBy('title', $sortDir);

                return true;
            case 'views':
                $query->orderBy('clicks', $sortDir);

                return true;
            case 'created':
                $query->orderBy('created_at', $sortDir);

                return true;
            case 'agent':
                // Order by the related agent's name via a correlated subquery
                // so pagination + select(*) stay intact (no join duplication).
                $query
                    ->orderBy(Agent::select('first_name')->whereColumn('agents.id', 'page_builder.agent_id'), $sortDir)
                    ->orderBy(Agent::select('last_name')->whereColumn('agents.id', 'page_builder.agent_id'), $sortDir);

                return true;
            default:
                return false;
        }
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
            'available' => ! $query->exists(),
        ]);
    }

    public function show(string $slug)
    {
        $pageBuilder = PageBuilder::where('slug', $slug)->with('agent')->firstOrFail();
        $this->guardInactiveAgentPage($pageBuilder);

        return new PageBuilderResource($pageBuilder);
    }

    public function showByAgent(string $agentId)
    {
        $pageBuilder = PageBuilder::where('agent_id', $agentId)->with('agent')->firstOrFail();
        $this->guardInactiveAgentPage($pageBuilder);

        return new PageBuilderResource($pageBuilder);
    }

    /**
     * Public visibility gate for an agent page: a page whose owning agent is no
     * longer active (inactive/resigned/deactivated) or is soft-deleted 404s for
     * the public — matching the site-wide agent-status gate on listings — while
     * the owning agent, admins, and region secretaries keep access. These routes
     * carry no auth middleware, so the token is resolved on demand via the
     * sanctum guard.
     */
    private function guardInactiveAgentPage(PageBuilder $page): void
    {
        $agent = $page->agent;
        if ($agent && $agent->status === 'active') {
            return;
        }

        $user = auth('sanctum')->user();
        $privileged = $user && (
            $user->role?->name === 'admin'
            || ($user->agent && $user->agent->id === $page->agent_id)
            || ($user->isSecretary()
                && $user->secretaryRegion() !== null
                && optional($agent)->region === $user->secretaryRegion())
        );

        if (! $privileged) {
            abort(404);
        }
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $agent = Agent::where('user_id', $user->id)->first();

        if (! $agent) {
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
            'title' => 'required|string',
            'slug' => 'nullable|string|unique:page_builder,slug',
            'seo_tags' => 'nullable|array',
            'description' => 'nullable|string',
            'about_me' => 'nullable|string',
            'about_photo' => 'nullable|string|max:2048',
            'heading' => 'nullable|string|max:255',
            'theme' => 'nullable|array',
            'theme.gold' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.brand' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.title' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.description' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.overlay' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'banner_settings' => 'nullable|array',
            'banner_settings.pos_x' => 'nullable|integer|min:0|max:100',
            'banner_settings.pos_y' => 'nullable|integer|min:0|max:100',
            'banner_settings.overlay' => 'nullable|integer|min:0|max:100',
            'banner_settings.zoom' => 'nullable|integer|min:100|max:300',
            'featured_listings' => 'nullable|array',
            'featured_listings.*' => 'integer',
            'banner' => 'nullable|array',
            'gallery' => 'nullable|array',
            'flyers' => 'nullable|array',
            'certificates' => 'nullable|array',
            'awards' => 'nullable|array',
            'video_url' => 'nullable|array',
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
            'title' => 'sometimes|required|string',
            'slug' => 'sometimes|nullable|string|unique:page_builder,slug,'.$pageBuilder->id,
            'seo_tags' => 'nullable|array',
            'description' => 'nullable|string',
            'about_me' => 'nullable|string',
            'about_photo' => 'nullable|string|max:2048',
            'heading' => 'nullable|string|max:255',
            'theme' => 'nullable|array',
            'theme.gold' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.brand' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.title' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.description' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.overlay' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'banner_settings' => 'nullable|array',
            'banner_settings.pos_x' => 'nullable|integer|min:0|max:100',
            'banner_settings.pos_y' => 'nullable|integer|min:0|max:100',
            'banner_settings.overlay' => 'nullable|integer|min:0|max:100',
            'banner_settings.zoom' => 'nullable|integer|min:100|max:300',
            'featured_listings' => 'nullable|array',
            'featured_listings.*' => 'integer',
            'banner' => 'nullable|array',
            'gallery' => 'nullable|array',
            'flyers' => 'nullable|array',
            'certificates' => 'nullable|array',
            'awards' => 'nullable|array',
            'video_url' => 'nullable|array',
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
            'message' => 'Page deleted successfully.',
        ]);
    }

    public function restore(Request $request, $id)
    {
        $pageBuilder = PageBuilder::withTrashed()->findOrFail($id);
        $this->authorize('delete', $pageBuilder);
        $pageBuilder->restore();

        return response()->json([
            'success' => true,
            'message' => 'Page restored successfully.',
        ]);
    }

    public function deleted(Request $request)
    {
        $this->authorize('viewDeleted', PageBuilder::class);

        $perPage = (int) ($request->input('per_page', 12));
        $q = PageBuilder::onlyTrashed();
        $this->applyPageSearch($q, (string) $request->input('search', ''));
        // Use the requested sort if any; otherwise default to newest-deleted.
        if (! $this->applyPageSort($q, $request)) {
            $q->orderByDesc('deleted_at');
        }

        return PageBuilderResource::collection($q->paginate($perPage));
    }

    public function trackImpression(Request $request, string $slug)
    {
        $page = PageBuilder::where('slug', $slug)->first();
        if (! $page) {
            return response()->json(['message' => 'Page not found'], 404);
        }

        $identifier = $this->trackingIdentifier($request);

        $now = now('Asia/Manila');
        $today = $now->toDateString();
        $cacheKey = "{$identifier}_page_{$slug}_imp_{$today}";

        if (! Cache::has($cacheKey)) {
            $page->increment('impressions');
            Cache::put($cacheKey, true, $now->copy()->endOfDay());
        }

        return response()->json(['success' => true]);
    }

    public function trackClick(Request $request, string $slug)
    {
        $identifier = $this->trackingIdentifier($request);

        $page = PageBuilder::where('slug', $slug)->first();
        if (! $page) {
            return response()->json(['message' => 'Page not found'], 404);
        }

        $now = now('Asia/Manila');
        $today = $now->toDateString();

        $clickKey = "{$identifier}_page_{$slug}_click";
        $impKey = "{$identifier}_page_{$slug}_imp_{$today}";
        // Ensure impression counted if somehow missed
        if (! Cache::has($impKey)) {
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
