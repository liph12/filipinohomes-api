<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChatResource;
use App\Mail\MessageNotificationMailer;
use App\Models\Chat;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use App\Services\TeamLeadershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $roleName = $user->role?->name;

        // Show endpoint hydrates the rest on demand.
        $query = Chat::with([
            'user.role',
            'user.agent:id,user_id,mobile_no',
            'listing:id,name,slug,price,featured_photo,property_id',
            'listing.property:id,status',
            'activeConversation',
            'activeConversation.users.role',
            'activeConversation.users.agent:id,user_id,mobile_no',
            'activeConversation.agentUser.role',
            'activeConversation.agentUser.agent:id,user_id,mobile_no',
            'activeConversation.latestMessage.user.role',
            'activeConversation.latestMessage.user.agent:id,user_id,mobile_no',
        ]);

        if ($roleName === 'admin') {
            // admin sees all
        } elseif ($roleName === 'agent') {
            $ledIds = app(TeamLeadershipService::class)->getLedTeamMemberUserIds($user->id);

            $query->where(function ($q) use ($user, $ledIds) {
                $q->where('user_id', $user->id)
                    ->orWhereHas('conversations', function ($sub) use ($user) {
                        $sub->whereHas('users', fn ($u) => $u->where('users.id', $user->id))
                            ->whereIn('status', ['accepted', 'closed']);
                    });

                if (!empty($ledIds)) {
                    $q->orWhereHas('conversations', function ($sub) use ($ledIds) {
                        $sub->whereIn('agent_user_id', $ledIds);
                    });
                }
            });
        } else {
            $query->where('user_id', $user->id);
        }

        // Optional filters used by both the inquiries-page chips and the
        // single-row lookup callers (ContactForm / AgentDetailPage / useInquiry).
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($typeId = $request->query('type_id')) {
            $query->where('type_id', (int) $typeId);
        }
        if ($status = $request->query('status')) {
            if ($status !== 'all') {
                $query->whereHas('activeConversation', fn ($c) => $c->where('status', $status));
            }
        }
        if ($q = trim((string) $request->query('q'))) {
            $like = '%' . $q . '%';
            $query->where(function ($outer) use ($like) {
                $outer->whereHas('user', fn ($u) => $u->where('name', 'like', $like))
                    ->orWhereHas('listing', fn ($l) => $l->where('name', 'like', $like))
                    ->orWhereHas('activeConversation.users', fn ($u) => $u->where('users.name', 'like', $like));
            });
        }

        // Per-participant archive / trash view. The pivot lives on
        // conversation_users; the state machine is documented at the
        // 2026_05_23 migration:
        //   archived_at NULL + removed_at NULL  → inbox  (default)
        //   archived_at NOT NULL + removed_at NULL → archived
        //   removed_at NOT NULL → trash
        // Always scoped to Auth::id() so archiving on one side never hides
        // the chat from the other participant.
        $view = $request->query('view', 'inbox');
        if ($view === 'archived') {
            $query->whereHas('activeConversation.users', function ($q) use ($user) {
                $q->where('users.id', $user->id)
                    ->whereNotNull('conversation_users.archived_at')
                    ->whereNull('conversation_users.removed_at');
            });
        } elseif ($view === 'trash') {
            $query->whereHas('activeConversation.users', function ($q) use ($user) {
                $q->where('users.id', $user->id)
                    ->whereNotNull('conversation_users.removed_at');
            });
        } else {
            // Inbox (default): hide chats this viewer has personally
            // archived OR trashed. Other participants still see the chat
            // in their inbox if they haven't acted on it themselves.
            $query->whereHas('activeConversation.users', function ($q) use ($user) {
                $q->where('users.id', $user->id)
                    ->whereNull('conversation_users.archived_at')
                    ->whereNull('conversation_users.removed_at');
            });
        }

        $perPage = (int) $request->query('per_page', 10);
        $chats = $query->latest()->paginate(min(max($perPage, 1), 50));

        // Single aggregate query — no per-row COUNT.
        $conversationIds = $chats->getCollection()
            ->map(fn ($chat) => $chat->activeConversation?->id)
            ->filter()
            ->values()
            ->all();

        if (!empty($conversationIds)) {
            $unreadByConversation = DB::table('messages')
                ->select('messages.conversation_id', DB::raw('COUNT(*) as unread'))
                ->join('conversation_users', function ($join) use ($user) {
                    $join->on('conversation_users.conversation_id', '=', 'messages.conversation_id')
                        ->where('conversation_users.user_id', '=', $user->id);
                })
                ->whereIn('messages.conversation_id', $conversationIds)
                ->where('messages.user_id', '!=', $user->id)
                ->whereIn('messages.status', ['active', 'updated'])
                ->where(function ($w) {
                    $w->whereNull('conversation_users.last_read_at')
                        ->orWhereColumn('messages.created_at', '>', 'conversation_users.last_read_at');
                })
                ->groupBy('messages.conversation_id')
                ->pluck('unread', 'messages.conversation_id');

            foreach ($chats as $chat) {
                $conv = $chat->activeConversation;
                if ($conv) {
                    $conv->setAttribute('computed_unread_count', (int) ($unreadByConversation[$conv->id] ?? 0));
                }
            }
        }

        return ChatResource::collection($chats);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Chat::class);

        $user = Auth::user();

        $validated = $request->validate([
            'type' => 'required|in:listing,agent,blog,reel',
            'type_id' => 'required|integer|min:1',
            'target_user_id' => ['required', 'exists:users,id', function ($attribute, $value, $fail) use ($user) {
                if ((int) $value === $user->id) {
                    $fail('You cannot start a chat with yourself.');
                }
            }],
            'message' => 'sometimes|string|max:5000',
        ]);

        $existing = Chat::where('type', $validated['type'])
            ->where('type_id', $validated['type_id'])
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            $existing->load(['user', 'listing', 'activeConversation.latestMessage.user', 'activeConversation.users', 'activeConversation.agentUser']);

            // If the active conversation was rejected, allow re-inquiry by creating a new conversation
            if ($existing->activeConversation && $existing->activeConversation->status === 'rejected') {
                $isListing = $validated['type'] === 'listing';
                // Trusted senders skip Pending Review (see fresh-chat path
                // below for the same predicate + rationale).
                $autoAccept = $isListing
                    && $this->shouldAutoAcceptListingInquiry($user, (int) $validated['target_user_id']);

                DB::transaction(function () use ($existing, $validated, $user, $isListing, $autoAccept) {
                    $conversation = Conversation::create([
                        'chat_id' => $existing->id,
                        // Inquiries from trusted senders (admin, or a team
                        // leader inquiring on a listing owned by an agent
                        // in their team) skip Pending Review entirely —
                        // they already have moderation rights, so a self-
                        // submitted inquiry going through moderation is
                        // pointless and would block the agent from seeing
                        // the message until someone accepts.
                        'status' => !$isListing || $autoAccept ? 'accepted' : 'pending',
                        'agent_user_id' => $isListing ? $validated['target_user_id'] : null,
                        'reviewed_by' => $autoAccept ? $user->id : null,
                        'reviewed_at' => $autoAccept ? now() : null,
                    ]);

                    if ($isListing) {
                        $adminUserIds = User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->pluck('id')->toArray();
                        $attachments = [$user->id => ['last_read_at' => now()]];
                        foreach ($adminUserIds as $adminId) {
                            $attachments[$adminId] = ['last_read_at' => null];
                        }

                        $leaderUserId = app(TeamLeadershipService::class)
                            ->findTeamLeaderUserIdFor((int) $validated['target_user_id']);
                        if ($leaderUserId && $leaderUserId !== $user->id && !isset($attachments[$leaderUserId])) {
                            $attachments[$leaderUserId] = ['last_read_at' => null];
                        }

                        // For auto-accepted inquiries (admin or team-leader
                        // sender), attach the listing agent now so they can
                        // read the history immediately (normally added on
                        // accept by ConversationController::accept).
                        if ($autoAccept) {
                            $agentUserId = (int) $validated['target_user_id'];
                            if ($agentUserId !== $user->id) {
                                $attachments[$agentUserId] = [
                                    'last_read_at' => null,
                                    'last_notified_at' => now(),
                                ];
                            }
                        }

                        $conversation->users()->attach($attachments);
                    } else {
                        $conversation->users()->attach([
                            $user->id => ['last_read_at' => now()],
                            $validated['target_user_id'] => ['last_read_at' => null],
                        ]);
                    }

                    if (!empty($validated['message'])) {
                        Message::create([
                            'conversation_id' => $conversation->id,
                            'user_id' => $user->id,
                            'body' => $validated['message'],
                            'type' => 'text',
                        ]);
                    }
                });

                $existing->touch();
                $existing->load(['user', 'listing', 'activeConversation.latestMessage.user', 'activeConversation.users', 'activeConversation.agentUser']);

                // After a rejected → re-inquiry transition the new conversation
                // is functionally a fresh submission. Notify admins + team
                // leader so they can review it — same as the fresh-chat path
                // below. For admin-initiated re-inquiries (auto-accepted),
                // skip the submission fan-out and send the acceptance email
                // straight to the agent instead.
                if ($isListing) {
                    if ($autoAccept) {
                        $this->dispatchAcceptanceEmail(
                            chatId:       $existing->id,
                            listingId:    (int) $validated['type_id'],
                            agentUserId:  (int) $validated['target_user_id'],
                            message:      $validated['message'] ?? '',
                            sender:       $user,
                        );
                    } else {
                        $this->dispatchSubmissionEmail(
                            chatId:       $existing->id,
                            listingId:    (int) $validated['type_id'],
                            agentUserId:  (int) $validated['target_user_id'],
                            message:      $validated['message'] ?? '',
                            sender:       $user,
                        );
                    }
                }
            }

            return new ChatResource($existing);
        }

        // Trusted senders skip the Pending Review step entirely. Two senders
        // qualify (see shouldAutoAcceptListingInquiry below):
        //   - admins (full moderation rights)
        //   - team leaders inquiring on a listing owned by an agent in their
        //     team (they're the natural moderator for that team's inquiries,
        //     so requiring themselves to also click Accept is busy-work)
        $autoAccept = $validated['type'] === 'listing'
            && $this->shouldAutoAcceptListingInquiry($user, (int) $validated['target_user_id']);

        $chat = DB::transaction(function () use ($validated, $user, $autoAccept) {
            $chat = Chat::create([
                'type' => $validated['type'],
                'type_id' => $validated['type_id'],
                'user_id' => $user->id,
            ]);

            $isListing = $validated['type'] === 'listing';

            $conversation = Conversation::create([
                'chat_id' => $chat->id,
                // Trusted senders (admins + team leaders inquiring within
                // their own team) skip Pending Review — they already have
                // moderation rights, so blocking the agent from seeing the
                // message until someone clicks Accept is pointless.
                'status' => !$isListing || $autoAccept ? 'accepted' : 'pending',
                'agent_user_id' => $isListing ? $validated['target_user_id'] : null,
                'reviewed_by' => $autoAccept ? $user->id : null,
                'reviewed_at' => $autoAccept ? now() : null,
            ]);

            if ($isListing) {
                // Attach client + all admin users + team leader (not the agent yet)
                $adminUserIds = User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->pluck('id')->toArray();

                $attachments = [
                    $user->id => ['last_read_at' => now()],
                ];
                foreach ($adminUserIds as $adminId) {
                    $attachments[$adminId] = ['last_read_at' => null];
                }

                $leaderUserId = app(TeamLeadershipService::class)
                    ->findTeamLeaderUserIdFor((int) $validated['target_user_id']);
                if ($leaderUserId && $leaderUserId !== $user->id && !isset($attachments[$leaderUserId])) {
                    $attachments[$leaderUserId] = ['last_read_at' => null];
                }

                // For auto-accepted inquiries (admin or team-leader sender)
                // attach the listing agent now so they can read the history
                // immediately. Without this they'd see the chat row in their
                // inquiries inbox (the agent-side ChatController::index
                // surfaces it via agent_user_id-in-ledIds) but
                // MessageController::index would 403 because they aren't in
                // conversation_users — that's exactly the empty-messages
                // symptom reported on the team leader's side.
                if ($autoAccept) {
                    $agentUserId = (int) $validated['target_user_id'];
                    if ($agentUserId !== $user->id) {
                        $attachments[$agentUserId] = [
                            'last_read_at' => null,
                            'last_notified_at' => now(),
                        ];
                    }
                }

                $conversation->users()->attach($attachments);
            } else {
                // Non-listing: attach client + target user directly (accepted)
                $conversation->users()->attach([
                    $user->id => ['last_read_at' => now()],
                    $validated['target_user_id'] => ['last_read_at' => null],
                ]);
            }

            if (!empty($validated['message'])) {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                    'body' => $validated['message'],
                    'type' => 'text',
                ]);
            }

            return $chat;
        });

        $chat->load(['user', 'listing', 'activeConversation.latestMessage.user', 'activeConversation.users', 'activeConversation.agentUser']);

        // Email fan-out fires AFTER the DB transaction commits.
        //   - Trusted sender (admin or team leader in scope) → acceptance
        //     email straight to the agent; the inquiry is already auto-
        //     accepted, and emailing the moderators their own submission
        //     would be noise.
        //   - Anyone else → submission email to admins + team leader for
        //     moderation (same as before).
        //
        // BCC pattern is preserved by MessageNotificationMailer (admin /
        // leader / agent addresses always live in BCC; TO header stays
        // info@filipinohomes.com). See MessageNotificationMailer.php:
        // dispatchForSubmission + dispatchForAcceptance.
        if ($validated['type'] === 'listing') {
            if ($autoAccept) {
                $this->dispatchAcceptanceEmail(
                    chatId:       $chat->id,
                    listingId:    (int) $validated['type_id'],
                    agentUserId:  (int) $validated['target_user_id'],
                    message:      $validated['message'] ?? '',
                    sender:       $user,
                );
            } else {
                $this->dispatchSubmissionEmail(
                    chatId:       $chat->id,
                    listingId:    (int) $validated['type_id'],
                    agentUserId:  (int) $validated['target_user_id'],
                    message:      $validated['message'] ?? '',
                    sender:       $user,
                );
            }
        }

        return new ChatResource($chat);
    }

    /**
     * Trusted-sender predicate for listing inquiries. Returns true when
     * the sender's own submission should bypass Pending Review:
     *
     *   - Admin (role.name = 'admin') — full moderation rights.
     *   - Team leader inquiring on a listing whose agent is in their own
     *     team. The leader is the natural moderator for that team's
     *     inquiries, so requiring them to also click Accept on their own
     *     submission is busy-work and would block the agent from reading
     *     the message until acceptance.
     *
     * Returns false for everyone else (regular clients, regular agents
     * inquiring outside their team, etc.) so the existing moderation
     * flow stays intact for normal traffic.
     */
    private function shouldAutoAcceptListingInquiry(
        User $sender,
        int $targetAgentUserId,
    ): bool {
        $roleName = $sender->role?->name;

        if ($roleName === 'admin') {
            return true;
        }

        if ($roleName === 'agent') {
            $ledMemberUserIds = app(TeamLeadershipService::class)
                ->getLedTeamMemberUserIds($sender->id);
            return in_array($targetAgentUserId, $ledMemberUserIds, true);
        }

        return false;
    }

    /**
     * Fan-out for the listing-inquiry submission email. Shared between
     * the fresh-chat path and the rejected → re-inquiry path so the
     * BCC pattern and relation-loading logic live in exactly one place.
     */
    private function dispatchSubmissionEmail(
        int $chatId,
        int $listingId,
        int $agentUserId,
        string $message,
        User $sender,
    ): void {
        $listing = Listing::with([
            'agent',
            'category',
            'property.barangay.city.province',
            'property.propertyAttribute.subtype.type',
        ])->find($listingId);

        if (!$listing) {
            // Listing was deleted between validation and dispatch (race).
            // The conversation row still exists for moderation; just skip
            // the email rather than crashing the queue worker.
            return;
        }

        // Load the sender's agent profile so the email can surface their
        // WhatsApp number when they're an agent. Skipped silently for
        // regular clients (the relation just returns null).
        $sender->loadMissing('agent');

        // Slug shape matches ConversationController@accept so the frontend's
        // ListingInquiries component (which matches `{slug}-{chat.id}`) can
        // route from the email CTA back to the right inquiry.
        $slug = Str::slug($listing->name) . '-' . $chatId;

        MessageNotificationMailer::dispatchForSubmission(
            sender:      $sender,
            message:     $message,
            slug:        $slug,
            listing:     MessageNotificationMailer::buildListingPayload($listing),
            agentUserId: $agentUserId,
        );
    }

    /**
     * Acceptance-email fan-out used by admin-initiated listing inquiries
     * (which auto-accept on submit — see store() above). Mirrors
     * dispatchSubmissionEmail's relation-loading + slug shape but routes
     * to dispatchForAcceptance so the agent (not admins/leader) gets the
     * "you've been assigned" email — matching what the moderation accept
     * path in ConversationController::accept produces.
     */
    private function dispatchAcceptanceEmail(
        int $chatId,
        int $listingId,
        int $agentUserId,
        string $message,
        User $sender,
    ): void {
        $listing = Listing::with([
            'agent',
            'category',
            'property.barangay.city.province',
            'property.propertyAttribute.subtype.type',
        ])->find($listingId);

        if (!$listing) {
            return;
        }

        $agent = User::find($agentUserId);
        if (!$agent) {
            return;
        }

        $sender->loadMissing('agent');

        $slug = Str::slug($listing->name) . '-' . $chatId;

        MessageNotificationMailer::dispatchForAcceptance(
            sender:      $sender,
            agent:       $agent,
            message:     $message,
            slug:        $slug,
            listing:     MessageNotificationMailer::buildListingPayload($listing),
            agentUserId: $agentUserId,
        );
    }

    public function show(Chat $chat)
    {
        $this->authorize('view', $chat);

        $chat->load([
            'user',
            'listing.property',
            'conversations.latestMessage.user',
            'conversations.users',
            'activeConversation.agentUser',
            'activeConversation.users',
            'activeConversation.latestMessage.user',
        ]);

        // Compute unread for the active conversation so the show response
        // matches the list shape.
        $user = Auth::user();
        $conv = $chat->activeConversation;
        if ($conv) {
            $pivot = $conv->users->firstWhere('id', $user->id)?->pivot;
            $lastReadAt = $pivot?->last_read_at;
            $unreadQuery = $conv->messages()
                ->where('user_id', '!=', $user->id)
                ->whereIn('status', ['active', 'updated']);
            if ($lastReadAt) {
                $unreadQuery->where('created_at', '>', $lastReadAt);
            }
            $conv->setAttribute('computed_unread_count', $unreadQuery->count());
        }

        return new ChatResource($chat);
    }

    public function destroy(Chat $chat)
    {
        $this->authorize('delete', $chat);

        $chat->delete();

        return response()->json(['message' => 'Chat deleted.']);
    }

    /**
     * Per-participant archive / trash actions.
     *
     * All four methods mutate the conversation_users pivot row for the
     * authenticated viewer × the chat's active_conversation. The
     * conversation's own status (pending / accepted / closed / rejected)
     * is untouched — this is a personal inbox shelf, not a state change.
     *
     * State machine (see the 2026_05_23 migration):
     *   archived_at NULL + removed_at NULL → Inbox      (default)
     *   archived_at NOT NULL + removed_at NULL → Archived
     *   removed_at NOT NULL → Trash
     *
     * Restore from Trash clears both columns so the row returns straight
     * to the inbox (mirrors Gmail's "Move to Inbox" from Trash).
     */
    public function archive(Chat $chat)
    {
        return $this->mutateViewerPivot($chat, [
            'archived_at' => now(),
        ]);
    }

    public function unarchive(Chat $chat)
    {
        return $this->mutateViewerPivot($chat, [
            'archived_at' => null,
        ]);
    }

    public function trash(Chat $chat)
    {
        return $this->mutateViewerPivot($chat, [
            'removed_at' => now(),
        ]);
    }

    public function restore(Chat $chat)
    {
        return $this->mutateViewerPivot($chat, [
            'archived_at' => null,
            'removed_at'  => null,
        ]);
    }

    /**
     * Shared pivot mutation for archive / unarchive / trash / restore.
     * Authorizes via the existing `view` ability so admins (who bypass
     * the participant check via ChatPolicy) can still act on chats they
     * don't appear in the pivot for — but only after we attach them, so
     * the row exists to update. For non-admins the pivot row is
     * guaranteed to exist already (ChatController::store attaches the
     * client + admins + leader at submission time).
     */
    private function mutateViewerPivot(Chat $chat, array $columns)
    {
        $this->authorize('view', $chat);

        $activeConv = $chat->activeConversation;
        if (!$activeConv) {
            return response()->json(
                ['message' => 'This chat has no active conversation.'],
                404,
            );
        }

        $userId = Auth::id();

        // Admins may not have a pivot row if they were promoted after the
        // conversation was created. Attach them lazily so the update has
        // something to act on; matches the existing "admins see all"
        // semantic in index().
        if (!$activeConv->users()->where('users.id', $userId)->exists()) {
            $activeConv->users()->attach($userId, [
                'last_read_at' => null,
            ]);
        }

        $activeConv->users()->updateExistingPivot($userId, $columns);

        $chat->load([
            'user',
            'listing',
            'activeConversation.latestMessage.user',
            'activeConversation.users',
            'activeConversation.agentUser',
        ]);

        return new ChatResource($chat);
    }

    /**
     * Aggregate counts for the /admin/chat-statistics page.
     *
     * Returns:
     *   - totals.{all,listing,agent,blog,reel} — overall chat counts per type
     *   - listing_inquiries — totals + agent_replied + by_status + reply_rate
     *     (a listing inquiry is "agent_replied" when the assigned agent has
     *     authored at least one non-deleted/unsent message in the conversation)
     *   - per_team — same shape per team (joined via team_agents on the
     *     assigned agent's user_id)
     *   - per_agent — same shape per agent_user_id, enriched with display info
     *
     * Each chat is represented by its LATEST conversation only (matches the
     * frontend's `active_conversation` semantic) so a closed-then-re-inquired
     * chat is never double-counted.
     */
    public function stats(Request $request)
    {
        $this->authorize('viewStats', Chat::class);

        // Optional date-range filter. Applies to the LATEST conversation per
        // chat (the same conversation the rest of this method aggregates over)
        // — "inquiries with activity in this window". Both bounds optional;
        // missing dates mean "no lower / no upper bound". Accepts YYYY-MM-DD.
        $validated = $request->validate([
            'date_from' => 'sometimes|nullable|date',
            'date_to'   => 'sometimes|nullable|date|after_or_equal:date_from',
        ]);
        $dateFrom = !empty($validated['date_from'])
            ? \Carbon\Carbon::parse($validated['date_from'])->startOfDay()
            : null;
        $dateTo = !empty($validated['date_to'])
            ? \Carbon\Carbon::parse($validated['date_to'])->endOfDay()
            : null;

        $applyDateFilter = function ($q) use ($dateFrom, $dateTo) {
            if ($dateFrom) {
                $q->where('c.created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $q->where('c.created_at', '<=', $dateTo);
            }
        };

        // 1) Chat totals by type — respect the date filter when present so the
        //    tab counts stay consistent with listing_inquiries.total.
        //    Anchor: a chat is "in this window" if its latest conversation's
        //    created_at falls inside [date_from, date_to].
        $byTypeQuery = DB::table('chats')
            ->whereNull('chats.deleted_at')
            ->select('chats.type', DB::raw('COUNT(*) as n'))
            ->groupBy('chats.type');

        if ($dateFrom || $dateTo) {
            $byTypeQuery->whereExists(function ($q) use ($dateFrom, $dateTo) {
                $q->select(DB::raw(1))
                  ->from('conversations as c')
                  ->whereColumn('c.chat_id', 'chats.id')
                  ->whereNull('c.deleted_at')
                  ->whereRaw('c.id = (SELECT MAX(c2.id) FROM conversations c2 WHERE c2.chat_id = chats.id AND c2.deleted_at IS NULL)');
                if ($dateFrom) {
                    $q->where('c.created_at', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $q->where('c.created_at', '<=', $dateTo);
                }
            });
        }

        $byType = $byTypeQuery->pluck('n', 'type')->toArray();

        $totals = [
            'all'     => (int) array_sum($byType),
            'listing' => (int) ($byType['listing'] ?? 0),
            'agent'   => (int) ($byType['agent']   ?? 0),
            'blog'    => (int) ($byType['blog']    ?? 0),
            'reel'    => (int) ($byType['reel']    ?? 0),
        ];

        // Subquery: "latest conversation per chat" — same scope the chat list
        // uses for `active_conversation`. Soft-deleted conversations are
        // excluded so a deleted re-inquiry doesn't bubble up as latest.
        $latestConvSub = DB::table('conversations')
            ->select(DB::raw('MAX(id) as id'))
            ->whereNull('deleted_at')
            ->groupBy('chat_id');

        // Shared SQL fragment: the conversation is "agent_replied" when the
        // assigned agent has authored a non-removed message in it. Used in
        // multiple aggregate sums below.
        $agentRepliedExists = "EXISTS (
            SELECT 1 FROM messages m
            WHERE m.conversation_id = c.id
              AND m.user_id = c.agent_user_id
              AND m.status NOT IN ('deleted', 'unsent')
        )";

        // 2) Listing-inquiry rollup (single grouped query)
        $listingAgg = DB::table('conversations as c')
            ->joinSub($latestConvSub, 'lc', 'lc.id', '=', 'c.id')
            ->join('chats', 'chats.id', '=', 'c.chat_id')
            ->whereNull('chats.deleted_at')
            ->where('chats.type', 'listing')
            ->tap($applyDateFilter)
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN c.status = 'pending'  THEN 1 ELSE 0 END) as s_pending"),
                DB::raw("SUM(CASE WHEN c.status = 'accepted' THEN 1 ELSE 0 END) as s_accepted"),
                DB::raw("SUM(CASE WHEN c.status = 'rejected' THEN 1 ELSE 0 END) as s_rejected"),
                DB::raw("SUM(CASE WHEN c.status = 'closed'   THEN 1 ELSE 0 END) as s_closed"),
                DB::raw("SUM(CASE WHEN c.agent_user_id IS NOT NULL AND {$agentRepliedExists} THEN 1 ELSE 0 END) as agent_replied")
            )
            ->first();

        $listingTotal = (int) ($listingAgg->total ?? 0);
        $agentReplied = (int) ($listingAgg->agent_replied ?? 0);
        $notReplied   = max(0, $listingTotal - $agentReplied);

        $listingInquiries = [
            'total'         => $listingTotal,
            'agent_replied' => $agentReplied,
            'not_replied'   => $notReplied,
            'by_status'     => [
                'pending'  => (int) ($listingAgg->s_pending  ?? 0),
                'accepted' => (int) ($listingAgg->s_accepted ?? 0),
                'rejected' => (int) ($listingAgg->s_rejected ?? 0),
                'closed'   => (int) ($listingAgg->s_closed   ?? 0),
            ],
            'reply_rate'    => $listingTotal > 0
                ? round(($agentReplied / $listingTotal) * 100, 1)
                : 0.0,
        ];

        // 3) Per-agent rollup (listing chats, latest conversation, agent assigned)
        $perAgentRows = DB::table('conversations as c')
            ->joinSub($latestConvSub, 'lc', 'lc.id', '=', 'c.id')
            ->join('chats', 'chats.id', '=', 'c.chat_id')
            ->whereNull('chats.deleted_at')
            ->where('chats.type', 'listing')
            ->whereNotNull('c.agent_user_id')
            ->tap($applyDateFilter)
            ->groupBy('c.agent_user_id')
            ->select(
                'c.agent_user_id',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN c.status = 'pending'  THEN 1 ELSE 0 END) as s_pending"),
                DB::raw("SUM(CASE WHEN c.status = 'accepted' THEN 1 ELSE 0 END) as s_accepted"),
                DB::raw("SUM(CASE WHEN c.status = 'rejected' THEN 1 ELSE 0 END) as s_rejected"),
                DB::raw("SUM(CASE WHEN c.status = 'closed'   THEN 1 ELSE 0 END) as s_closed"),
                DB::raw("SUM(CASE WHEN {$agentRepliedExists} THEN 1 ELSE 0 END) as agent_replied")
            )
            ->get();

        // Enrich each agent_user_id with display info (name / avatar / team).
        $agentUserIds = $perAgentRows->pluck('agent_user_id')->all();
        $userInfo = collect();
        if (!empty($agentUserIds)) {
            $userInfo = DB::table('users as u')
                ->leftJoin('agents as a', 'a.user_id', '=', 'u.id')
                ->leftJoin('team_agents as ta', function ($j) {
                    $j->on('ta.agent_id', '=', 'a.id')
                      ->where('ta.status', '=', 'active');
                })
                ->leftJoin('teams as t', 't.id', '=', 'ta.team_id')
                ->whereIn('u.id', $agentUserIds)
                ->select(
                    'u.id as user_id',
                    'u.name as name',
                    'u.avatar as avatar',
                    't.id as team_id',
                    't.name as team_name'
                )
                ->get()
                ->keyBy('user_id');
        }

        $perAgent = $perAgentRows->map(function ($row) use ($userInfo) {
            $info = $userInfo[$row->agent_user_id] ?? null;
            $total = (int) $row->total;
            $replied = (int) $row->agent_replied;
            return [
                'agent_user_id' => (int) $row->agent_user_id,
                'name'          => $info?->name ?? ('Agent #' . $row->agent_user_id),
                'avatar'        => $info?->avatar,
                'team_id'       => isset($info->team_id) ? (int) $info->team_id : null,
                'team_name'     => $info?->team_name,
                'total'         => $total,
                'agent_replied' => $replied,
                'not_replied'   => max(0, $total - $replied),
                'by_status'     => [
                    'pending'  => (int) $row->s_pending,
                    'accepted' => (int) $row->s_accepted,
                    'rejected' => (int) $row->s_rejected,
                    'closed'   => (int) $row->s_closed,
                ],
                'reply_rate'    => $total > 0
                    ? round(($replied / $total) * 100, 1)
                    : 0.0,
            ];
        })->values()->toArray();

        // 4) Per-team meta: team display info + leader name + active agent count.
        $teamMeta = DB::table('teams as t')
            ->leftJoin('team_agents as la', function ($j) {
                $j->on('la.team_id', '=', 't.id')
                  ->where('la.status', '=', 'active')
                  ->where('la.is_leader', '=', true);
            })
            ->leftJoin('agents as la_a', 'la_a.id', '=', 'la.agent_id')
            ->leftJoin('users as la_u', 'la_u.id', '=', 'la_a.user_id')
            ->leftJoin('team_agents as ag', function ($j) {
                $j->on('ag.team_id', '=', 't.id')
                  ->where('ag.status', '=', 'active');
            })
            ->select(
                't.id as team_id',
                't.name as team_name',
                'la_u.name as leader_name',
                DB::raw('COUNT(DISTINCT ag.agent_id) as agent_count')
            )
            ->groupBy('t.id', 't.name', 'la_u.name')
            ->get();

        // Aggregate the per_agent rollups by team for the per_team payload.
        $teamRollup = [];
        foreach ($perAgent as $agent) {
            $tid = $agent['team_id'];
            if (!$tid) {
                continue;
            }
            if (!isset($teamRollup[$tid])) {
                $teamRollup[$tid] = [
                    'total'         => 0,
                    'agent_replied' => 0,
                    'by_status'     => [
                        'pending'  => 0,
                        'accepted' => 0,
                        'rejected' => 0,
                        'closed'   => 0,
                    ],
                ];
            }
            $teamRollup[$tid]['total']         += $agent['total'];
            $teamRollup[$tid]['agent_replied'] += $agent['agent_replied'];
            foreach ($agent['by_status'] as $s => $n) {
                $teamRollup[$tid]['by_status'][$s] += $n;
            }
        }

        $perTeam = $teamMeta->map(function ($t) use ($teamRollup) {
            $tid = (int) $t->team_id;
            $r = $teamRollup[$tid] ?? [
                'total'         => 0,
                'agent_replied' => 0,
                'by_status'     => [
                    'pending'  => 0,
                    'accepted' => 0,
                    'rejected' => 0,
                    'closed'   => 0,
                ],
            ];
            $total = (int) $r['total'];
            $replied = (int) $r['agent_replied'];
            return [
                'team_id'       => $tid,
                'team_name'     => (string) $t->team_name,
                'leader_name'   => $t->leader_name,
                'agent_count'   => (int) $t->agent_count,
                'total'         => $total,
                'agent_replied' => $replied,
                'not_replied'   => max(0, $total - $replied),
                'by_status'     => $r['by_status'],
                'reply_rate'    => $total > 0
                    ? round(($replied / $total) * 100, 1)
                    : 0.0,
            ];
        })->values()->toArray();

        return response()->json([
            'totals'            => $totals,
            'listing_inquiries' => $listingInquiries,
            'per_team'          => $perTeam,
            'per_agent'         => $perAgent,
        ]);
    }
}
