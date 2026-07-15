<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChatResource;
use App\Jobs\SendInquiryReviewNotification;
use App\Mail\MessageNotificationMailer;
use App\Models\Agent;
use App\Models\BlockedUser;
use App\Models\Chat;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\TeamAgent;
use App\Models\User;
use App\Models\UserInfo;
use App\Services\AuditMailService;
use App\Services\AuditSecurityService;
use App\Services\ChatRateLimitService;
use App\Services\TeamLeadershipService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $roleName = $user->role?->name;

        // Show endpoint hydrates the rest on demand.
        $query = Chat::with([
            'user.role',
            'user.agent:id,user_id,mobile_no,whats_app_no',
            'listing:id,name,slug,price,featured_photo,property_id',
            'listing.property:id,status',
            'activeConversation',
            'activeConversation.users.role',
            'activeConversation.users.agent:id,user_id,mobile_no,whats_app_no',
            'activeConversation.agentUser.role',
            'activeConversation.agentUser.agent:id,user_id,mobile_no,whats_app_no',
            'activeConversation.latestMessage.user.role',
            'activeConversation.latestMessage.user.agent:id,user_id,mobile_no,whats_app_no',
        ]);

        // Team-dashboard reply monitoring: attach two correlated aggregates per
        // chat — whether the assigned agent has ever replied (any live message
        // authored by conversation.agent_user_id), and the timestamp of the
        // inquirer's (chats.user_id) most recent live message ("client last
        // reply", used to spot clients who've gone quiet). Gated behind an opt-in
        // flag so the hot shared inbox pays nothing for these subqueries.
        if ($request->boolean('with_reply_stats')) {
            $query->addSelect('chats.*')
                ->selectSub(function ($q) {
                    $q->from('messages as m')
                        ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
                        ->whereColumn('c.chat_id', 'chats.id')
                        ->whereColumn('m.user_id', 'c.agent_user_id')
                        ->whereNotIn('m.status', ['deleted', 'unsent'])
                        ->selectRaw('CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END');
                }, 'agent_replied')
                ->selectSub(function ($q) {
                    $q->from('messages as m')
                        ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
                        ->whereColumn('c.chat_id', 'chats.id')
                        ->whereColumn('m.user_id', 'chats.user_id')
                        ->whereNotIn('m.status', ['deleted', 'unsent'])
                        ->selectRaw('MAX(m.created_at)');
                }, 'client_last_reply_at');

            // Keep soft-deleted listings visible so the team dashboard can flag
            // inquiries whose listing has since been removed (the default
            // relation scope would drop them, leaving the row with no listing).
            $query->with(['listing' => fn ($q) => $q->withTrashed()]);
        }

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

                if (! empty($ledIds)) {
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
        // "Have I already messaged X?" lookups must always be scoped to the
        // viewer's own chats. Without this, admins (who see all chats by
        // default in the role branch above) get any user's chat with the
        // target agent and the UI falsely shows "Message Already Sent."
        if ($request->boolean('mine')) {
            $query->where('user_id', $user->id);
        }

        // Mine vs Moderating filter for admin/TL inboxes.
        //   scope=mine       — chats the viewer is PERSONALLY driving:
        //                      they're either the chat owner OR the
        //                      assigned agent. Team-member chats do
        //                      NOT count here (a TL doesn't "own" a
        //                      conversation assigned to one of their
        //                      agents — that's their team member's
        //                      job to drive). Team-member chats fall
        //                      under Moderating.
        //   scope=moderating — everything else they can see. For a
        //                      TL: chats assigned to their team's
        //                      agents that they oversee but aren't
        //                      personally driving. For an admin: every
        //                      other chat in the system.
        //   scope=all (or absent) — no scope filter (back-compat).
        // Independent of the `mine` boolean above, which exists for
        // duplicate-detection in MessageMeCard and stays untouched.
        $scope = $request->query('scope');
        if ($scope === 'mine' || $scope === 'moderating') {
            if ($scope === 'mine') {
                $query->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhereHas('activeConversation', function ($c) use ($user) {
                            $c->where('agent_user_id', $user->id);
                        });
                });
            } else {
                $query->where('user_id', '!=', $user->id)
                    ->whereDoesntHave('activeConversation', function ($c) use ($user) {
                        $c->where('agent_user_id', $user->id);
                    });
            }
        } elseif ($scope === 'team' && $roleName === 'admin') {
            // Admin-only "Team" filter: conversations assigned to an agent who
            // is an active member of any team.
            $teamUserIds = Agent::whereIn(
                'id',
                TeamAgent::where('status', 'active')->pluck('agent_id')
            )->whereNotNull('user_id')->pluck('user_id')->all();
            $query->whereHas('activeConversation', function ($c) use ($teamUserIds) {
                $c->whereIn('agent_user_id', $teamUserIds);
            });
            // Eager-load the assigned agent's active team so the inbox can
            // group inquiries by team (square logo + name → agents' inquiries).
            $query->with([
                'activeConversation.agentUser.agent.teamMembers' => fn ($q) => $q->where('status', 'active'),
                'activeConversation.agentUser.agent.teamMembers.team',
            ]);
        }

        if ($status = $request->query('status')) {
            if ($status !== 'all') {
                // Accept a single status or a comma-separated set (e.g.
                // "pending,accepted" for the team dashboard's "active" feed).
                $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $status))));
                $query->whereHas('activeConversation', fn ($c) => $c->whereIn('status', $statuses));
            }
        }

        // Filter inquiries by the inquirer's role (agents also send inquiries,
        // so this lets moderators isolate client vs. agent inquiries).
        if ($inquirerRole = $request->query('inquirer_role')) {
            if ($inquirerRole !== 'all') {
                $query->whereHas('user.role', fn ($r) => $r->where('name', $inquirerRole));
            }
        }
        if ($q = trim((string) $request->query('q'))) {
            $like = '%'.$q.'%';
            $query->where(function ($outer) use ($like) {
                $outer->whereHas('user', fn ($u) => $u->where('name', 'like', $like))
                    ->orWhereHas('listing', fn ($l) => $l->where('name', 'like', $like))
                    ->orWhereHas('activeConversation.users', fn ($u) => $u->where('users.name', 'like', $like));
            });
        }

        // Per-participant archive / trash / permanent-delete view. The
        // pivot lives on conversation_users; the full state machine is
        // documented at the 2026_05_23 + 2026_05_24 migrations:
        //   archived_at NULL + removed_at NULL + purged_at NULL → Inbox  (default)
        //   archived_at NOT NULL + removed_at NULL + purged_at NULL → Archived
        //   removed_at NOT NULL + purged_at NULL → Trash
        //   purged_at NOT NULL → hidden from every view for this viewer
        //
        // Purged rows are excluded from ALL three views unconditionally.
        // The row stays in the DB so an admin tool can recover it later.
        $purgedExclusion = function ($q) use ($user) {
            $q->where('users.id', $user->id)
                ->whereNotNull('conversation_users.purged_at');
        };

        $view = $request->query('view', 'inbox');
        if ($view === 'archived') {
            // Archived and Trash REQUIRE an explicit pivot row with the
            // matching flag — whereHas is correct here.
            $query->whereHas('activeConversation.users', function ($q) use ($user) {
                $q->where('users.id', $user->id)
                    ->whereNotNull('conversation_users.archived_at')
                    ->whereNull('conversation_users.removed_at')
                    ->whereNull('conversation_users.purged_at');
            });
        } elseif ($view === 'trash') {
            $query->whereHas('activeConversation.users', function ($q) use ($user) {
                $q->where('users.id', $user->id)
                    ->whereNotNull('conversation_users.removed_at')
                    ->whereNull('conversation_users.purged_at');
            });
        } else {
            // Inbox (default). EXCLUDE chats this viewer has archived /
            // trashed / purged — but don't require them to be in the
            // pivot at all (admins promoted after a chat was created
            // have no pivot row in that chat, and they should still see
            // it via the "admin sees all" role branch above).
            $query->whereDoesntHave('activeConversation.users', function ($q) use ($user) {
                $q->where('users.id', $user->id)
                    ->where(function ($w) {
                        $w->whereNotNull('conversation_users.archived_at')
                            ->orWhereNotNull('conversation_users.removed_at')
                            ->orWhereNotNull('conversation_users.purged_at');
                    });
            });
        }
        // Belt-and-suspenders: purged rows are never visible regardless
        // of view, even if a future view branch forgets the exclusion.
        $query->whereDoesntHave('activeConversation.users', $purgedExclusion);

        // Lightweight unread-badge feed. The bottom-nav / sub-tab badges only
        // need the unread totals, not a page of rich chat objects. Reusing the
        // fully-filtered $query keeps the counts identical to what the inbox
        // list would show (role scope, inbox view, archived/trashed/purged
        // exclusions all already applied) with zero divergence risk — but we
        // skip the ChatResource serialization and pagination and instead sum
        // unread in a single aggregate, the same rule as computed_unread_count
        // below. Uncapped, so it can't undercount the way a fixed page size
        // would once a viewer has many conversations.
        if ($request->boolean('unread_only')) {
            $visible = (clone $query)
                ->setEagerLoads([])
                ->with(['activeConversation' => fn ($q) => $q->select('conversations.id', 'conversations.chat_id', 'conversations.agent_user_id')])
                ->get(['chats.id', 'chats.type', 'chats.user_id']);

            $typeByConv = [];
            // For listing inquiries, tag each conversation mine|team so the badge
            // can split "My Inquiries" (the viewer personally drives it) from
            // "Team Inquiries" (a team member's thread a leader moderates) — the
            // same rule as the scope=mine filter above.
            $scopeByConv = [];
            foreach ($visible as $chat) {
                $conv = $chat->activeConversation;
                if (! $conv) {
                    continue;
                }
                $typeByConv[$conv->id] = $chat->type;
                $mine = ((int) $conv->agent_user_id === (int) $user->id)
                    || ((int) $chat->user_id === (int) $user->id);
                $scopeByConv[$conv->id] = $mine ? 'mine' : 'team';
            }

            $result = [
                'total'          => 0,
                'inquiries'      => 0,
                'messages'       => 0,
                'inquiries_mine' => 0,
                'inquiries_team' => 0,
            ];

            if (! empty($typeByConv)) {
                $unreadByConversation = DB::table('messages')
                    ->select('messages.conversation_id', DB::raw('COUNT(*) as unread'))
                    ->join('conversation_users', function ($join) use ($user) {
                        $join->on('conversation_users.conversation_id', '=', 'messages.conversation_id')
                            ->where('conversation_users.user_id', '=', $user->id);
                    })
                    ->whereIn('messages.conversation_id', array_keys($typeByConv))
                    ->where('messages.user_id', '!=', $user->id)
                    ->whereIn('messages.status', ['active', 'updated'])
                    ->where(function ($w) {
                        $w->whereNull('conversation_users.last_read_at')
                            ->orWhereColumn('messages.created_at', '>', 'conversation_users.last_read_at');
                    })
                    ->groupBy('messages.conversation_id')
                    ->pluck('unread', 'messages.conversation_id');

                foreach ($unreadByConversation as $cid => $n) {
                    $n = (int) $n;
                    $result['total'] += $n;
                    $type = $typeByConv[$cid] ?? null;
                    if ($type === 'listing') {
                        $result['inquiries'] += $n;
                        if (($scopeByConv[$cid] ?? 'team') === 'mine') {
                            $result['inquiries_mine'] += $n;
                        } else {
                            $result['inquiries_team'] += $n;
                        }
                    } elseif ($type === 'agent') {
                        $result['messages'] += $n;
                    }
                }
            }

            return response()->json($result);
        }

        $perPage = (int) $request->query('per_page', 10);
        $chats = $query->latest()->paginate(min(max($perPage, 1), 50));

        // Single aggregate query — no per-row COUNT.
        $conversationIds = $chats->getCollection()
            ->map(fn ($chat) => $chat->activeConversation?->id)
            ->filter()
            ->values()
            ->all();

        if (! empty($conversationIds)) {
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
            // Origin of an agent inquiry — drives the "from your agent
            // profile" vs "from your agent page" wording in the notification
            // email. Only meaningful for type=agent; ignored otherwise.
            'source' => 'nullable|in:agent_profile,agent_page',
            // Sender's geo at send time (browser ipinfo blob) — stamped onto
            // the chat row for Inquiry Analytics origin tracing. Optional:
            // when absent we fall back to the sender's stored user_info.
            'origin_country' => 'nullable|string|max:8',
            'origin_region' => 'nullable|string|max:96',
            'origin_city' => 'nullable|string|max:96',
        ]);

        // Secretaries are staff oversight, not buyers: they may direct-message
        // agents (type=agent) but must NOT open a listing inquiry (type=listing).
        if ($validated['type'] === 'listing' && $user->isSecretary()) {
            abort(403, 'Secretaries cannot inquire on listings.');
        }

        // Block-check the sender BEFORE we touch chats/conversations. This
        // closes the loophole where a blocked client could open a brand-new
        // inquiry on a different listing owned by the same agent (or any
        // listing if an admin issued a site-wide ban).
        //
        // Both listing AND agent-direct ("Message Me") chats target a
        // specific user (`target_user_id` = the agent). blog / reel chats
        // don't have an agent target so they're not gated here. Surfaced
        // on prod 2026-06-03 — a globally-blocked scammer could still send
        // agent-direct messages because the original gate only fired for
        // `type=listing`.
        if (in_array($validated['type'], ['listing', 'agent'], true)
            && BlockedUser::isBlocking($user->id, (int) $validated['target_user_id'])) {
            abort(403, 'You can no longer contact this agent.');
        }

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
                    $conversation = new Conversation([
                        'chat_id' => $existing->id,
                        // Inquiries from trusted senders (admin, or a team
                        // leader inquiring on a listing owned by an agent
                        // in their team) skip Pending Review entirely —
                        // they already have moderation rights, so a self-
                        // submitted inquiry going through moderation is
                        // pointless and would block the agent from seeing
                        // the message until someone accepts.
                        'status' => ! $isListing || $autoAccept ? 'accepted' : 'pending',
                        'agent_user_id' => $isListing ? $validated['target_user_id'] : null,
                        'reviewed_by' => $autoAccept ? $user->id : null,
                        'reviewed_at' => $autoAccept ? now() : null,
                    ]);

                    // Human-readable audit description for /admin/activity-logs.
                    // Set on the model instance BEFORE save so the
                    // LogsActivity trait picks it up at audit-write time.
                    $listingName = $existing->listing?->name;
                    $conversation->auditSource = $autoAccept ? 'inquiry_auto_accept' : 'inquiry_submit';
                    $conversation->auditDescription = sprintf(
                        '%s re-submitted an inquiry%s%s',
                        $user->name,
                        $listingName ? " on {$listingName}" : '',
                        $autoAccept ? ' — auto-accepted' : '',
                    );

                    $conversation->save();

                    if ($isListing) {
                        $adminUserIds = User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->pluck('id')->toArray();
                        // Every pivot row must declare the SAME set of pivot
                        // columns — otherwise Laravel's batch attach() picks
                        // the column list from the first row and binds extra
                        // values for rows with extra keys → "column count
                        // doesn't match value count". Default `last_notified_at`
                        // to null on every row so the auto-accept branch below
                        // (which sets it for the agent only) stays consistent.
                        $attachments = [
                            $user->id => ['last_read_at' => now(), 'last_notified_at' => null],
                        ];
                        foreach ($adminUserIds as $adminId) {
                            $attachments[$adminId] = ['last_read_at' => null, 'last_notified_at' => null];
                        }

                        $leaderUserId = app(TeamLeadershipService::class)
                            ->findTeamLeaderUserIdFor((int) $validated['target_user_id']);
                        if ($leaderUserId && $leaderUserId !== $user->id && ! isset($attachments[$leaderUserId])) {
                            $attachments[$leaderUserId] = ['last_read_at' => null, 'last_notified_at' => null];
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

                    if (! empty($validated['message'])) {
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
                            chatId: $existing->id,
                            listingId: (int) $validated['type_id'],
                            agentUserId: (int) $validated['target_user_id'],
                            message: $validated['message'] ?? '',
                            sender: $user,
                        );
                    } else {
                        $this->dispatchSubmissionEmail(
                            chatId: $existing->id,
                            listingId: (int) $validated['type_id'],
                            agentUserId: (int) $validated['target_user_id'],
                            message: $validated['message'] ?? '',
                            sender: $user,
                        );
                    }
                } elseif ($validated['type'] === 'agent') {
                    $this->dispatchAgentProfileEmail(
                        chatId: $existing->id,
                        agentUserId: (int) $validated['target_user_id'],
                        message: $validated['message'] ?? '',
                        sender: $user,
                        source: $validated['source'] ?? null,
                    );
                }
            }

            return new ChatResource($existing);
        }

        // Daily new-conversation cap. Only fires when we're about to
        // create a BRAND-NEW chat row (the $existing branch above
        // never reaches here, so re-inquiries on the same listing /
        // agent reuse the existing chat and don't consume a slot).
        //
        // Admins bypass — moderation paths must remain unconstrained.
        // Agents and clients are both subject to the cap. See
        // ChatRateLimitService and the plan
        // "Feat: Daily inquiry cap + block-scope dialog…" for the
        // shape and rationale.
        $isAdmin = $user->role?->name === 'admin';
        if (! $isAdmin) {
            $rateLimit = app(ChatRateLimitService::class);
            if ($rateLimit->exhausted((int) $user->id)) {
                app(AuditSecurityService::class)->recordRateLimitHit(
                    $user,
                    'inquiry_daily_cap',
                    sprintf(
                        '%s hit the daily %d-conversation cap',
                        $user->name,
                        ChatRateLimitService::DAILY_LIMIT,
                    ),
                    [
                        'attempted_type' => $validated['type'],
                        'target_user_id' => (int) $validated['target_user_id'],
                        'attempted_type_id' => (int) $validated['type_id'],
                    ],
                );

                return response()->json([
                    'message' => sprintf(
                        'Daily limit reached. You can open up to %d new conversations per day. Try again tomorrow.',
                        ChatRateLimitService::DAILY_LIMIT,
                    ),
                    'limit' => ChatRateLimitService::DAILY_LIMIT,
                    'remaining' => 0,
                ], 429);
            }
        }

        // Trusted senders skip the Pending Review step entirely. Two senders
        // qualify (see shouldAutoAcceptListingInquiry below):
        //   - admins (full moderation rights)
        //   - team leaders inquiring on a listing owned by an agent in their
        //     team (they're the natural moderator for that team's inquiries,
        //     so requiring themselves to also click Accept is busy-work)
        $autoAccept = $validated['type'] === 'listing'
            && $this->shouldAutoAcceptListingInquiry($user, (int) $validated['target_user_id']);

        // Freeze the sender's geo at send time. Prefer the browser-supplied
        // origin (fresh ipinfo); fall back to their stored user_info (last
        // login's geo) when the payload has none — e.g. ad-blocked ipinfo.
        $originGeo = [
            'origin_country' => $validated['origin_country'] ?? null,
            'origin_region' => $validated['origin_region'] ?? null,
            'origin_city' => $validated['origin_city'] ?? null,
        ];
        if (! array_filter($originGeo)) {
            $ui = UserInfo::where('user_id', $user->id)->first();
            if ($ui) {
                $originGeo = [
                    'origin_country' => $ui->country ?: null,
                    'origin_region' => $ui->state ?: null,
                    'origin_city' => $ui->city ?: null,
                ];
            }
        }

        $chat = DB::transaction(function () use ($validated, $user, $autoAccept, $originGeo) {
            $chat = Chat::create([
                'type' => $validated['type'],
                'type_id' => $validated['type_id'],
                'user_id' => $user->id,
                ...$originGeo,
            ]);

            $isListing = $validated['type'] === 'listing';

            $conversation = new Conversation([
                'chat_id' => $chat->id,
                // Trusted senders (admins + team leaders inquiring within
                // their own team) skip Pending Review — they already have
                // moderation rights, so blocking the agent from seeing the
                // message until someone clicks Accept is pointless.
                'status' => ! $isListing || $autoAccept ? 'accepted' : 'pending',
                'agent_user_id' => $isListing ? $validated['target_user_id'] : null,
                'reviewed_by' => $autoAccept ? $user->id : null,
                'reviewed_at' => $autoAccept ? now() : null,
            ]);

            // Human-readable audit description for /admin/activity-logs.
            // Set on the model instance BEFORE save so the
            // LogsActivity trait picks it up at audit-write time.
            $listingName = $isListing
                ? Listing::where('id', $validated['type_id'])->value('name')
                : null;
            $conversation->auditSource = $autoAccept
                ? 'inquiry_auto_accept'
                : ($isListing ? 'inquiry_submit' : 'agent_dm_open');
            $conversation->auditDescription = $isListing
                ? sprintf(
                    '%s submitted an inquiry%s%s',
                    $user->name,
                    $listingName ? " on {$listingName}" : '',
                    $autoAccept ? ' — auto-accepted' : '',
                )
                : sprintf('%s opened a direct message', $user->name);

            $conversation->save();

            if ($isListing) {
                // Attach client + all admin users + team leader (not the agent yet).
                // Every pivot row must declare the same set of pivot columns —
                // see the comment in the parallel block above (~line 291) for
                // why `last_notified_at` is defaulted to null on every row.
                $adminUserIds = User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->pluck('id')->toArray();

                $attachments = [
                    $user->id => ['last_read_at' => now(), 'last_notified_at' => null],
                ];
                foreach ($adminUserIds as $adminId) {
                    $attachments[$adminId] = ['last_read_at' => null, 'last_notified_at' => null];
                }

                $leaderUserId = app(TeamLeadershipService::class)
                    ->findTeamLeaderUserIdFor((int) $validated['target_user_id']);
                if ($leaderUserId && $leaderUserId !== $user->id && ! isset($attachments[$leaderUserId])) {
                    $attachments[$leaderUserId] = ['last_read_at' => null, 'last_notified_at' => null];
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

            if (! empty($validated['message'])) {
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

        // Increment the daily counter AFTER the chat is committed.
        // Doing it pre-commit would leak a slot if the transaction
        // rolled back; doing it post-commit matches what actually
        // got created. Admins still bypass.
        if (! $isAdmin) {
            app(ChatRateLimitService::class)->recordNewChat((int) $user->id);
        }

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
                    chatId: $chat->id,
                    listingId: (int) $validated['type_id'],
                    agentUserId: (int) $validated['target_user_id'],
                    message: $validated['message'] ?? '',
                    sender: $user,
                );
            } else {
                $this->dispatchSubmissionEmail(
                    chatId: $chat->id,
                    listingId: (int) $validated['type_id'],
                    agentUserId: (int) $validated['target_user_id'],
                    message: $validated['message'] ?? '',
                    sender: $user,
                );
            }

            // Push the moderators (admins + the agent's team leader, already
            // attached above) with a rich "listing_inquiry" review notification
            // that names the property and deep-links to the in-app review
            // screen — emails alone are easy to miss. The first message is
            // created inside the transaction and never flows through
            // MessageController, so we dispatch here. The job only pushes
            // recipients who prefer push; the rest got the email just above.
            $conversation = $chat->activeConversation;
            $firstMessage = $conversation?->latestMessage;
            if ($conversation && $firstMessage) {
                SendInquiryReviewNotification::dispatch(
                    $conversation->id,
                    $firstMessage->id,
                    $user->id,
                );
            }
        } elseif ($validated['type'] === 'agent') {
            // Agent-profile "Message Me" inquiry — no moderation step (the
            // conversation is auto-accepted on create), so the agent gets
            // exactly one email straight to their inbox.
            $this->dispatchAgentProfileEmail(
                chatId: $chat->id,
                agentUserId: (int) $validated['target_user_id'],
                message: $validated['message'] ?? '',
                sender: $user,
                source: $validated['source'] ?? null,
            );
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

        // Sender-side: admins skip Pending Review (full moderation
        // rights). Team leaders inquiring inside their own team
        // skip too — they're the natural moderator for that team's
        // queue, so requiring themselves to also click Accept is
        // busy-work.
        if ($roleName === 'admin') {
            return true;
        }

        if ($roleName === 'agent') {
            $ledMemberUserIds = app(TeamLeadershipService::class)
                ->getLedTeamMemberUserIds($sender->id);
            if (in_array($targetAgentUserId, $ledMemberUserIds, true)) {
                return true;
            }
        }

        // Recipient-side: when the listing's assigned agent IS an
        // admin or team leader, skip Pending Review too. They're
        // trusted moderators and ceremonially clicking Accept on
        // their own listings' inquiries is confusing (the TL would
        // be "accepting their own pending inquiry from a client").
        $target = User::with('role')->find($targetAgentUserId);
        if (! $target) {
            return false;
        }
        if ($target->role?->name === 'admin') {
            return true;
        }
        if (app(TeamLeadershipService::class)->isTeamLeader($targetAgentUserId)) {
            return true;
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

        if (! $listing) {
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
        $slug = Str::slug($listing->name).'-'.$chatId;

        // SMTP failures (disabled mailbox, rate limits, transient
        // network) must NEVER 500 the inquiry-submission flow — the
        // chat row is already committed in the caller's transaction
        // by the time we get here. Email is a side-effect; log and
        // move on rather than throwing past the controller.
        try {
            MessageNotificationMailer::dispatchForSubmission(
                sender: $sender,
                message: $message,
                slug: $slug,
                listing: MessageNotificationMailer::buildListingPayload($listing),
                agentUserId: $agentUserId,
            );
        } catch (Throwable $e) {
            Log::warning('Submission email failed to dispatch', [
                'chat_id' => $chatId,
                'listing_id' => $listingId,
                'agent_user_id' => $agentUserId,
                'error' => $e->getMessage(),
            ]);
            app(AuditMailService::class)->recordFailure(
                $e,
                MessageNotificationMailer::class,
                [],
                'New listing inquiry — admin fan-out',
                [
                    'auditable_type' => Chat::class,
                    'auditable_id' => $chatId,
                ],
            );
        }
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

        if (! $listing) {
            return;
        }

        $agent = User::find($agentUserId);
        if (! $agent) {
            return;
        }

        $sender->loadMissing('agent');

        $slug = Str::slug($listing->name).'-'.$chatId;

        // Same protection as dispatchSubmissionEmail above — SMTP
        // failures can't be allowed to 500 the inquiry-creation
        // path. The auto-accept already happened in store(); a
        // failing notification email is not worth losing the chat.
        try {
            MessageNotificationMailer::dispatchForAcceptance(
                sender: $sender,
                agent: $agent,
                message: $message,
                slug: $slug,
                listing: MessageNotificationMailer::buildListingPayload($listing),
                agentUserId: $agentUserId,
            );
        } catch (Throwable $e) {
            Log::warning('Auto-acceptance email failed to dispatch', [
                'chat_id' => $chatId,
                'listing_id' => $listingId,
                'agent_user_id' => $agentUserId,
                'error' => $e->getMessage(),
            ]);
            app(AuditMailService::class)->recordFailure(
                $e,
                MessageNotificationMailer::class,
                $agent->email ? [$agent->email] : [],
                'Auto-accepted inquiry — agent notification',
                [
                    'auditable_type' => Chat::class,
                    'auditable_id' => $chatId,
                ],
            );
        }
    }

    /**
     * Email the agent when a visitor sends them an inquiry through the
     * "Message Me" form on their public profile (POST /chats with
     * type=agent). No listing context, no moderation fan-out — the
     * conversation is auto-accepted, so the agent is the only recipient.
     *
     * Slug shape mirrors the listing-inquiry helpers so the "Reply To
     * Inquiry" CTA in the email lands on the same /inbox/{slug-id}
     * route the frontend's ListingInquiries component understands.
     */
    private function dispatchAgentProfileEmail(
        int $chatId,
        int $agentUserId,
        string $message,
        User $sender,
        ?string $source = null,
    ): void {
        $agent = User::find($agentUserId);
        if (! $agent) {
            return;
        }

        $sender->loadMissing('agent');

        $agentName = trim((string) ($agent->name ?? '')) ?: 'agent';
        $slug = Str::slug($agentName).'-'.$chatId;

        // Same protection as the listing-inquiry mailers above —
        // a failing SMTP transport can't be allowed to block a
        // visitor sending the very first message through "Message
        // Me" on an agent profile. The chat row exists already;
        // the email is a fan-out, not the operation.
        try {
            MessageNotificationMailer::dispatchForAgentProfile(
                sender: $sender,
                agent: $agent,
                message: $message,
                slug: $slug,
                agentUserId: $agentUserId,
                source: $source,
            );
        } catch (Throwable $e) {
            Log::warning('Agent-profile inquiry email failed to dispatch', [
                'chat_id' => $chatId,
                'agent_user_id' => $agentUserId,
                'error' => $e->getMessage(),
            ]);
            app(AuditMailService::class)->recordFailure(
                $e,
                MessageNotificationMailer::class,
                $agent->email ? [$agent->email] : [],
                'Agent profile DM — first message notification',
                [
                    'auditable_type' => Chat::class,
                    'auditable_id' => $chatId,
                ],
            );
        }
    }

    public function show(Chat $chat)
    {
        $this->authorize('view', $chat);

        $chat->load([
            'user',
            'listing.property',
            // Listing owner → surfaced as the second participant in the
            // app's conversation-info sheet. Load role for the badge and the
            // user's own agent row so UserResource resolves mobile_no without
            // an N+1 (mirrors the 'user.agent:id,user_id,mobile_no' load in index()).
            'listing.agent.user.role',
            'listing.agent.user.agent:id,user_id,mobile_no,whats_app_no',
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
            'removed_at' => null,
        ]);
    }

    /**
     * Per-participant "Delete Permanently". Final state — once purged
     * the chat is hidden from every view (inbox / archived / trash) for
     * this viewer. The row stays in the database so an admin tool can
     * recover it later if needed (e.g. compliance request). Other
     * participants' pivot rows are untouched; the chat stays in their
     * inboxes / archive / trash as before.
     *
     * Only exposed from the Trash view in the UI, behind a confirmation
     * dialog. archived_at + removed_at are left as-is so the audit
     * trail of how the chat got purged is preserved on the row.
     */
    public function purge(Chat $chat)
    {
        return $this->mutateViewerPivot($chat, [
            'purged_at' => now(),
        ]);
    }

    /**
     * Admin-only hard delete of a user's conversations, platform-wide. This
     * covers both chats they OWN (their inquiries + threads they started,
     * `chat.user_id`) AND agent-to-agent direct threads where they are the
     * OTHER party — i.e. someone else (often an admin) started the DM *to*
     * them, so the owner column points at the initiator, not them. Without
     * the second case an admin can't purge a thread they themselves opened
     * with an agent (the symptom: "No conversations found for this user").
     *
     * The participant match is scoped to `type = 'agent'` on purpose, so it
     * never reaches into listing inquiries the user merely handles as the
     * assigned agent — those rows belong to the inquiring client and must
     * not be collateral. Unlike per-participant purge, this removes the rows
     * from the database for ALL participants — there is no recovery. Used to
     * clear out a confirmed spam/abuse account's conversation history.
     *
     * Implementation: `forceDelete()` issues a real DELETE on chats, so the
     * existing `cascadeOnDelete` foreign keys wipe the dependent
     * conversations, messages, conversation_users pivots and message
     * reactions in one shot. `withTrashed()` is used so soft-deleted chats
     * are purged too. The confirmation (typing the user's name) is enforced
     * client-side; the server only re-checks admin authority.
     */
    public function purgeByUser(User $user)
    {
        $actor = Auth::user();
        if ($actor?->role?->name !== 'admin') {
            return response()->json(
                ['message' => 'Only admins can permanently delete a user\'s conversations.'],
                403,
            );
        }

        // Chats the user owns outright — their inquiries and any thread they
        // started (chat.user_id). Original behaviour.
        $ownedIds = Chat::withTrashed()
            ->where('user_id', $user->id)
            ->pluck('id');

        // Agent-to-agent direct threads where the user is the OTHER party:
        // a conversation participant or the conversation's agent, but NOT the
        // chat owner (someone else opened the DM to them). Scoped to
        // type='agent' so listing inquiries they handle as the assigned agent
        // are never swept in. withTrashed() on the conversation subquery so a
        // soft-deleted thread is still matched, mirroring the parent query.
        $dmIds = Chat::withTrashed()
            ->where('type', 'agent')
            ->whereHas('conversations', function ($cq) use ($user) {
                $cq->withTrashed()->where(function ($q) use ($user) {
                    $q->where('agent_user_id', $user->id)
                        ->orWhereHas('users', fn ($uq) => $uq->where('users.id', $user->id));
                });
            })
            ->pluck('id');

        $chatIds = $ownedIds->merge($dmIds)->unique()->values();
        $count = $chatIds->count();

        if ($count === 0) {
            return response()->json([
                'message' => 'No conversations found for this user.',
                'deleted' => 0,
            ]);
        }

        DB::transaction(function () use ($chatIds) {
            Chat::withTrashed()->whereIn('id', $chatIds)->forceDelete();
        });

        Log::warning('Admin permanently deleted a user\'s conversations.', [
            'actor_id' => $actor->id,
            'target_user' => $user->id,
            'target_name' => $user->name,
            'chats_deleted' => $count,
        ]);

        return response()->json([
            'message' => "Permanently deleted {$count} conversation(s).",
            'deleted' => $count,
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
        if (! $activeConv) {
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
        if (! $activeConv->users()->where('users.id', $userId)->exists()) {
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
            'date_to' => 'sometimes|nullable|date|after_or_equal:date_from',
        ]);
        $dateFrom = ! empty($validated['date_from'])
            ? Carbon::parse($validated['date_from'])->startOfDay()
            : null;
        $dateTo = ! empty($validated['date_to'])
            ? Carbon::parse($validated['date_to'])->endOfDay()
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
            'all' => (int) array_sum($byType),
            'listing' => (int) ($byType['listing'] ?? 0),
            'agent' => (int) ($byType['agent'] ?? 0),
            'blog' => (int) ($byType['blog'] ?? 0),
            'reel' => (int) ($byType['reel'] ?? 0),
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
        // Only accepted conversations count toward chat-stats KPIs.
        // Pending = admin still has to moderate the inbound side, so
        // it's not yet "agent workload". Rejected never made it
        // past the gate. Closed are excluded too — the user wants
        // the page to reflect "real, currently-actionable" accepted
        // conversations only.
        $listingAgg = DB::table('conversations as c')
            ->joinSub($latestConvSub, 'lc', 'lc.id', '=', 'c.id')
            ->join('chats', 'chats.id', '=', 'c.chat_id')
            ->whereNull('chats.deleted_at')
            ->where('chats.type', 'listing')
            ->where('c.status', 'accepted')
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
        $notReplied = max(0, $listingTotal - $agentReplied);

        $listingInquiries = [
            'total' => $listingTotal,
            'agent_replied' => $agentReplied,
            'not_replied' => $notReplied,
            'by_status' => [
                'pending' => (int) ($listingAgg->s_pending ?? 0),
                'accepted' => (int) ($listingAgg->s_accepted ?? 0),
                'rejected' => (int) ($listingAgg->s_rejected ?? 0),
                'closed' => (int) ($listingAgg->s_closed ?? 0),
            ],
            'reply_rate' => $listingTotal > 0
                ? round(($agentReplied / $listingTotal) * 100, 1)
                : 0.0,
        ];

        // 3) Per-agent rollup (listing chats, latest conversation, agent assigned)
        // Same accepted-only filter as the listing aggregate above
        // so the per-agent table on /admin/chat-statistics matches
        // the headline KPI cards.
        $perAgentRows = DB::table('conversations as c')
            ->joinSub($latestConvSub, 'lc', 'lc.id', '=', 'c.id')
            ->join('chats', 'chats.id', '=', 'c.chat_id')
            ->whereNull('chats.deleted_at')
            ->where('chats.type', 'listing')
            ->where('c.status', 'accepted')
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
        if (! empty($agentUserIds)) {
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
                'name' => $info?->name ?? ('Agent #'.$row->agent_user_id),
                'avatar' => $info?->avatar,
                'team_id' => isset($info->team_id) ? (int) $info->team_id : null,
                'team_name' => $info?->team_name,
                'total' => $total,
                'agent_replied' => $replied,
                'not_replied' => max(0, $total - $replied),
                'by_status' => [
                    'pending' => (int) $row->s_pending,
                    'accepted' => (int) $row->s_accepted,
                    'rejected' => (int) $row->s_rejected,
                    'closed' => (int) $row->s_closed,
                ],
                'reply_rate' => $total > 0
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
            if (! $tid) {
                continue;
            }
            if (! isset($teamRollup[$tid])) {
                $teamRollup[$tid] = [
                    'total' => 0,
                    'agent_replied' => 0,
                    'by_status' => [
                        'pending' => 0,
                        'accepted' => 0,
                        'rejected' => 0,
                        'closed' => 0,
                    ],
                ];
            }
            $teamRollup[$tid]['total'] += $agent['total'];
            $teamRollup[$tid]['agent_replied'] += $agent['agent_replied'];
            foreach ($agent['by_status'] as $s => $n) {
                $teamRollup[$tid]['by_status'][$s] += $n;
            }
        }

        $perTeam = $teamMeta->map(function ($t) use ($teamRollup) {
            $tid = (int) $t->team_id;
            $r = $teamRollup[$tid] ?? [
                'total' => 0,
                'agent_replied' => 0,
                'by_status' => [
                    'pending' => 0,
                    'accepted' => 0,
                    'rejected' => 0,
                    'closed' => 0,
                ],
            ];
            $total = (int) $r['total'];
            $replied = (int) $r['agent_replied'];

            return [
                'team_id' => $tid,
                'team_name' => (string) $t->team_name,
                'leader_name' => $t->leader_name,
                'agent_count' => (int) $t->agent_count,
                'total' => $total,
                'agent_replied' => $replied,
                'not_replied' => max(0, $total - $replied),
                'by_status' => $r['by_status'],
                'reply_rate' => $total > 0
                    ? round(($replied / $total) * 100, 1)
                    : 0.0,
            ];
        })->values()->toArray();

        return response()->json([
            'totals' => $totals,
            'listing_inquiries' => $listingInquiries,
            'per_team' => $perTeam,
            'per_agent' => $perAgent,
        ]);
    }
}
