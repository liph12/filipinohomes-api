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

        // Use ConversationPolicy::view which already covers:
        //   - admin bypass (via the policy's before())
        //   - anyone in the conversation_users pivot
        //   - the assigned agent + any team leader of that agent, even
        //     before they're attached (lets a TL read a Pending Review
        //     inquiry so they can decide whether to accept it)
        // The previous inline check only honored admin + pivot
        // membership, which 403'd team-leader moderators on pending
        // inquiries since the assigned agent / TL isn't attached until
        // the inquiry is accepted.
        $this->authorize('view', $conversation);

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

        // Authorize sending the same way we authorize viewing (matches the
        // index() method above): admin, anyone in the conversation_users pivot,
        // the assigned agent, and any team leader of that agent. This lets a
        // TL/admin INTERVENE in a thread they moderate without first being
        // attached to the pivot. Previously a TL relied on markRead
        // auto-attaching them on open — which we removed so the pivot stops
        // bloating with every observer — so the old pivot-only check here would
        // now 403 them. They're attached below once they actually post; the
        // status + block checks further down still gate the send.
        $this->authorize('view', $conversation);

        if (in_array($conversation->status, ['rejected', 'closed'])) {
            abort(403, 'Cannot send messages to a ' . $conversation->status . ' conversation.');
        }

        // Sender blocked from messaging this agent? Scope-aware via the
        // helper: catches both per-agent rows (this specific agent) and
        // any global admin-issued ban regardless of agent.
        //
        // For listing chats `conversation.agent_user_id` is the agent.
        // For agent-direct ("Message Me") chats that column is null
        // because there's no separate "assigned agent" — the agent is
        // the chat's target user (`chat.type_id`). Fall through to that
        // when the conversation column is empty so a globally-banned
        // user can't keep messaging in an existing agent-direct thread.
        $blockTargetAgentId = (int) ($conversation->agent_user_id ?? 0);
        if (!$blockTargetAgentId) {
            $conversation->loadMissing('chat');
            if ($conversation->chat?->type === 'agent') {
                $blockTargetAgentId = (int) $conversation->chat->type_id;
            }
        }
        if ($blockTargetAgentId
            && BlockedUser::isBlocking((int) Auth::id(), $blockTargetAgentId)) {
            abort(403, 'You have been blocked from this conversation.');
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

        // Update sender's last_read_at so online presence reflects activity.
        // syncWithoutDetaching (not updateExistingPivot) so a moderating
        // admin/TL who sends into a thread they don't yet belong to gets
        // attached as a participant — that's what lets them receive push
        // notifications on the client's subsequent replies.
        $conversation->users()->syncWithoutDetaching([
            Auth::id() => ['last_read_at' => Carbon::now()],
        ]);

        // A new message resurfaces an archived thread: clear archived_at for
        // every recipient (participants other than the sender) so the chat
        // returns to their Inbox, matching Messenger. Trashed/purged rows are
        // left untouched — those are deliberate states a message shouldn't undo.
        $conversation->users()
            ->newPivotStatement()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', '!=', Auth::id())
            ->whereNotNull('archived_at')
            ->whereNull('removed_at')
            ->whereNull('purged_at')
            ->update(['archived_at' => null]);

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
