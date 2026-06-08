<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
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

    /** Read the authenticated user's listing-inquiry channel preference. */
    public function preferences(Request $request)
    {
        return response()->json([
            'inquiry_notify_channel' => $request->user()->inquiry_notify_channel ?? 'push',
        ]);
    }

    /** Update the authenticated user's listing-inquiry channel preference. */
    public function updatePreferences(Request $request)
    {
        $validated = $request->validate([
            'inquiry_notify_channel' => 'required|in:push,email',
        ]);

        $user = $request->user();
        $user->inquiry_notify_channel = $validated['inquiry_notify_channel'];
        $user->save();

        return response()->json([
            'inquiry_notify_channel' => $user->inquiry_notify_channel,
        ]);
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
}
