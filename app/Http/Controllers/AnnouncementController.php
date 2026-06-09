<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\ExpoPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnnouncementController extends Controller
{
    /**
     * Paginated history of sent announcements, newest first. Shape mirrors the
     * mobile `Paginated<T>` type (data + meta).
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'page'     => 'nullable|integer|min:1',
        ]);

        $paginator = Announcement::with('creator:id,name')
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Recipient-facing list of broadcasts the caller actually received, newest
     * first. Shape mirrors the mobile `Paginated<ReceivedAnnouncement>` type —
     * no analytics, poster exposed as `created_by` only.
     */
    public function indexForRecipient(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'page'     => 'nullable|integer|min:1',
        ]);

        $receivedIds = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNotNull('announcement_id')
            ->distinct()
            ->pluck('announcement_id');

        $paginator = Announcement::query()
            ->whereIn('id', $receivedIds)
            ->orderByRaw('sent_at IS NULL')
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Announcement $a) => [
                'id'         => $a->id,
                'kind'       => $a->kind,
                'title'      => $a->title,
                'body'       => $a->body,
                'data'       => $a->data,
                'created_by' => $a->created_by,
                'sent_at'    => optional($a->sent_at)->toIso8601String(),
                'created_at' => optional($a->created_at)->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Compose + send a broadcast. Records the source announcement, fans out a
     * feed row per recipient and pushes to their devices, then snapshots the
     * recipient count. The whole fan-out is wrapped in a transaction so a
     * failure leaves no half-sent state.
     */
    public function store(Request $request, ExpoPushService $push)
    {
        $validated = $request->validate([
            'kind'               => 'required|in:announcement,maintenance,custom',
            'title'              => 'required|string|max:255',
            // Body may be Markdown now, so it needs headroom for inline image
            // URLs and formatting beyond the old 2000-char plain-text cap.
            'body'               => 'required|string|max:20000',
            'scope'              => 'required|in:all,agents,platform',
            'platform'           => 'required_if:scope,platform|nullable|in:ios,android',
            'data'               => 'nullable|array',
            'data.format'        => 'nullable|in:markdown',
            'data.cover_image_url' => 'nullable|url',
        ]);

        $platform = $validated['scope'] === 'platform' ? $validated['platform'] : null;
        $data = $validated['data'] ?? null;

        $announcement = DB::transaction(function () use ($validated, $platform, $data, $request, $push) {
            $announcement = Announcement::create([
                'created_by' => $request->user()->id,
                'kind'       => $validated['kind'],
                'title'      => $validated['title'],
                'body'       => $validated['body'],
                'data'       => $data,
                'audience'   => ['scope' => $validated['scope'], 'platform' => $platform],
                'sent_at'    => now(),
            ]);

            $recipients = $this->resolveRecipients($validated['scope'], $platform);

            // The push (and the in-app feed row inserted from the same body)
            // must be plain text — raw Markdown would surface literal `**`,
            // `#`, `![](…)` in the OS notification. The full Markdown stays in
            // the saved announcement for the in-app detail view.
            $count = $push->broadcast(
                $recipients,
                $validated['kind'],
                $validated['title'],
                $this->plainTextFromMarkdown($validated['body']),
                ['type' => 'announcement', 'announcement_id' => $announcement->id, 'kind' => $validated['kind']],
                $announcement->id,
                $platform,
            );

            $announcement->update(['recipients_count' => $count]);

            return $announcement;
        });

        return response()->json(['data' => $announcement->load('creator:id,name')], 201);
    }

    public function show(Announcement $announcement)
    {
        return response()->json(['data' => $announcement->load('creator:id,name')]);
    }

    /**
     * Recipient-facing detail for a broadcast the caller actually received.
     * No analytics; the poster is exposed as `created_by` only so the client
     * can render the masked "Staff-XXX" identity (admins are never named here).
     */
    public function showForRecipient(Request $request, Announcement $announcement)
    {
        $received = AppNotification::query()
            ->where('announcement_id', $announcement->id)
            ->where('user_id', $request->user()->id)
            ->exists();

        abort_unless($received, 404);

        return response()->json([
            'data' => [
                'id'         => $announcement->id,
                'kind'       => $announcement->kind,
                'title'      => $announcement->title,
                'body'       => $announcement->body,
                'data'       => $announcement->data,
                'created_by' => $announcement->created_by,
                'sent_at'    => optional($announcement->sent_at)->toIso8601String(),
                'created_at' => optional($announcement->created_at)->toIso8601String(),
            ],
        ]);
    }

    /**
     * Delivery + engagement analytics for one announcement. Reads come straight
     * from the per-recipient feed rows (`app_notifications.read_at`); the device
     * picture comes from those recipients' registered tokens. Shape is consumed
     * by the mobile stats dashboard.
     */
    public function stats(Announcement $announcement)
    {
        $rows = AppNotification::query()
            ->where('announcement_id', $announcement->id)
            ->get(['user_id', 'read_at']);

        $recipients = $rows->count();
        $read = $rows->whereNotNull('read_at')->count();
        $readRate = $recipients > 0 ? round($read / $recipients, 4) : 0;

        $userIds = $rows->pluck('user_id')->all();

        $tokens = DeviceToken::query()
            ->whereIn('user_id', $userIds)
            ->get(['platform', 'os_version', 'device_model']);

        $byPlatform = $this->breakdown($tokens, 'platform', 'Unknown');
        $byOsVersion = $this->breakdown($tokens, 'os_version', 'Unknown');
        $byDeviceModel = $this->breakdown($tokens, 'device_model', 'Unknown');

        $recipientList = AppNotification::query()
            ->where('announcement_id', $announcement->id)
            ->with('user:id,name')
            ->orderByRaw('read_at IS NULL')
            ->orderByDesc('read_at')
            ->get(['user_id', 'read_at'])
            ->map(fn ($row) => [
                'id'      => $row->user_id,
                'name'    => $row->user?->name ?? 'Unknown',
                'read_at' => $row->read_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'data' => [
                'totals' => [
                    'recipients' => $recipients,
                    'read'       => $read,
                    'unread'     => $recipients - $read,
                    'read_rate'  => $readRate,
                ],
                'by_platform'     => $byPlatform,
                'by_os_version'   => $byOsVersion,
                'by_device_model' => $byDeviceModel,
                'recipients'      => $recipientList,
            ],
        ]);
    }

    /**
     * Count tokens grouped by one column, newest/biggest first, with a fallback
     * label for null values. Returns `[{ label, count }]`.
     *
     * @param  \Illuminate\Support\Collection<int,DeviceToken>  $tokens
     * @return array<int,array{label:string,count:int}>
     */
    private function breakdown($tokens, string $column, string $fallback): array
    {
        return $tokens
            ->groupBy(fn ($token) => $token->{$column} ?: $fallback)
            ->map(fn ($group, $label) => ['label' => (string) $label, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Flatten a Markdown body to plain text for the push notification / in-app
     * feed row. Images collapse to their alt text (or drop), links to their
     * label, and inline/block formatting markers are stripped. Best-effort —
     * the canonical rich body is the stored Markdown.
     */
    private function plainTextFromMarkdown(string $markdown): string
    {
        $text = $markdown;

        // Images: ![alt](url) -> alt (then drop empty residue).
        $text = preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        // Links: [label](url) -> label.
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        // Fenced/inline code fences and backticks.
        $text = preg_replace('/```[\s\S]*?```/', '', $text);
        $text = str_replace('`', '', $text);
        // Heading hashes, blockquote markers, and unordered list bullets at
        // line starts.
        $text = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $text);
        $text = preg_replace('/^\s{0,3}>\s?/m', '', $text);
        $text = preg_replace('/^\s{0,3}[-*+]\s+/m', '', $text);
        // Ordered list markers: "1. " -> "".
        $text = preg_replace('/^\s{0,3}\d+\.\s+/m', '', $text);
        // Bold/italic/strikethrough emphasis markers.
        $text = preg_replace('/(\*\*|__|\*|_|~~)/', '', $text);
        // Collapse 3+ newlines and trim.
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Recipients are app users (those with a registered device). "agents"
     * narrows to the agent role; "platform" narrows to users who have a device
     * on that platform.
     *
     * @return \Illuminate\Support\Collection<int,User>
     */
    private function resolveRecipients(string $scope, ?string $platform)
    {
        $query = User::query()->whereHas('deviceTokens');

        if ($scope === 'agents') {
            $query->whereHas('role', fn ($q) => $q->where('name', 'agent'));
        } elseif ($scope === 'platform') {
            $query->whereHas('deviceTokens', fn ($q) => $q->where('platform', $platform));
        }

        return $query->get(['id']);
    }
}
