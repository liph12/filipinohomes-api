<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
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
