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

    /**
     * Keys we never want surfaced in the activity feed even if old rows
     * captured them. Mirrors Listing::$auditExclude — kept in sync so the
     * write-side exclude and the read-side scrub stay aligned.
     */
    public const SCRUB_KEYS = [
        'clicks',
        'impressions',
        'updated_at',
        'seo_tags',
    ];

    /**
     * Strip SCRUB_KEYS from old_values / new_values on each row. If both
     * diff sides become empty after stripping, the row carried no real
     * change and is dropped from the feed entirely.
     */
    public static function scrubRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $old = is_array($row['old_values'] ?? null) ? $row['old_values'] : [];
            $new = is_array($row['new_values'] ?? null) ? $row['new_values'] : [];
            foreach (self::SCRUB_KEYS as $k) {
                unset($old[$k], $new[$k]);
            }
            $row['old_values'] = $old;
            $row['new_values'] = $new;

            // Keep custom events (audited, resubmitted, deleted, etc.) even
            // if their diff is empty — the event itself is meaningful.
            $event = $row['event'] ?? '';
            $isMeaningfulEvent = in_array(
                $event,
                ['created', 'deleted', 'audited', 'resubmitted', 'restored'],
                true
            );
            if (!$isMeaningfulEvent && empty($old) && empty($new)) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

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

        $paginated = $query->paginate($perPage);
        $rows = self::scrubRows($paginated->items() ? array_map(
            fn($m) => $m->toArray(),
            $paginated->items()
        ) : []);

        return response()->json([
            'data'         => $rows,
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'per_page'     => $paginated->perPage(),
            'total'        => $paginated->total(),
            'from'         => $paginated->firstItem(),
            'to'           => $paginated->lastItem(),
        ]);
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
