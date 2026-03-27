<?php

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class MessageController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
        ]);

        $conversation = Conversation::findOrFail($validated['conversation_id']);

        if (!$conversation->users()->where('users.id', Auth::id())->exists()
            && Auth::user()->role?->name !== 'admin') {
            abort(403, 'You are not a participant in this conversation.');
        }

        $messages = Message::where('conversation_id', $validated['conversation_id'])
            ->with(['user', 'replyTo.user', 'reactions.user'])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return MessageResource::collection($messages);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
            'body' => 'required|string|max:5000',
            'type' => 'sometimes|in:text,file,image,emoji',
            'reply_to_id' => 'sometimes|nullable|exists:messages,id',
        ]);

        $conversation = Conversation::findOrFail($validated['conversation_id']);

        if (!$conversation->users()->where('users.id', Auth::id())->exists()
            && Auth::user()->role?->name !== 'admin') {
            abort(403, 'You are not a participant in this conversation.');
        }

        if (!empty($validated['reply_to_id'])) {
            $replyMsg = Message::find($validated['reply_to_id']);
            if (!$replyMsg || $replyMsg->conversation_id !== $conversation->id) {
                abort(422, 'Reply message must belong to the same conversation.');
            }
        }

        $message = Message::create([
            'conversation_id' => $validated['conversation_id'],
            'user_id' => Auth::id(),
            'body' => $validated['body'],
            'type' => $validated['type'] ?? 'text',
            'reply_to_id' => $validated['reply_to_id'] ?? null,
        ]);

        // Update sender's last_read_at so online presence reflects activity
        $conversation->users()->updateExistingPivot(Auth::id(), [
            'last_read_at' => Carbon::now(),
        ]);

        $message->load(['user', 'replyTo.user', 'reactions.user']);

        return new MessageResource($message);
    }

    public function update(Request $request, Message $message)
    {
        $this->authorize('update', $message);

        $isAdmin = Auth::user()->role?->name === 'admin';
        if (!$isAdmin && $message->created_at->addMinutes(15)->isPast()) {
            abort(403, 'Messages can only be edited within 15 minutes of sending.');
        }

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message->update([
            'body' => $validated['body'],
            'status' => 'updated',
        ]);

        $message->load(['user', 'replyTo.user', 'reactions.user']);

        return new MessageResource($message);
    }

    public function destroy(Message $message)
    {
        $this->authorize('delete', $message);

        $message->update(['status' => 'deleted', 'body' => '']);

        return response()->json(['message' => 'Message deleted.']);
    }
}
