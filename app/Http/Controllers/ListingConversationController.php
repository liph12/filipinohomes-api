<?php

namespace App\Http\Controllers;

use App\Models\ListingConversation;
use App\Models\ListingInquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListingConversationController extends Controller
{
    /**
     * Get all messages for a specific inquiry.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'inquiry_id' => ['required', 'integer', 'exists:listing_inquiries,id'],
        ]);

        $inquiry = ListingInquiry::findOrFail($request->inquiry_id);

        $this->authorize('view', $inquiry);

        $messages = $inquiry->conversations()
            ->with('sender:id,name')
            ->paginate($request->integer('per_page', 50));

        // Mark incoming messages as read
        $inquiry->conversations()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $request->user()->id)
            ->update(['read_at' => now(), 'is_read' => true]);

        return response()->json($messages);
    }

    /**
     * Send a message in an existing inquiry thread.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'inquiry_id' => ['required', 'integer', 'exists:listing_inquiries,id'],
            'message'    => ['required', 'string', 'max:2000'],
        ]);

        $inquiry = ListingInquiry::findOrFail($request->inquiry_id);

        $this->authorize('view', $inquiry);

        abort_if($inquiry->status === 'closed', 403, 'This inquiry is closed.');

        $conversation = $inquiry->conversations()->create([
            'sender_id' => $request->user()->id,
            'message'   => $request->message,
        ]);

        // Reactivate if it was pending
        if ($inquiry->status === 'pending') {
            $inquiry->update(['status' => 'active']);
        }

        return response()->json([
            'data' => $conversation->load('sender:id,name'),
        ], 201);
    }
}