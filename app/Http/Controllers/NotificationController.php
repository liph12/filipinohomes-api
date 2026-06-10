<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Paginated in-app notification feed for the authenticated user, newest
     * first. Shape mirrors the mobile `Paginated<T>` type (data + meta).
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'page'     => 'nullable|integer|min:1',
        ]);

        $paginator = AppNotification::where('user_id', $request->user()->id)
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
     * Single notification, scoped to the authenticated user. Lets a cold push
     * tap (e.g. listing_inquiry → notification/{id}) load the detail when the
     * row isn't in the app's list cache yet.
     */
    public function show(Request $request, AppNotification $notification)
    {
        abort_if($notification->user_id !== $request->user()->id, 403);

        return response()->json(['data' => $notification]);
    }

    /** Read the authenticated user's notification preferences. */
    public function preferences(Request $request)
    {
        return response()->json($this->preferencePayload($request->user()));
    }

    /**
     * Update the authenticated user's notification preferences. Every field is
     * optional so the mobile app can PATCH a single toggle; only the provided
     * keys are written.
     */
    public function updatePreferences(Request $request)
    {
        $validated = $request->validate([
            'inquiry_notify_channel'  => 'sometimes|in:push,email',
            'notify_new_inquiry'      => 'sometimes|boolean',
            'notify_listing_verified' => 'sometimes|boolean',
            'notify_status_change'    => 'sometimes|boolean',
        ]);

        $user = $request->user();
        $user->fill($validated);
        $user->save();

        return response()->json($this->preferencePayload($user));
    }

    /**
     * Admin edit of *another* user's notification preferences, from the
     * Mobile Statistics page. Same validation as updatePreferences but the
     * target is the route-bound user, not the caller. Admin-role is enforced
     * by the RoleMiddleware:admin group on the route. The field change is
     * audited automatically by the User model's LogsActivity trait (category
     * 'users'), attributed to the acting admin.
     */
    public function adminUpdatePreferences(Request $request, User $user)
    {
        $validated = $request->validate([
            'inquiry_notify_channel'  => 'sometimes|in:push,email',
            'notify_new_inquiry'      => 'sometimes|boolean',
            'notify_listing_verified' => 'sometimes|boolean',
            'notify_status_change'    => 'sometimes|boolean',
        ]);

        $user->fill($validated);
        $user->save();

        return response()->json($this->preferencePayload($user));
    }

    /** @return array<string,mixed> */
    private function preferencePayload($user): array
    {
        return [
            'inquiry_notify_channel'  => $user->inquiry_notify_channel ?? 'push',
            'notify_new_inquiry'      => (bool) ($user->notify_new_inquiry ?? true),
            'notify_listing_verified' => (bool) ($user->notify_listing_verified ?? true),
            'notify_status_change'    => (bool) ($user->notify_status_change ?? true),
        ];
    }

    public function unreadCount(Request $request)
    {
        $count = AppNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, AppNotification $notification)
    {
        abort_if($notification->user_id !== $request->user()->id, 403);

        if (!$notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['data' => $notification]);
    }

    public function markAllRead(Request $request)
    {
        AppNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked read.']);
    }

    public function destroy(Request $request, AppNotification $notification)
    {
        abort_if($notification->user_id !== $request->user()->id, 403);

        $notification->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }
}
