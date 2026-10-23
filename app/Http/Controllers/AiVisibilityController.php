<?php

namespace App\Http\Controllers;

use App\Services\Audience\AiVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * AI-assistant visibility: where ChatGPT, Perplexity & co. open or send
 * people to our pages. Logic lives in AiVisibilityService.
 */
class AiVisibilityController extends Controller
{
    public function __construct(private AiVisibilityService $ai) {}

    /**
     * POST /track/ai-fetch — server-to-server from the web's proxy.ts when an
     * AI agent requests a page. Gated by the build token (the web already
     * holds it server-side), because a public endpoint would let anyone
     * inflate the counts. The UA is re-classified here, so an unknown agent
     * is dropped rather than trusted.
     */
    public function storeFetch(Request $request): JsonResponse
    {
        $token = (string) config('app.build_token', '');
        $given = (string) $request->header('X-Build-Token', '');
        if ($token === '' || $given === '' || ! hash_equals($token, $given)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'path' => 'required|string|max:2048',
            'user_agent' => 'required|string|max:512',
        ]);

        $hit = $this->ai->classifyAgent($data['user_agent']);
        if (! $hit) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $this->ai->record($hit['kind'], $hit['source'], $data['path'], $hit['agent']);

        return response()->json(['ok' => true, 'kind' => $hit['kind'], 'source' => $hit['source']]);
    }

    /**
     * POST /track/ai-click — public, from the web's VisitTracker on landing.
     * Re-classified server-side; one row per visitor per page per day so a
     * reload or remount doesn't count twice.
     */
    public function storeClick(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visitor_id' => 'nullable|string|max:64',
            'utm_source' => 'nullable|string|max:128',
            'referrer' => 'nullable|string|max:512',
            'path' => 'required|string|max:512',
        ]);

        $source = $this->ai->classifyClick($data['utm_source'] ?? null, $data['referrer'] ?? null);
        if (! $source) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $path = $this->ai->normalizePath($data['path']);
        if (! empty($data['visitor_id'])) {
            $key = 'ai-click:'.$data['visitor_id'].':'.md5($path).':'.now()->toDateString();
            if (! Cache::add($key, 1, now()->addDay())) {
                return response()->json(['ok' => true, 'deduped' => true]);
            }
        }

        $this->ai->record('click', $source, $path);

        return response()->json(['ok' => true, 'source' => $source]);
    }

    /** GET /admin/ai-visibility?from=&to=&page=&per_page=&sort= — admin report (route-gated). */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:10|max:100',
            'sort' => 'nullable|in:'.implode(',', AiVisibilityService::SORTS),
        ]);

        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return response()->json($this->ai->report(
            $from,
            $to,
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 50),
            $data['sort'] ?? 'views',
        ));
    }
}
