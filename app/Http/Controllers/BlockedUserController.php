<?php

namespace App\Http\Controllers;

use App\Models\BlockedUser;
use App\Models\User;
use App\Services\AuditSecurityService;
use App\Services\TeamLeadershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BlockedUserController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $roleName = $user->role?->name;

        $query = BlockedUser::with(['blockedUser', 'blockedByUser', 'agent']);

        if ($roleName === 'admin') {
            // Admins see every row — global + per-agent for all agents.
            if ($request->has('agent_user_id')) {
                $query->where('agent_user_id', $request->input('agent_user_id'));
            }
        } else {
            // Agent: own per-agent rows.
            // Team leader: own per-agent rows + per-agent rows for any agent
            // in a team they lead. Both fall through the same OR.
            $ledIds = app(TeamLeadershipService::class)->getLedTeamMemberUserIds($user->id);
            $visibleAgentIds = array_values(array_unique(array_merge([$user->id], $ledIds)));

            $query->where(function ($q) use ($visibleAgentIds) {
                $q->whereIn('agent_user_id', $visibleAgentIds);
            });
        }

        return response()->json($query->latest()->get());
    }

    public function check(Request $request)
    {
        $validated = $request->validate([
            'agent_user_id' => 'required|exists:users,id',
            'blocked_user_id' => 'required|exists:users,id',
        ]);

        // Scope-aware: a global row for blocked_user_id counts as blocking
        // regardless of agent. The blocked_user payload is the most relevant
        // row (global preferred so the UI surfaces "site-wide" when present).
        $blocked = BlockedUser::with(['blockedUser', 'blockedByUser', 'agent'])
            ->where('blocked_user_id', $validated['blocked_user_id'])
            ->where(function ($q) use ($validated) {
                $q->where('scope', 'global')
                  ->orWhere(function ($qq) use ($validated) {
                      $qq->where('scope', 'per_agent')
                         ->where('agent_user_id', $validated['agent_user_id']);
                  });
            })
            ->orderByRaw("FIELD(scope, 'global', 'per_agent')")
            ->first();

        return response()->json([
            'is_blocked' => (bool) $blocked,
            'blocked_user' => $blocked,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            // Required for per-agent blocks; optional for global blocks
            // since the controller writes agent_user_id=null on the
            // global row anyway. Some legacy listing chats also have
            // conversation.agent_user_id=null even though the listing
            // itself has an owner — relaxing this lets admins ship a
            // global ban without needing the frontend to fall back to
            // a placeholder agent.
            'agent_user_id' => 'required_unless:scope,global|nullable|exists:users,id',
            'blocked_user_id' => 'required|exists:users,id',
            'reason' => 'nullable|string|max:1000',
            // Admin-only knob: 'global' writes a site-wide ban,
            // 'per_agent' writes a single per-agent row. Defaults
            // to 'global' (the legacy admin behavior). Agents and
            // TLs always use per_agent regardless of what they
            // send here — the branch below enforces it.
            'scope' => 'nullable|in:per_agent,global',
        ]);

        $user = Auth::user();
        $isAdmin = $user->role?->name === 'admin';
        // agent_user_id is optional for global blocks; coerce to 0
        // when missing so downstream code that compares it as int
        // doesn't blow up. The per-agent branches below explicitly
        // require a non-zero value and abort otherwise.
        $targetAgentId = (int) ($validated['agent_user_id'] ?? 0);
        $blockedUserId = (int) $validated['blocked_user_id'];

        // Prevent blocking yourself.
        if (!$isAdmin && $targetAgentId === $blockedUserId) {
            abort(422, 'You cannot block yourself.');
        }

        $auditService = app(AuditSecurityService::class);
        $blockedUserModel = User::find($blockedUserId);

        if ($isAdmin) {
            // Admins pick the scope at the call site (see the
            // BlockScopeDialog on the frontend). Default = global to
            // preserve legacy behavior when no scope is sent.
            $requestedScope = $validated['scope'] ?? 'global';

            if ($requestedScope === 'per_agent') {
                if ($targetAgentId <= 0) {
                    abort(422, 'agent_user_id is required for a per-agent block.');
                }
                $blocked = BlockedUser::firstOrCreate(
                    [
                        'agent_user_id'   => $targetAgentId,
                        'blocked_user_id' => $blockedUserId,
                        'scope'           => 'per_agent',
                    ],
                    [
                        'blocked_by' => $user->id,
                        'reason'     => $validated['reason'] ?? null,
                    ]
                );
            } else {
                // Site-wide ban — one row, applies to every agent.
                $blocked = BlockedUser::firstOrCreate(
                    [
                        'blocked_user_id' => $blockedUserId,
                        'scope'           => 'global',
                    ],
                    [
                        'agent_user_id' => null,
                        'blocked_by'    => $user->id,
                        'reason'        => $validated['reason'] ?? null,
                    ]
                );
            }

            if ($blockedUserModel) {
                $auditService->recordBlock(
                    $user,
                    $blockedUserModel,
                    $blocked->scope,
                    $blocked->agent_user_id,
                    $validated['reason'] ?? null,
                );
            }

            $blocked->load(['blockedUser', 'blockedByUser', 'agent']);

            return response()->json($blocked, 201);
        }

        // Team leaders block across their entire team — one click fans out
        // to a per-agent row for every active member of the team(s) they
        // lead, so the client is blocked from messaging any of them. This
        // matches the operator mental model: "a TL block is a team ban,
        // not a personal ban". Regular agents (no led members) just write
        // a single per-agent row for themselves.
        $tls = app(TeamLeadershipService::class);
        $ledIds = $tls->getLedTeamMemberUserIds($user->id);
        $isTeamLeader = !empty($ledIds);

        if ($isTeamLeader) {
            // Roster always includes the TL themselves so a self-issued block
            // from their own chat also fans out, and so a leader who happens
            // not to appear in their own team's roster still gets blocked.
            $teamAgentIds = array_values(array_unique(array_merge([$user->id], $ledIds)));

            // Target agent must be in their team's scope (or the TL themselves).
            // A TL can't write a per-agent row for an agent outside their team.
            if (!in_array($targetAgentId, $teamAgentIds, true)) {
                abort(403, 'You can only block users for your own account or your team\'s agents.');
            }

            $rows = [];
            DB::transaction(function () use ($teamAgentIds, $blockedUserId, $user, $validated, &$rows) {
                foreach ($teamAgentIds as $agentId) {
                    if ($agentId === $blockedUserId) {
                        // Skip the self-block edge case — e.g. a TL whose
                        // team member happens to share the client's id (won't
                        // happen in practice but cheap to guard).
                        continue;
                    }
                    $rows[$agentId] = BlockedUser::firstOrCreate(
                        [
                            'agent_user_id' => $agentId,
                            'blocked_user_id' => $blockedUserId,
                            'scope' => 'per_agent',
                        ],
                        [
                            'blocked_by' => $user->id,
                            'reason' => $validated['reason'] ?? null,
                        ]
                    );
                }
            });

            // Return the row whose agent matches the conversation the caller
            // is on — the frontend uses its `id` to drive unblock on the same
            // visible "Unblock User" button. Falls back to any row if the
            // target somehow isn't in the fan-out (shouldn't happen).
            $primary = $rows[$targetAgentId] ?? reset($rows);
            if (!$primary) {
                // No rows were written — degenerate case (e.g. blocking self).
                abort(422, 'Nothing to block.');
            }
            $primary->load(['blockedUser', 'blockedByUser', 'agent']);

            if ($blockedUserModel) {
                $auditService->recordBlock(
                    $user,
                    $blockedUserModel,
                    'per_agent',
                    $primary->agent_user_id,
                    $validated['reason'] ?? null,
                );
            }

            return response()->json($primary, 201);
        }

        // Regular agent — can only write a per-agent row for themselves.
        if ($targetAgentId !== $user->id) {
            abort(403, 'You can only block users for your own account.');
        }

        $blocked = BlockedUser::firstOrCreate(
            [
                'agent_user_id' => $targetAgentId,
                'blocked_user_id' => $blockedUserId,
                'scope' => 'per_agent',
            ],
            [
                'blocked_by' => $user->id,
                'reason' => $validated['reason'] ?? null,
            ]
        );

        $blocked->load(['blockedUser', 'blockedByUser', 'agent']);

        if ($blockedUserModel) {
            $auditService->recordBlock(
                $user,
                $blockedUserModel,
                'per_agent',
                $blocked->agent_user_id,
                $validated['reason'] ?? null,
            );
        }

        return response()->json($blocked, 201);
    }

    public function destroy(BlockedUser $blockedUser)
    {
        $user = Auth::user();
        $isAdmin = $user->role?->name === 'admin';

        // Snapshot fields BEFORE delete so the audit row can record
        // the scope + agent_user_id we just removed (the model is
        // gone by the time we call recordUnblock otherwise).
        $auditService = app(AuditSecurityService::class);
        $blockedUserModel = User::find($blockedUser->blocked_user_id);
        $unblockScope = (string) $blockedUser->scope;
        $unblockAgentId = $blockedUser->agent_user_id;

        if ($isAdmin) {
            // Admins can unblock any row (global or per-agent).
            $blockedUser->delete();
            if ($blockedUserModel) {
                $auditService->recordUnblock(
                    $user,
                    $blockedUserModel,
                    $unblockScope,
                    $unblockAgentId,
                );
            }
            return response()->json(['message' => 'User unblocked.']);
        }

        // Non-admins can only touch per-agent rows.
        if ($blockedUser->scope === 'global') {
            abort(403, 'Site-wide blocks can only be removed by an administrator.');
        }

        $tls = app(TeamLeadershipService::class);
        $ledIds = $tls->getLedTeamMemberUserIds($user->id);
        $isTeamLeader = !empty($ledIds);

        if ($isTeamLeader) {
            // Team leader: unblock fans out across the team — remove every
            // per-agent row sharing the blocked_user_id whose agent is in
            // this leader's team (or is the leader themselves). Matches the
            // fan-out shape applied in store() so block + unblock are
            // symmetric. Rows authored by another moderator (a different TL
            // or the agent themselves) are preserved — TLs only undo their
            // own team's coverage, not somebody else's separately issued
            // per-agent block.
            $teamAgentIds = array_values(array_unique(array_merge([$user->id], $ledIds)));
            if (!in_array($blockedUser->agent_user_id, $teamAgentIds, true)) {
                abort(403, 'You can only unblock users from your own list or your team\'s agents.');
            }

            DB::table('blocked_users')
                ->where('blocked_user_id', $blockedUser->blocked_user_id)
                ->where('scope', 'per_agent')
                ->whereIn('agent_user_id', $teamAgentIds)
                ->where('blocked_by', $user->id)
                ->delete();

            if ($blockedUserModel) {
                $auditService->recordUnblock(
                    $user,
                    $blockedUserModel,
                    'per_agent',
                    $unblockAgentId,
                );
            }

            return response()->json(['message' => 'User unblocked.']);
        }

        // Regular agent — can only touch their own per-agent row.
        if ($blockedUser->agent_user_id !== $user->id) {
            abort(403, 'You can only unblock users from your own list.');
        }

        $blockedUser->delete();

        if ($blockedUserModel) {
            $auditService->recordUnblock(
                $user,
                $blockedUserModel,
                'per_agent',
                $unblockAgentId,
            );
        }

        return response()->json(['message' => 'User unblocked.']);
    }
}
