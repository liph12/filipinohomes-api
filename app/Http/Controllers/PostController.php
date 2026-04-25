<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $sort = $request->query('sort');

        $query = Post::with('category')
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

    public function show($slug)
    {
        $post = Post::with('category')
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json($post);
    }

    public function trackView(Request $request, $slug)
    {
        $post = Post::where('slug', $slug)->firstOrFail();

        $headerDeviceId = trim((string) $request->header('X-Device-Id', ''));
        $bodyDeviceId = trim((string) $request->input('device_id', ''));
        $deviceId = $headerDeviceId !== ''
            ? $headerDeviceId
            : ($bodyDeviceId !== '' ? $bodyDeviceId : hash('sha256', ($request->ip() ?? 'unknown') . '|' . ((string) $request->userAgent() ?: 'ua')));

        $today = now('Asia/Manila')->toDateString();
        $cacheKey = "blogs:view:{$post->id}:{$deviceId}:{$today}";

        $recorded = false;
        if (!Cache::has($cacheKey)) {
            $post->increment('views');
            Cache::put($cacheKey, true, now('Asia/Manila')->endOfDay());
            $recorded = true;
        }

        return response()->json([
            'success' => true,
            'recorded' => $recorded,
            'views' => (int) $post->fresh()->views,
        ]);
    }
}
