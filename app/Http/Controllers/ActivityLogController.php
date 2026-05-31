<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Audit;
use App\Services\TeamLeadershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        // Login / authentication events. Direct Audit::create writes
        // from AuditAuthService (login isn't a model mutation, so no
        // LogsActivity trait involved).
        'auth',
        // Outbound mail outcomes. Success writes are fired from the
        // global MessageSent listener; failure writes are fired from
        // try/catch blocks around every Mail::send call site via
        // AuditMailService::recordFailure().
        'mailer',
    ];

    /**
     * Keys we never want surfaced in the activity feed even if old rows
     * captured them. Mirrors Listing::$auditExclude — kept in sync so the
     * write-side exclude and the read-side scrub stay aligned.
     */
    public const SCRUB_KEYS = [
        'clicks',
        'impressions',
        'views',
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
            // Keep rows that carry a human description even when the
            // diff is empty. New event families (logged_in, mailer_sent,
            // mailer_failed, inquiry_*) are description-only — they
            // don't mutate any model field, so old/new are always empty,
            // but the row IS meaningful.
            $hasDescription = !empty(trim((string) ($row['description'] ?? '')));
            if (!$isMeaningfulEvent && !$hasDescription && empty($old) && empty($new)) {
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

        // Role filter — plain values (admin/agent/client/editor) match
        // the `user_role` column directly. `team_leader` is special-cased
        // because TL status is computed from the team_agents pivot, not
        // stored on the audit row; we resolve the current TL user_id set
        // and restrict the query to those ids. The Agent filter remains
        // inclusive of TLs (an admin auditing "all agent activity" would
        // expect TL activity to show up too); the team_leader filter is
        // an additive narrow-down.
        if ($role = trim((string) $request->input('role', ''))) {
            if ($role === 'team_leader') {
                $tlUserIds = Agent::query()
                    ->join('team_agents', 'agents.id', '=', 'team_agents.agent_id')
                    ->where('team_agents.is_leader', true)
                    ->where('team_agents.status', 'active')
                    ->pluck('agents.user_id')
                    ->all();
                if (empty($tlUserIds)) {
                    // No active TLs in the system — short-circuit to
                    // an empty result rather than emit `IN ()` which
                    // some MySQL versions choke on.
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('user_id', $tlUserIds);
                }
            } else {
                $query->where('user_role', $role);
            }
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

        // Enrich each row with `user_is_team_leader` so the frontend
        // can render the TEAM LEADER badge variant on TL agents
        // (otherwise they'd display as plain AGENT, since user_role
        // only captures the base role name). Computed at read time —
        // the audit row stores no TL flag, and historical accuracy
        // (was-TL-at-the-time) isn't critical for the admin UI's
        // current-state badge.
        $userIds = array_values(array_unique(array_filter(array_map(
            fn ($row) => isset($row['user_id']) ? (int) $row['user_id'] : null,
            $rows
        ))));
        if (!empty($userIds)) {
            $tlMap = app(TeamLeadershipService::class)->isTeamLeaderBulk($userIds);
            foreach ($rows as &$row) {
                $uid = isset($row['user_id']) ? (int) $row['user_id'] : 0;
                $row['user_is_team_leader'] = $uid ? ($tlMap[$uid] ?? false) : false;
            }
            unset($row);
        }

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

    /**
     * Overview tab top-of-page stats. Rolls up:
     *   - today / 7-day / 30-day total event counts
     *   - the busiest category in the last 7 days
     *   - mailer_failed count in the last 7 days (signal that SMTP /
     *     transport is unhealthy — admins can spot a brewing incident)
     */
    public function overviewStats(Request $request): JsonResponse
    {
        if (($request->user()->role->name ?? null) !== 'admin') {
            abort(403);
        }

        $now = now();
        $today = $now->copy()->startOfDay();
        $week  = $now->copy()->subDays(7);
        $month = $now->copy()->subDays(30);

        $todayCount = Audit::where('created_at', '>=', $today)->count();
        $weekCount  = Audit::where('created_at', '>=', $week)->count();
        $monthCount = Audit::where('created_at', '>=', $month)->count();

        $topCategory = Audit::query()
            ->selectRaw('category, COUNT(*) as total')
            ->where('created_at', '>=', $week)
            ->whereNotNull('category')
            ->groupBy('category')
            ->orderByDesc('total')
            ->first();

        $failedMailer = Audit::where('event', 'mailer_failed')
            ->where('created_at', '>=', $week)
            ->count();

        return response()->json([
            'today'             => $todayCount,
            'week'              => $weekCount,
            'month'             => $monthCount,
            'top_category'      => $topCategory?->category,
            'top_category_count' => (int) ($topCategory?->total ?? 0),
            'failed_mailer_week' => $failedMailer,
        ]);
    }

    /**
     * Management tab storage panel. Returns the audit table footprint
     * (total rows + byte size via information_schema), the freshest and
     * oldest entries, the four age buckets driving the distribution
     * chart, and a per-threshold deletion preview so the Clear panel
     * can show "N records will be permanently deleted" without an
     * extra round-trip per dropdown change.
     */
    public function storageOverview(Request $request): JsonResponse
    {
        if (($request->user()->role->name ?? null) !== 'admin') {
            abort(403);
        }

        $total  = Audit::count();
        $oldest = Audit::min('created_at');
        $newest = Audit::max('created_at');

        // Bytes via information_schema. Wrapped in a try/catch since
        // some hosting setups (managed RDS read replicas, restricted
        // accounts) block direct information_schema reads — in that
        // case we fall back to a rough per-row estimate so the panel
        // still renders something useful.
        $sizeBytes = 0;
        try {
            $tableName = (new Audit)->getTable();
            $row = DB::selectOne(
                'SELECT (data_length + index_length) AS size_bytes
                   FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = ?',
                [$tableName]
            );
            $sizeBytes = (int) ($row->size_bytes ?? 0);
        } catch (\Throwable $e) {
            // Conservative average — most audit rows land between
            // 0.7 KB (login events) and 2 KB (full diff rows). 1 KB
            // is a defensible middle estimate when the real query
            // path isn't available.
            $sizeBytes = (int) round($total * 1024);
        }

        $now = now();
        $bucket30  = Audit::where('created_at', '>=', $now->copy()->subDays(30))->count();
        $bucket90  = Audit::whereBetween('created_at', [
            $now->copy()->subDays(90),
            $now->copy()->subDays(30),
        ])->count();
        $bucket180 = Audit::whereBetween('created_at', [
            $now->copy()->subDays(180),
            $now->copy()->subDays(90),
        ])->count();
        $bucketOld = Audit::where('created_at', '<', $now->copy()->subDays(180))->count();

        // Deletion-preview counts for the canonical Clear-panel
        // thresholds. Pre-computed here so the frontend can switch
        // the dropdown without re-hitting the network.
        $previewThresholds = [30, 60, 90, 180, 365];
        $deletionPreview = [];
        foreach ($previewThresholds as $days) {
            $deletionPreview[(string) $days] = Audit::where(
                'created_at',
                '<',
                $now->copy()->subDays($days)
            )->count();
        }

        return response()->json([
            'total_records' => $total,
            'size_bytes'    => $sizeBytes,
            'oldest_entry'  => $oldest,
            'newest_entry'  => $newest,
            'age_distribution' => [
                ['key' => 'last_30',   'label' => 'Last 30 days',        'count' => $bucket30],
                ['key' => '30_90',     'label' => '30-90 days',          'count' => $bucket90],
                ['key' => '90_180',    'label' => '90-180 days',         'count' => $bucket180],
                ['key' => 'older_180', 'label' => 'Older than 180 days', 'count' => $bucketOld],
            ],
            'deletion_preview' => $deletionPreview,
        ]);
    }

    /**
     * Permanently delete all audit rows older than `older_than_days`.
     * Destructive — the frontend gates this behind a confirmation
     * dialog, but admin-role enforcement happens here regardless.
     */
    public function clearOldLogs(Request $request): JsonResponse
    {
        if (($request->user()->role->name ?? null) !== 'admin') {
            abort(403);
        }

        $data = $request->validate([
            // 1 day floor protects against a fat-finger threshold that
            // would wipe everything. 10-year ceiling is a sanity bound.
            'older_than_days' => 'required|integer|min:1|max:3650',
        ]);

        $cutoff = now()->subDays((int) $data['older_than_days']);
        $count  = Audit::where('created_at', '<', $cutoff)->count();
        Audit::where('created_at', '<', $cutoff)->delete();

        return response()->json([
            'deleted' => $count,
            'cutoff'  => $cutoff->toISOString(),
        ]);
    }

    /**
     * Stream audit rows out as CSV or JSON. Streamed (not buffered)
     * so a large export doesn't OOM the PHP worker on a 100K-row
     * table — chunkById walks the table in 500-row pages.
     */
    public function exportLogs(Request $request)
    {
        if (($request->user()->role->name ?? null) !== 'admin') {
            abort(403);
        }

        $data = $request->validate([
            'from'   => 'nullable|date',
            'to'     => 'nullable|date',
            'format' => 'required|in:csv,json',
        ]);

        $query = Audit::query()->orderBy('id');
        if (!empty($data['from'])) {
            $query->whereDate('created_at', '>=', $data['from']);
        }
        if (!empty($data['to'])) {
            $query->whereDate('created_at', '<=', $data['to']);
        }

        $stamp    = now()->format('Ymd-His');
        $filename = "activity-logs-{$stamp}.{$data['format']}";

        // CSV columns mirror the shape the activity-logs page already
        // surfaces. JSON dump includes the full row including
        // old_values/new_values so a forensic export keeps the diff.
        $csvColumns = [
            'id', 'created_at', 'category', 'event', 'source',
            'user_id', 'user_role', 'user_name',
            'auditable_type', 'auditable_id', 'subject_label',
            'description', 'ip_address', 'url',
        ];

        if ($data['format'] === 'csv') {
            return new StreamedResponse(function () use ($query, $csvColumns) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, $csvColumns);
                $query->chunkById(500, function ($chunk) use ($handle, $csvColumns) {
                    foreach ($chunk as $row) {
                        $line = [];
                        foreach ($csvColumns as $col) {
                            $val = $row->{$col};
                            $line[] = is_scalar($val) || $val === null ? (string) $val : json_encode($val);
                        }
                        fputcsv($handle, $line);
                    }
                });
                fclose($handle);
            }, 200, [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        }

        // JSON path — also streamed so a large export doesn't buffer
        // the whole result set in PHP memory.
        return new StreamedResponse(function () use ($query) {
            echo '[';
            $first = true;
            $query->chunkById(500, function ($chunk) use (&$first) {
                foreach ($chunk as $row) {
                    if (!$first) echo ',';
                    echo json_encode($row->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $first = false;
                }
            });
            echo ']';
        }, 200, [
            'Content-Type'        => 'application/json; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
