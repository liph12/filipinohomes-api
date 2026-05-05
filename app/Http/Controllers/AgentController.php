<?php

namespace App\Http\Controllers;

use App\Models\Agent;
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
        $perPage = max(1, min((int) $request->query('per_page', 12), 24));

        $query = Agent::query()
            ->select('agents.*')
            ->with([
                'user',
                'pageBuilder:id,agent_id,slug',
            ])
            ->whereHas('user.role', function($q){
                $q->where('name', 'agent');
            })
            ->withCount('listings');

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

        return new AgentResourceCollection(
            $query
                ->orderByDesc('listings_count')
                ->paginate($perPage)
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

        $agentListingIds = $agent->listings()->pluck('listings.id');
        $totalInquiries   = ListingInquiry::whereIn('listing_id', $agentListingIds)->count();
        $pendingInquiries = ListingInquiry::whereIn('listing_id', $agentListingIds)
            ->where('status', 'pending')
            ->count();

        // Sold chart — last 12 months grouped by month
        $twelveMonthsAgo = Carbon::now()->subMonths(11)->startOfMonth();

        $buildMonthlyChart = function (string $status) use ($agent, $twelveMonthsAgo): array {
            $byMonth = $agent->listings()
                ->join('properties', 'listings.property_id', '=', 'properties.id')
                ->where('properties.status', $status)
                ->where('properties.status_change_date', '>=', $twelveMonthsAgo)
                ->select(
                    DB::raw("DATE_FORMAT(properties.status_change_date, '%Y-%m') as month"),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('month')
                ->orderBy('month')
                ->pluck('count', 'month');

            $chart = [];
            for ($i = 0; $i < 12; $i++) {
                $month = Carbon::now()->subMonths(11 - $i)->format('Y-m');
                $chart[] = ['month' => $month, 'count' => $byMonth[$month] ?? 0];
            }
            return $chart;
        };

        $soldChart   = $buildMonthlyChart('sold');
        $rentedChart = $buildMonthlyChart('rented');
        $leasedChart = $buildMonthlyChart('leased');

        // Recent inquiries
        $recentInquiries = ListingInquiry::whereIn('listing_id', $agentListingIds)
            ->with(['listing:id,name,slug', 'client:id,name,email'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($inq) => [
                'id'           => $inq->id,
                'listing_name' => $inq->listing?->name ?? 'N/A',
                'listing_slug' => $inq->listing?->slug ?? '',
                'client_name'  => $inq->client?->name ?? 'N/A',
                'client_email' => $inq->client?->email ?? '',
                'status'       => $inq->status,
                'created_at'   => $inq->created_at?->toDateTimeString(),
            ]);

        // Login history
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
}
