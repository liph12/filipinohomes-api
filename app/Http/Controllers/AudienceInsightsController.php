<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for the admin "Audience Insights" page. Currently exposes the
 * audience-size counts (total / new / returning clients + distinct visitors)
 * for a date range. Admin-only (route gated by RoleMiddleware:admin); the
 * date-range param mirrors the other dashboard endpoints.
 */
class AudienceInsightsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_start' => 'nullable|date',
            'date_end'   => 'nullable|date|after_or_equal:date_start',
        ]);

        $start   = $validated['date_start'] ?? now()->startOfYear()->toDateString();
        $end     = $validated['date_end']   ?? now()->toDateString();
        $startDt = $start . ' 00:00:00';
        $endDt   = $end . ' 23:59:59';

        return response()->json([
            'size'        => $this->size($startDt, $endDt),
            'acquisition' => $this->acquisition($startDt, $endDt),
            'meta'        => ['from' => $start, 'to' => $end],
        ]);
    }

    /** Visitors grouped by acquisition channel within the date range. */
    private function acquisition(string $startDt, string $endDt): array
    {
        $channels = DB::table('visits')
            ->whereBetween('created_at', [$startDt, $endDt])
            ->select('channel', DB::raw('COUNT(*) as c'))
            ->groupBy('channel')
            ->orderByDesc('c')
            ->get()
            ->map(fn ($r) => ['channel' => $r->channel, 'value' => (int) $r->c])
            ->all();

        return ['channels' => $channels];
    }

    /** Clients are scoped via roles.name = 'client'. */
    private function clientsBase()
    {
        return DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'client');
    }

    private function size(string $startDt, string $endDt): array
    {
        $totalClients = (clone $this->clientsBase())->count();

        $newClients = (clone $this->clientsBase())
            ->whereBetween('users.created_at', [$startDt, $endDt])
            ->count();

        // Returning = client logged in during the range AND registered before it.
        $returning = DB::table('login_logs')
            ->join('users', 'users.id', '=', 'login_logs.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'client')
            ->whereBetween('login_logs.logged_in_at', [$startDt, $endDt])
            ->where('users.created_at', '<', $startDt)
            ->distinct('login_logs.user_id')
            ->count('login_logs.user_id');

        $visitors = DB::table('visits')
            ->whereBetween('created_at', [$startDt, $endDt])
            ->distinct('visitor_id')
            ->count('visitor_id');

        return [
            'total_clients'     => $totalClients,
            'new_clients'       => $newClients,
            'returning_clients' => $returning,
            'visitors'          => $visitors,
        ];
    }
}
