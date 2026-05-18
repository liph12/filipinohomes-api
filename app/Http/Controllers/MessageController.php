<?php

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use App\Jobs\SendMessageNotification;
use App\Models\BlockedUser;
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
            'body'            => 'nullable|string|max:5000',
            'type'            => 'sometimes|in:text,file,image,emoji',
            'reply_to_id'     => 'sometimes|nullable|exists:messages,id',
            'attachments'     => 'sometimes|array|max:10',
            'attachments.*'   => 'string|url|max:2048',
        ]);

        $body = isset($validated['body']) ? trim($validated['body']) : null;
        if ($body === '') {
            $body = null;
        }
        $attachments = $validated['attachments'] ?? [];

        // Require at least one of: body text or attachments. Empty bubbles
        // would render as nothing in the thread.
        if ($body === null && empty($attachments)) {
            abort(422, 'A message must include text or at least one attachment.');
        }

        // Default the type to "image" when attachments are present and no type
        // was explicitly sent. Keep "text" otherwise.
        $type = $validated['type'] ?? (!empty($attachments) ? 'image' : 'text');

        $conversation = Conversation::findOrFail($validated['conversation_id']);

        if (!$conversation->users()->where('users.id', Auth::id())->exists()
            && Auth::user()->role?->name !== 'admin') {
            abort(403, 'You are not a participant in this conversation.');
        }

        if (in_array($conversation->status, ['rejected', 'closed'])) {
            abort(403, 'Cannot send messages to a ' . $conversation->status . ' conversation.');
        }

        // Check if sender is blocked by the conversation's agent
        if ($conversation->agent_user_id) {
            $isBlocked = BlockedUser::where('agent_user_id', $conversation->agent_user_id)
                ->where('blocked_user_id', Auth::id())
                ->exists();
            if ($isBlocked) {
                abort(403, 'You have been blocked from this conversation.');
            }
        }

        if (!empty($validated['reply_to_id'])) {
            $replyMsg = Message::find($validated['reply_to_id']);
            if (!$replyMsg || $replyMsg->conversation_id !== $conversation->id) {
                abort(422, 'Reply message must belong to the same conversation.');
            }
        }

        $message = Message::create([
            'conversation_id' => $validated['conversation_id'],
            'user_id'         => Auth::id(),
            'body'            => $body,
            'type'            => $type,
            'attachments'     => !empty($attachments) ? $attachments : null,
            'reply_to_id'     => $validated['reply_to_id'] ?? null,
        ]);

        // Touch the parent chat so it sorts to the top of the list
        $conversation->chat->touch();

        // Update sender's last_read_at so online presence reflects activity
        $conversation->users()->updateExistingPivot(Auth::id(), [
            'last_read_at' => Carbon::now(),
        ]);

        // Dispatch notification job (async, with 30-min cooldown per recipient)
        SendMessageNotification::dispatch(
            $conversation->id,
            $message->id,
            Auth::id()
        );

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

        $isOwner = $message->user_id === Auth::id();
        $status = $isOwner ? 'unsent' : 'deleted';

        $message->update(['status' => $status, 'body' => '']);

        return response()->json(['message' => $isOwner ? 'Message unsent.' : 'Message deleted.']);
    }
}
