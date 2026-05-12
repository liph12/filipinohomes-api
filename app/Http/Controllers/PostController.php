<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PostController extends Controller
{
    /**
     * Whitelist the user columns surfaced via the `author` relation
     * on every blog response. Keep password / OTP / role internals
     * out of the public API surface; expose only what the frontend
     * BlogPosting `author: Person` schema and the rendered byline
     * card need.
     */
    private const AUTHOR_COLUMNS = [
        'id',
        'name',
        'slug',
        'avatar',
        'bio',
        'credentials',
    ];

    public function index(Request $request)
    {
        $sort = $request->query('sort');

        $query = Post::with(['category', 'author:' . implode(',', self::AUTHOR_COLUMNS)])
            ->whereNotNull('published_at');

        if ($sort === 'views') {
            $query->orderByDesc('views')
                ->orderByDesc('published_at');
        } else {
            $query->orderByDesc('published_at');
        }

        return response()->json(
            $query->paginate(10)
        );
    }

    public function show(string $slug)
    {
        $post = Post::with(['category', 'author:' . implode(',', self::AUTHOR_COLUMNS)])
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json($post);
    }

    public function trackView(Request $request, string $slug)
    {
        $post = Post::where('slug', $slug)->firstOrFail();
        $deviceId = $this->resolveDeviceId($request);

        $cacheKey = "blogs:view:{$post->id}:{$deviceId}";

        $recorded = false;
        if (!Cache::has($cacheKey)) {
            $post->increment('views');
            Cache::put($cacheKey, true, now()->addYear());
            $recorded = true;
        }

        return response()->json([
            'success' => true,
            'recorded' => $recorded,
            'views' => (int) $post->fresh()->views,
        ]);
    }

    public function trackImpression(Request $request, string $slug)
    {
        $post = Post::where('slug', $slug)->firstOrFail();
        $deviceId = $this->resolveDeviceId($request);

        $cacheKey = "blogs:impression:{$post->id}:{$deviceId}";

        $recorded = false;
        if (!Cache::has($cacheKey)) {
            $post->increment('impressions');
            Cache::put($cacheKey, true, now()->addYear());
            $recorded = true;
        }

        return response()->json([
            'success' => true,
            'recorded' => $recorded,
            'impressions' => (int) $post->fresh()->impressions,
        ]);
    }

    private function resolveDeviceId(Request $request): string
    {
        $header = trim((string) $request->header('X-Device-Id', ''));
        if ($header !== '') return $header;

        $body = trim((string) $request->input('device_id', ''));
        if ($body !== '') return $body;

        return hash('sha256', ($request->ip() ?? 'unknown') . '|' . ((string) $request->userAgent() ?: 'ua'));
    }
}
