<?php

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
            ->with('user')
            ->orderBy('created_at')
            ->paginate(50);

        return MessageResource::collection($messages);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
            'body' => 'required|string|max:5000',
            'type' => 'sometimes|in:text,file,image,emoji',
        ]);

        $conversation = Conversation::findOrFail($validated['conversation_id']);

        if (!$conversation->users()->where('users.id', Auth::id())->exists()
            && Auth::user()->role?->name !== 'admin') {
            abort(403, 'You are not a participant in this conversation.');
        }

        $message = Message::create([
            'conversation_id' => $validated['conversation_id'],
            'user_id' => Auth::id(),
            'body' => $validated['body'],
            'type' => $validated['type'] ?? 'text',
        ]);

        $message->load('user');

        return new MessageResource($message);
    }

    public function update(Request $request, Message $message)
    {
        $this->authorize('update', $message);

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message->update([
            'body' => $validated['body'],
            'status' => 'updated',
        ]);

        $message->load('user');

        return new MessageResource($message);
    }

    public function destroy(Message $message)
    {
        $this->authorize('delete', $message);

        $message->update(['status' => 'deleted', 'body' => '']);

        return response()->json(['message' => 'Message deleted.']);
    }
}
