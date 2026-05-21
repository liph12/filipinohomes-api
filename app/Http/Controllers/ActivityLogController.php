<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * Canonical categories exposed in the UI filter. Adding a new category?
     * Add it here AND on the relevant model's $auditCategory.
     */
    public const CATEGORIES = [
        'listings',
        'listings_audit',
        'users',
        'agents',
        'projects',
        'ads',
        'content',
        'inquiries',
        'system',
    ];

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Paginated activity log feed. Admin-only.
     *
     * Query params:
     *   - category:   string|csv  (e.g. "listings" or "listings,listings_audit")
     *   - event:      string      (created/updated/deleted/audited/resubmitted/restored)
     *   - source:     string
     *   - user_id:    int
     *   - subject_id: int
     *   - subject_type: string    (full class or short, e.g. "Listing")
     *   - search:     string      (matches user_name / subject_label / description)
     *   - from / to:  date (YYYY-MM-DD)
     *   - per_page:   int (1..100, default 25)
     */
    public function index(Request $request): JsonResponse
    {
        if (($request->user()->role->name ?? null) !== 'admin') {
            abort(403);
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));

        $query = Audit::query()->latest('id');

        if ($category = $request->input('category')) {
            $cats = is_array($category) ? $category : explode(',', (string) $category);
            $cats = array_values(array_filter(array_map('trim', $cats)));
            if (!empty($cats)) $query->whereIn('category', $cats);
        }

        if ($event = $request->input('event')) {
            $query->where('event', $event);
        }

        if ($source = $request->input('source')) {
            $query->where('source', $source);
        }

        if ($userId = $request->input('user_id')) {
            $query->where('user_id', (int) $userId);
        }

        if ($subjectId = $request->input('subject_id')) {
            $query->where('auditable_id', (int) $subjectId);
        }

        if ($subjectType = $request->input('subject_type')) {
            // Accept either short class name ("Listing") or fully qualified.
            if (!str_contains($subjectType, '\\')) {
                $subjectType = 'App\\Models\\' . $subjectType;
            }
            $query->where('auditable_type', $subjectType);
        }

        if ($from = $request->input('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('user_name', 'like', $like)
                  ->orWhere('subject_label', 'like', $like)
                  ->orWhere('description', 'like', $like);
            });
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * Categories surfaced in the UI dropdown — canonical list + per-category
     * counts from the current dataset.
     */
    public function categories(Request $request): JsonResponse
    {
        if (($request->user()->role->name ?? null) !== 'admin') {
            abort(403);
        }

        $counts = Audit::query()
            ->selectRaw('category, COUNT(*) as total')
            ->whereNotNull('category')
            ->groupBy('category')
            ->orderBy('category')
            ->pluck('total', 'category')
            ->toArray();

        return response()->json([
            'all'    => self::CATEGORIES,
            'counts' => $counts,
        ]);
    }
}
