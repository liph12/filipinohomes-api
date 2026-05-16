<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ListingInquiry;
use App\Models\LoginLog;
use App\Http\Resources\AgentResourceCollection;
use App\Http\Resources\AgentResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AgentController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->query('per_page', 12), 50));

        // Optional date-range filter for the "listings created in range" subquery.
        // Used by the admin Top Agents table. When absent, listings_in_range_count
        // falls back to the all-time count so existing callers keep working.
        $dateFrom = $request->query('date_from'); // 'YYYY-MM-DD' or null
        $dateTo   = $request->query('date_to');
        $teamId   = $request->query('team_id');

        $rangeSql = "(SELECT COUNT(*) FROM listings WHERE listings.agent_id = agents.id";
        $rangeBindings = [];
        if ($dateFrom) {
            $rangeSql .= " AND listings.created_at >= ?";
            $rangeBindings[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo) {
            $rangeSql .= " AND listings.created_at <= ?";
            $rangeBindings[] = $dateTo . ' 23:59:59';
        }
        $rangeSql .= ") as listings_in_range_count";

        $query = Agent::query()
            ->select([
                'agents.*',
                // flat COUNT subqueries — no nested EXISTS, JOIN for property status
                DB::raw("(SELECT COUNT(*) FROM listings WHERE listings.agent_id = agents.id) as listings_count"),
                DB::raw("(SELECT COUNT(*) FROM listings WHERE listings.agent_id = agents.id AND listings.visibility = 'public') as public_listings_count"),
                DB::raw("(SELECT COUNT(*) FROM listings WHERE listings.agent_id = agents.id AND listings.visibility = 'private') as private_listings_count"),
                DB::raw("(SELECT COUNT(*) FROM listings INNER JOIN properties ON properties.id = listings.property_id WHERE listings.agent_id = agents.id AND properties.status = 'sold') as sold_count"),
                DB::raw("(SELECT COUNT(*) FROM listings INNER JOIN properties ON properties.id = listings.property_id WHERE listings.agent_id = agents.id AND properties.status = 'rented') as rented_count"),
                DB::raw("(SELECT COUNT(*) FROM listings INNER JOIN properties ON properties.id = listings.property_id WHERE listings.agent_id = agents.id AND properties.status = 'leased') as leased_count"),
                DB::raw("(SELECT COUNT(*) FROM conversations WHERE conversations.agent_user_id = agents.user_id AND conversations.status = 'accepted') as ongoing_inquiries_count"),
                DB::raw("(SELECT COUNT(*) FROM conversations WHERE conversations.agent_user_id = agents.user_id AND conversations.status = 'closed') as closed_inquiries_count"),
                DB::raw($rangeSql),
                'agents.median_first_response_seconds',
                'agents.within_1h_response_pct',
                'agents.unanswered_response_pct',
                'agents.response_sample_size',
                'agents.response_metrics_window_days',
            ])
            ->addBinding($rangeBindings, 'select')
            ->with([
                'user',
                'pageBuilder:id,agent_id,slug',
                'teamMembers.team',
            ])
            ->whereHas('user.role', function($q){
                $q->where('name', 'agent');
            });

        if ($teamId) {
            $query->whereHas('teamMembers', function ($q) use ($teamId) {
                $q->where('team_id', $teamId);
            });
        }

        if ($search = $request->query('search')) {
            $term = '%' . $search . '%';

            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'LIKE', $term)
                  ->orWhere('last_name', 'LIKE', $term)
                  ->orWhere('address', 'LIKE', $term)
                  ->orWhere('mobile_no', 'LIKE', $term)
                  ->orWhereHas('user', function ($uq) use ($term) {
                      $uq->where('email', 'LIKE', $term)
                         ->orWhere('name', 'LIKE', $term);
                  });
            });
        }

        $sortBy  = $request->query('sort_by');
        $sortDir = strtolower($request->query('sort_dir', 'desc'));
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';

        $allowed = ['full_name', 'email', 'member_since', 'listings_count', 'listings_in_range', 'transactions_count', 'inquiries_count', 'response_speed', 'last_online', 'status', 'login_count'];

        if (in_array($sortBy, $allowed)) {
            match ($sortBy) {
                'full_name'          => $query->orderByRaw("CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) $sortDir"),
                'email'              => $query->orderByRaw("(SELECT email FROM users WHERE users.id = agents.user_id) $sortDir"),
                'member_since'       => $query->orderBy('member_since', $sortDir),
                'listings_count'     => $query->orderBy('listings_count', $sortDir),
                'listings_in_range'  => $query->orderBy('listings_in_range_count', $sortDir),
                'transactions_count' => $query->orderByRaw("(sold_count + rented_count + leased_count) $sortDir"),
                'inquiries_count'    => $query->orderByRaw("(ongoing_inquiries_count + closed_inquiries_count) $sortDir"),
                'last_online'        => $query->orderByRaw("(SELECT last_online_at FROM users WHERE users.id = agents.user_id) $sortDir"),
                'status'             => $query->orderBy('agents.status', $sortDir),
                'login_count'        => $query->orderByRaw("(SELECT COUNT(*) FROM login_logs WHERE login_logs.user_id = agents.user_id) $sortDir"),
                'response_speed'     => $query
                    ->orderByRaw("CASE WHEN response_sample_size >= 3 AND median_first_response_seconds IS NOT NULL AND (unanswered_response_pct IS NULL OR unanswered_response_pct < 50) THEN 0 ELSE 1 END ASC")
                    ->orderByRaw("CASE WHEN response_sample_size >= 3 AND median_first_response_seconds IS NOT NULL AND (unanswered_response_pct IS NULL OR unanswered_response_pct < 50) THEN median_first_response_seconds ELSE NULL END ASC")
                    ->orderByDesc('listings_count'),
            };
        } else {
            $query->orderByDesc('listings_count');
        }

        return new AgentResourceCollection(
            $query->paginate($perPage)
        );
    }

    public function admins()
    {
        $adminIds = User::query()->admin()->pluck('id');

        return response()->json($adminIds);
    }

    public function statistics(Request $request, $id)
    {
        $agent = Agent::with('user')->withCount('listings')->findOrFail($id);
        $user = $agent->user;

        $activeListings = $agent->listings()
            ->whereHas('property', fn($q) => $q->where('status', 'active'))
            ->count();

        $soldCount = $agent->listings()
            ->whereHas('property', fn($q) => $q->where('status', 'sold'))
            ->count();

        $rentedCount = $agent->listings()
            ->whereHas('property', fn($q) => $q->where('status', 'rented'))
            ->count();

        $leasedCount = $agent->listings()
            ->whereHas('property', fn($q) => $q->where('status', 'leased'))
            ->count();

        $agentUserId = $agent->user_id;

        // Inquiries are tracked in conversations.agent_user_id (a users.id),
        // not the legacy listing_inquiries table — match what the agent
        // sees on /agent/listing-inquiries.
        $totalInquiries = $agentUserId
            ? Conversation::where('agent_user_id', $agentUserId)->count()
            : 0;
        $pendingInquiries = $agentUserId
            ? Conversation::where('agent_user_id', $agentUserId)
                ->where('status', 'pending')
                ->count()
            : 0;

        // Sold chart — last 12 months grouped by month
        $twelveMonthsAgo = Carbon::now()->subMonths(11)->startOfMonth();

$buildMonthlyChart = function (string $status) use ($agent, $twelveMonthsAgo): array {
    $byMonth = DB::table('listings')
        ->join('properties', 'listings.property_id', '=', 'properties.id')
        ->where('listings.agent_id', $agent->id)
        ->where('properties.status', $status)
        ->whereBetween('properties.status_change_date', [
            $twelveMonthsAgo,
            Carbon::now()->endOfMonth(),
        ])
        ->select(
            DB::raw("DATE_FORMAT(properties.status_change_date, '%Y-%m') as month"),
            DB::raw('COUNT(*) as count')
        )
        ->groupBy('month')
        ->orderBy('month')
        ->pluck('count', 'month');

    $chart = [];
    for ($i = 0; $i < 12; $i++) {
        $key = Carbon::now()->subMonths(11 - $i)->format('Y-m');
        $chart[] = ['month' => $key, 'count' => (int) ($byMonth[$key] ?? 0)];
    }

    return $chart;
};

        $soldChart   = $buildMonthlyChart('sold');
        $rentedChart = $buildMonthlyChart('rented');
        $leasedChart = $buildMonthlyChart('leased');

        // Recent inquiries — pulled from conversations.agent_user_id, joining
        // chat → listing (the inquired-on listing) and chat → user (the client).
        $recentInquiries = $agentUserId
            ? Conversation::query()
                ->where('agent_user_id', $agentUserId)
                ->with([
                    'chat:id,user_id,type,type_id',
                    'chat.user:id,name,email',
                    'chat.listing:id,name,slug',
                ])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn ($conv) => [
                    'id'           => $conv->id,
                    'listing_name' => $conv->chat?->listing?->name ?? 'N/A',
                    'listing_slug' => $conv->chat?->listing?->slug ?? '',
                    'client_name'  => $conv->chat?->user?->name ?? 'N/A',
                    'client_email' => $conv->chat?->user?->email ?? '',
                    'status'       => $conv->status,
                    'created_at'   => $conv->created_at?->toDateTimeString(),
                ])
            : collect();

        // Login history + stats
        $loginHistory = $user
            ? LoginLog::where('user_id', $user->id)
                ->orderByDesc('logged_in_at')
                ->limit(20)
                ->get()
                ->map(fn($log) => [
                    'id'          => $log->id,
                    'ip_address'  => $log->ip_address,
                    'user_agent'  => $log->user_agent,
                    'logged_in_at' => $log->logged_in_at?->toDateTimeString(),
                ])
            : [];

        $loginCount = $user ? LoginLog::where('user_id', $user->id)->count() : 0;

        $loginByMonth = $user
            ? LoginLog::where('user_id', $user->id)
                ->where('logged_in_at', '>=', $twelveMonthsAgo)
                ->select(
                    DB::raw("DATE_FORMAT(logged_in_at, '%Y-%m') as month"),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('month')
                ->pluck('count', 'month')
            : collect();

        $loginChart = [];
        for ($i = 0; $i < 12; $i++) {
            $key = Carbon::now()->subMonths(11 - $i)->format('Y-m');
            $loginChart[] = ['month' => $key, 'count' => (int) ($loginByMonth[$key] ?? 0)];
        }

        $fullName = collect([$agent->first_name, $agent->middle_name, $agent->last_name])
            ->filter()->join(' ') ?: $user?->name ?: 'Guest User';

        return response()->json([
            'agent' => [
                'id'           => $agent->id,
                'full_name'    => $fullName,
                'avatar'       => $agent->avatar ?? $user?->avatar,
                'address'      => $agent->address,
                'member_since' => $agent->member_since,
                'email'        => $user?->email,
                'mobile_no'    => $agent->mobile_no ?? $user?->mobile_no,
            ],
            'kpi' => [
                'total_listings'    => $agent->listings_count,
                'active_listings'   => $activeListings,
                'sold_count'        => $soldCount,
                'rented_count'      => $rentedCount,
                'leased_count'      => $leasedCount,
                'total_inquiries'   => $totalInquiries,
                'pending_inquiries' => $pendingInquiries,
            ],
            'sold_chart'        => $soldChart,
            'rented_chart'      => $rentedChart,
            'leased_chart'      => $leasedChart,
            'recent_inquiries'  => $recentInquiries,
            'login_history'     => $loginHistory,
            'login_count'       => $loginCount,
            'login_chart'       => $loginChart,
            'response_metrics'  => [
                'median_first_response_seconds' => $agent->median_first_response_seconds,
                'within_1h_response_pct'        => $agent->within_1h_response_pct !== null
                    ? (float) $agent->within_1h_response_pct
                    : null,
                'unanswered_response_pct'       => $agent->unanswered_response_pct !== null
                    ? (float) $agent->unanswered_response_pct
                    : null,
                'sample_size'                   => $agent->response_sample_size,
                'window_days'                   => $agent->response_metrics_window_days,
                'updated_at'                    => $agent->response_metrics_updated_at?->toDateTimeString(),
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $agent = Agent::with('user')
            ->withCount('listings')
            ->findOrFail($id);

        $listingsQuery = $agent->listings()
            ->with([
                'property.propertyAttribute.subtype.type',
                'property.furnishing',
                'property.barangay.city.province',
                'category',
            ])
            ->orderByDesc('created_at');

        if ($search = trim((string) $request->query('search', ''))) {
            $term = "%{$search}%";

            $listingsQuery->where(function ($q) use ($term) {
                $q->where('name', 'LIKE', $term)
                    ->orWhere('code', 'LIKE', $term)
                    ->orWhereHas('property', function ($propertyQuery) use ($term) {
                        $propertyQuery
                            ->orWhere('address', 'LIKE', $term);
                    });
            });
        }

        $agent->setRelation(
            'listings',
            $listingsQuery
                ->paginate(12)
                ->appends($request->only(['search']))
        );

        return new AgentResource($agent);
    }

    public function profile()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $agent = Agent::where('user_id', $user->id)
            ->with('user.role')
            ->first();

        if (!$agent) {
            return new UserResource($user->load('role'));
        }

        return new AgentResource($agent);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name'        => 'required|string|max:255',
            'middle_name'       => 'nullable|string|max:255',
            'last_name'         => 'required|string|max:255',
            'mobile_no'         => 'required|string|max:20',
            'whats_app_no'      => 'nullable|string|max:20',
            'address'           => 'nullable|string|max:500',
            'socials'           => 'nullable|array',
            'socials.facebook'  => 'nullable|string|max:255',
            'socials.instagram' => 'nullable|string|max:255',
            'socials.twitter'   => 'nullable|string|max:255',
            'socials.linkedin'  => 'nullable|string|max:255',
            'socials.youtube'   => 'nullable|string|max:255',
            'socials.tiktok'    => 'nullable|string|max:255',
            'bio'               => 'nullable|string',
            'avatar'            => 'nullable|string|url',
            'geo_location'      => 'nullable|array:lat,lng',
            'user_id'           => 'nullable|exists:users,id', // ← admin can pass a target user
        ]);

        $user      = Auth::user();
        $role      = $user->role?->name;
        $targetId  = $user->id; // default to self

        if ($role === 'admin') {
            // Admin can pass user_id to update any agent, or defaults to themselves
            if (!empty($validated['user_id'])) {
                $targetId = $validated['user_id'];

                // Make sure target user is actually an agent (or admin)
                $targetUser = \App\Models\User::with('role')->findOrFail($targetId);
                $targetRole = $targetUser->role?->name;

                if (!in_array($targetRole, ['agent', 'admin'])) {
                    abort(403, 'Target user must be an agent or admin.');
                }
            }
        } elseif ($role === 'agent') {
            // Agent can only update their own profile
            $targetId = $user->id;
        } else {
            abort(403, 'Only agents or admins can create or update an agent profile.');
        }

        // Remove user_id from validated so it doesn't get saved into agent fields
        unset($validated['user_id']);

        $agent = Agent::updateOrCreate(
            ['user_id' => $targetId],
            $validated
        );

        return new AgentResource($agent);
    }

    public function updateStatus(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role?->name !== 'admin') abort(403);

        $agent = Agent::withTrashed()->findOrFail($id);
        $validated = $request->validate([
            'status' => 'required|in:active,inactive,resigned',
        ]);
        $agent->update(['status' => $validated['status']]);

        return response()->json(['data' => new AgentResource($agent)]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role?->name !== 'admin') abort(403);

        $agent = Agent::findOrFail($id);
        $agent->delete();

        return response()->json(['message' => 'Agent removed.']);
    }

    public function restore(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role?->name !== 'admin') abort(403);

        $agent = Agent::onlyTrashed()->findOrFail($id);
        $agent->restore();

        return response()->json(['data' => new AgentResource($agent)]);
    }


    public function deletedAgents(Request $request)
    {
        $user = $request->user();
        if ($user->role?->name !== 'admin') abort(403);

        $perPage = max(1, min((int) $request->query('per_page', 12), 24));

        $query = Agent::onlyTrashed()
            ->select([
                'agents.*',
                DB::raw("(SELECT COUNT(*) FROM listings WHERE listings.agent_id = agents.id) as listings_count"),
            ])
            ->with(['user'])
            ->whereHas('user.role', function ($q) {
                $q->where('name', 'agent');
            });

        if ($search = $request->query('search')) {
            $term = '%' . $search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'LIKE', $term)
                  ->orWhere('last_name', 'LIKE', $term)
                  ->orWhere('mobile_no', 'LIKE', $term)
                  ->orWhereHas('user', function ($uq) use ($term) {
                      $uq->where('email', 'LIKE', $term)->orWhere('name', 'LIKE', $term);
                  });
            });
        }

        $sortBy  = $request->query('sort_by');
        $sortDir = strtolower($request->query('sort_dir', 'asc'));
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'asc';

        match ($sortBy) {
            'full_name'      => $query->orderByRaw("CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) $sortDir"),
            'deleted_at'     => $query->orderBy('deleted_at', $sortDir),
            'listings_count' => $query->orderBy('listings_count', $sortDir),
            default          => $query->orderByDesc('deleted_at'),
        };

        return new AgentResourceCollection(
            $query->paginate($perPage)
        );
    }
}
