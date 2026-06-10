<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\TeamLeadershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin read surface for the Mobile Statistics page: who's on the Expo app,
 * what devices they use, and whether they get inquiry alerts by push or email.
 * Admin-role is enforced by the RoleMiddleware:admin route group.
 *
 * "Mobile users" = any user (regardless of role) that has at least one
 * registered device token. The role filter narrows to a specific role —
 * including `team_leader`, which is a team_agents pivot flag rather than a
 * role. All device metadata already exists on `device_tokens` (platform /
 * os_version / device_model / app_version / last_used_at), and the
 * push-vs-email choice already lives on `users.inquiry_notify_channel`.
 */
class MobileStatisticsController extends Controller
{
    /**
     * KPI rollup for the page header.
     *
     * @return JsonResponse {
     *   mobile_users, by_platform:{android,ios}, push_users, email_users, active_7d
     * }
     */
    public function stats(Request $request): JsonResponse
    {
        // user_ids of app-role users that have at least one device token.
        $mobileUserIds = $this->mobileUserIdsQuery()->pluck('id');
        $mobileUsers   = $mobileUserIds->count();

        $platformCounts = DeviceToken::query()
            ->whereIn('user_id', $mobileUserIds)
            ->whereIn('platform', ['android', 'ios'])
            ->select('platform', DB::raw('COUNT(DISTINCT user_id) as total'))
            ->groupBy('platform')
            ->pluck('total', 'platform');

        // Push vs email is per-user. 'push' is the default when the column is
        // null, mirroring User::prefersInquiryPush() and the mobile app.
        $channelCounts = User::query()
            ->whereIn('id', $mobileUserIds)
            ->select(
                DB::raw("SUM(CASE WHEN inquiry_notify_channel = 'email' THEN 1 ELSE 0 END) as email_users"),
                DB::raw("SUM(CASE WHEN inquiry_notify_channel = 'email' THEN 0 ELSE 1 END) as push_users")
            )
            ->first();

        $active7d = DeviceToken::query()
            ->whereIn('user_id', $mobileUserIds)
            ->where('last_used_at', '>=', now()->subDays(7))
            ->distinct('user_id')
            ->count('user_id');

        return response()->json([
            'mobile_users' => $mobileUsers,
            'by_platform'  => [
                'android' => (int) ($platformCounts['android'] ?? 0),
                'ios'     => (int) ($platformCounts['ios'] ?? 0),
            ],
            'push_users'  => (int) ($channelCounts->push_users ?? 0),
            'email_users' => (int) ($channelCounts->email_users ?? 0),
            'active_7d'   => $active7d,
        ]);
    }

    /**
     * Paginated list of mobile users with their devices + notification config.
     *
     * Query params:
     *   - per_page: int (1..100, default 25)
     *   - page:     int
     *   - role:     'admin'|'agent'|'client'|'editor'|'team_leader'
     *               (team_leader narrows to TL agents via the pivot)
     *   - platform: 'android' | 'ios'        (only users with such a device)
     *   - search:   matches name / email
     */
    public function users(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));

        $query = $this->mobileUserIdsQuery()
            ->with([
                'role:id,name',
                'deviceTokens' => fn ($q) => $q->orderByDesc('last_used_at'),
            ])
            ->select('users.id', 'users.name', 'users.email', 'users.role_id',
                'users.inquiry_notify_channel', 'users.notify_new_inquiry',
                'users.notify_listing_verified', 'users.notify_status_change');

        if ($platform = trim((string) $request->input('platform', ''))) {
            $query->whereHas('deviceTokens', fn ($q) => $q->where('platform', $platform));
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)->orWhere('email', 'like', $like);
            });
        }

        $roleFilter = trim((string) $request->input('role', ''));
        if ($roleFilter === 'team_leader') {
            // team_leader is computed from the team_agents pivot — resolve the
            // current TL user_id set and restrict, same as ActivityLogController.
            $tlUserIds = \App\Models\Agent::query()
                ->join('team_agents', 'agents.id', '=', 'team_agents.agent_id')
                ->where('team_agents.is_leader', true)
                ->where('team_agents.status', 'active')
                ->pluck('agents.user_id')
                ->all();
            empty($tlUserIds) ? $query->whereRaw('1 = 0') : $query->whereIn('users.id', $tlUserIds);
        } elseif ($roleFilter !== '') {
            $query->whereHas('role', fn ($q) => $q->where('name', $roleFilter));
        }

        $paginated = $query->orderBy('users.name')->paginate($perPage);

        $tlMap = app(TeamLeadershipService::class)
            ->isTeamLeaderBulk($paginated->pluck('id')->all());

        $data = collect($paginated->items())->map(fn (User $u) => [
            'id'             => $u->id,
            'name'           => $u->name,
            'email'          => $u->email,
            'role'           => $u->role?->name ?? 'agent',
            'is_team_leader' => $tlMap[$u->id] ?? false,
            'devices'        => $u->deviceTokens->map(fn (DeviceToken $d) => [
                'platform'     => $d->platform,
                'device_model' => $d->device_model,
                'os_version'   => $d->os_version,
                'app_version'  => $d->app_version,
                'last_used_at' => $d->last_used_at,
            ])->values(),
            'inquiry_notify_channel'  => $u->inquiry_notify_channel ?? 'push',
            'notify_new_inquiry'      => (bool) ($u->notify_new_inquiry ?? true),
            'notify_listing_verified' => (bool) ($u->notify_listing_verified ?? true),
            'notify_status_change'    => (bool) ($u->notify_status_change ?? true),
        ])->values();

        return response()->json([
            'data'         => $data,
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'per_page'     => $paginated->perPage(),
            'total'        => $paginated->total(),
            'from'         => $paginated->firstItem(),
            'to'           => $paginated->lastItem(),
        ]);
    }

    /**
     * Base query: app-role users that have at least one registered device.
     * Reused by both the KPI rollup and the paginated list so the population
     * stays identical.
     */
    private function mobileUserIdsQuery()
    {
        return User::query()
            ->whereHas('deviceTokens');
    }
}
