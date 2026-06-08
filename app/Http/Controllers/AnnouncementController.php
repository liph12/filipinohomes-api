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
     * Compose + send a broadcast. Records the source announcement, fans out a
     * feed row per recipient and pushes to their devices, then snapshots the
     * recipient count. The whole fan-out is wrapped in a transaction so a
     * failure leaves no half-sent state.
     */
    public function store(Request $request, ExpoPushService $push)
    {
        $validated = $request->validate([
            'kind'     => 'required|in:announcement,maintenance,custom',
            'title'    => 'required|string|max:255',
            'body'     => 'required|string|max:2000',
            'scope'    => 'required|in:all,agents,platform',
            'platform' => 'required_if:scope,platform|nullable|in:ios,android',
        ]);

        $platform = $validated['scope'] === 'platform' ? $validated['platform'] : null;

        $announcement = DB::transaction(function () use ($validated, $platform, $request, $push) {
            $announcement = Announcement::create([
                'created_by' => $request->user()->id,
                'kind'       => $validated['kind'],
                'title'      => $validated['title'],
                'body'       => $validated['body'],
                'audience'   => ['scope' => $validated['scope'], 'platform' => $platform],
                'sent_at'    => now(),
            ]);

            $recipients = $this->resolveRecipients($validated['scope'], $platform);

            $count = $push->broadcast(
                $recipients,
                $validated['kind'],
                $validated['title'],
                $validated['body'],
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
