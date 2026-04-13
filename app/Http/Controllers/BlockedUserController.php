<?php

namespace App\Http\Controllers;

use App\Models\BlockedUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BlockedUserController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = BlockedUser::with(['blockedUser', 'blockedByUser']);

        if ($user->role?->name === 'admin') {
            // Admins can filter by agent_user_id or see all
            if ($request->has('agent_user_id')) {
                $query->where('agent_user_id', $request->input('agent_user_id'));
            }
        } else {
            // Agents can only see their own blocked list
            $query->where('agent_user_id', $user->id);
        }

        return response()->json($query->latest()->get());
    }

    public function check(Request $request)
    {
        $validated = $request->validate([
            'agent_user_id' => 'required|exists:users,id',
            'blocked_user_id' => 'required|exists:users,id',
        ]);

        $blocked = BlockedUser::where('agent_user_id', $validated['agent_user_id'])
            ->where('blocked_user_id', $validated['blocked_user_id'])
            ->first();

        return response()->json([
            'is_blocked' => (bool) $blocked,
            'blocked_user' => $blocked,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'agent_user_id' => 'required|exists:users,id',
            'blocked_user_id' => 'required|exists:users,id',
            'reason' => 'nullable|string|max:1000',
        ]);

        $user = Auth::user();
        $isAdmin = $user->role?->name === 'admin';

        // Only the agent themselves or an admin can block
        if (!$isAdmin && (int) $validated['agent_user_id'] !== $user->id) {
            abort(403, 'You can only block users for your own account.');
        }

        // Prevent blocking yourself
        if ((int) $validated['agent_user_id'] === (int) $validated['blocked_user_id']) {
            abort(422, 'You cannot block yourself.');
        }

        $blocked = BlockedUser::firstOrCreate(
            [
                'agent_user_id' => $validated['agent_user_id'],
                'blocked_user_id' => $validated['blocked_user_id'],
            ],
            [
                'blocked_by' => $user->id,
                'reason' => $validated['reason'] ?? null,
            ]
        );

        $blocked->load(['blockedUser', 'blockedByUser']);

        return response()->json($blocked, 201);
    }

    public function destroy(BlockedUser $blockedUser)
    {
        $user = Auth::user();
        $isAdmin = $user->role?->name === 'admin';

        if (!$isAdmin && $blockedUser->agent_user_id !== $user->id) {
            abort(403, 'You can only unblock users from your own block list.');
        }

        $blockedUser->delete();

        return response()->json(['message' => 'User unblocked.']);
    }
}
