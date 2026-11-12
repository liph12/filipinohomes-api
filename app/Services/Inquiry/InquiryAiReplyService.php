<?php

namespace App\Services\Inquiry;

use App\Mail\MessageNotificationMailer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use App\Services\ExpoPushService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Inquiry Filipinohomes Assistant — covers for agents who aren't online when a listing
 * inquiry comes in (`inquiries:ai-reply`, every 5 minutes).
 *
 *  1. ACK, right after the inquiry is approved: thanks the client, says the
 *     agent will get in touch, gives the property code and the agent's numbers,
 *     and asks the client to confirm their contact details.
 *  2. FOLLOW-UP, once, if the client answers before the agent does.
 *  All messages are fixed text (no OpenAI), so the wording, numbers and codes
 *  are always exact and nothing is spent per inquiry.
 *  3. ESCALATION, when the agent still hasn't replied after N minutes (default
 *     3h): an apology plus the admin numbers — a fixed template, never AI, so
 *     the numbers are always exact. Only sent during office hours, so a client
 *     isn't told to call a landline nobody answers at 2 AM; the agent gets a
 *     push at the same time.
 *
 * Guarantees:
 *  - "Agent replied" = any message in the thread not written by the client and
 *    not automated (agent, team leader or admin). Once that exists, nothing is
 *    sent.
 *  - At most one message per stage per conversation: the inquiry_ai_replies row
 *    is claimed (unique key) BEFORE posting, so EC2 and Laravel Cloud running
 *    the same scheduler can't double-send.
 *  - Only inquiries approved AFTER the assistant was switched on are touched
 *    (enabled_since), so enabling it never messages an old backlog.
 *  - Messages are posted by a dedicated "Filipinohomes Assistant" user, flagged
 *    messages.automated, and never attached to the thread's participants — the
 *    agent's response-time metrics only count the agent's own messages.
 */
class InquiryAiReplyService
{
    public const SETTINGS_KEY = 'inquiry_ai_reply';

    public const LAST_RUN_KEY = 'inquiry_ai_reply_last_run';

    public const TIMEZONE = 'Asia/Manila';

    public const STAGE_ACK = 'ack';

    public const STAGE_ESCALATION = 'escalation';

    /** One "thank you" when the client answers the first reply before the agent has. */
    public const STAGE_FOLLOWUP = 'followup';

    /** An ack is only meaningful right after approval — never weeks later. */
    private const ACK_MAX_AGE_MINUTES = 120;

    public const DEFAULTS = [
        'enabled' => false,
        'enabled_since' => null,
        'ack_enabled' => true,
        'ack_delay_minutes' => 0,
        'followup_enabled' => true,
        'escalation_enabled' => true,
        'escalation_after_minutes' => 180,
        'office_days' => [1, 2, 3, 4, 5, 6], // Mon–Sat
        'office_start' => '08:00',
        'office_end' => '17:00',
        'max_age_hours' => 72,
        'daily_cap' => 200,
        'contact_mobile' => '(+63) 977-815-0888',
        // Office WhatsApp — shown with the WhatsApp logo when set.
        'contact_whatsapp' => '',
        // Listing owner never logged in, or no login AND no activity for this
        // many days: the first reply gives the admin number right away
        // instead of making the client wait for an agent who won't come.
        'inactive_agent_enabled' => true,
        'inactive_agent_days' => 15,
    ];

    /** inquiry_ai_replies.source for an admin-number reply sent because the agent is inactive. */
    public const SOURCE_INACTIVE_AGENT = 'inactive_agent';

    // The chat already labels these messages AUTOMATED, so no "(automated message)" here.
    private const SIGNATURE = '— Filipinohomes Assistant';

    /* ------------------------------------------------------------------ */
    /* Settings */
    /* ------------------------------------------------------------------ */

    public function settings(): array
    {
        $raw = Setting::get(self::SETTINGS_KEY);
        $stored = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $m = [...self::DEFAULTS, ...(is_array($stored) ? $stored : [])];

        return [
            'enabled' => (bool) $m['enabled'],
            'enabled_since' => is_string($m['enabled_since']) ? $m['enabled_since'] : null,
            'ack_enabled' => (bool) $m['ack_enabled'],
            'ack_delay_minutes' => max(0, min(240, (int) $m['ack_delay_minutes'])),
            'followup_enabled' => (bool) $m['followup_enabled'],
            'escalation_enabled' => (bool) $m['escalation_enabled'],
            'escalation_after_minutes' => max(60, min(2880, (int) $m['escalation_after_minutes'])),
            'office_days' => $this->sanitizeDays((array) $m['office_days']),
            'office_start' => $this->validTime($m['office_start'], self::DEFAULTS['office_start']),
            'office_end' => $this->validTime($m['office_end'], self::DEFAULTS['office_end']),
            'max_age_hours' => max(1, min(24 * 14, (int) $m['max_age_hours'])),
            'daily_cap' => max(1, min(2000, (int) $m['daily_cap'])),
            'contact_mobile' => trim((string) $m['contact_mobile']),
            'contact_whatsapp' => trim((string) $m['contact_whatsapp']),
            'inactive_agent_enabled' => (bool) $m['inactive_agent_enabled'],
            'inactive_agent_days' => max(1, min(365, (int) $m['inactive_agent_days'])),
        ];
    }

    public function saveSettings(array $input): array
    {
        $current = $this->settings();
        $enabled = (bool) ($input['enabled'] ?? false);

        // Switching ON (re)starts the clock: only inquiries approved from now
        // on are handled, so enabling never reaches back into a backlog.
        $enabledSince = $enabled
            ? (($current['enabled'] && $current['enabled_since']) ? $current['enabled_since'] : Carbon::now()->toIso8601String())
            : null;

        $merged = [...$current, ...$input, 'enabled' => $enabled, 'enabled_since' => $enabledSince];
        unset($merged['summary']);
        Setting::set(self::SETTINGS_KEY, json_encode($merged));

        return $this->settings();
    }

    public function summary(array $s): string
    {
        if (! $s['enabled']) {
            return 'Off — clients wait for the agent';
        }
        $parts = [];
        if ($s['ack_enabled']) {
            $parts[] = $s['ack_delay_minutes'] > 0 ? "Replies {$s['ack_delay_minutes']} min after approval" : 'Replies right after approval';
        }
        if ($s['escalation_enabled']) {
            $parts[] = 'admin number after '.$this->durationLabel($s['escalation_after_minutes']);
        }

        return $parts ? ucfirst(implode(' · ', $parts)) : 'On — both messages switched off';
    }

    /* ------------------------------------------------------------------ */
    /* Office hours */
    /* ------------------------------------------------------------------ */

    public function isOfficeOpen(array $s, CarbonInterface $now): bool
    {
        $local = $now->copy()->setTimezone(self::TIMEZONE);
        if (! in_array($local->dayOfWeek, $s['office_days'], true)) {
            return false;
        }
        $start = $this->minutes($s['office_start']);
        $end = $this->minutes($s['office_end']);
        $at = $local->hour * 60 + $local->minute;

        return $start <= $end ? ($at >= $start && $at < $end) : ($at >= $start || $at < $end);
    }

    public function officeHoursLabel(array $s): string
    {
        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $days = $s['office_days'];
        $isRange = count($days) > 1 && $days === range($days[0], end($days));
        $daysLabel = $isRange
            ? $names[$days[0]].'–'.$names[end($days)]
            : implode(', ', array_map(fn ($d) => $names[$d], $days));
        $fmt = fn (string $t) => Carbon::createFromFormat('H:i', $t)->format('g:i A');

        return "{$daysLabel}, {$fmt($s['office_start'])}–{$fmt($s['office_end'])}";
    }

    /* ------------------------------------------------------------------ */
    /* Eligibility */
    /* ------------------------------------------------------------------ */

    /** Accepted listing inquiries nobody but the client (or the assistant) has written in. */
    private function awaitingAgentQuery(array $s): Builder
    {
        $q = Conversation::query()
            ->where('status', 'accepted')
            ->whereNotNull('agent_user_id')
            ->whereNotNull('reviewed_at')
            ->whereHas('chat', fn ($c) => $c->where('type', 'listing')->whereColumn('chats.user_id', '!=', 'conversations.agent_user_id'))
            ->whereNotExists(fn ($m) => $m->select(DB::raw(1))
                ->from('messages')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->where('messages.automated', false)
                ->whereRaw('messages.user_id <> (SELECT chats.user_id FROM chats WHERE chats.id = conversations.chat_id)'))
            ->orderBy('conversations.id');

        if ($s['enabled_since']) {
            $q->where('reviewed_at', '>=', Carbon::parse($s['enabled_since']));
        }

        return $q;
    }

    private function withoutStage(Builder $q, string $stage): Builder
    {
        return $q->whereNotExists(fn ($r) => $r->select(DB::raw(1))
            ->from('inquiry_ai_replies')
            ->whereColumn('inquiry_ai_replies.conversation_id', 'conversations.id')
            ->where('inquiry_ai_replies.stage', $stage));
    }

    public function ackQuery(array $s): Builder
    {
        $now = Carbon::now();

        return $this->withoutStage($this->awaitingAgentQuery($s), self::STAGE_ACK)
            ->whereBetween('reviewed_at', [
                $now->copy()->subMinutes($s['ack_delay_minutes'] + self::ACK_MAX_AGE_MINUTES),
                $now->copy()->subMinutes($s['ack_delay_minutes']),
            ]);
    }

    public function escalationQuery(array $s): Builder
    {
        $now = Carbon::now();

        return $this->withoutStage($this->awaitingAgentQuery($s), self::STAGE_ESCALATION)
            ->whereBetween('reviewed_at', [
                $now->copy()->subHours($s['max_age_hours']),
                $now->copy()->subMinutes($s['escalation_after_minutes']),
            ]);
    }

    public function sentToday(): int
    {
        return (int) DB::table('inquiry_ai_replies')
            ->where('status', 'sent')
            ->where('created_at', '>=', Carbon::now(self::TIMEZONE)->startOfDay()->utc())
            ->count();
    }

    /* ------------------------------------------------------------------ */
    /* Run */
    /* ------------------------------------------------------------------ */

    /**
     * One scheduler pass. Returns counts; never throws for a single bad
     * conversation (logged and recorded as failed instead).
     *
     * @return array{acks:int, escalations:int, failed:int, skipped:int, reason:?string, would:array<int, array{conversation_id:int, stage:string}>}
     */
    public function run(bool $dryRun = false, int $limit = 25): array
    {
        $s = $this->settings();
        $out = ['acks' => 0, 'escalations' => 0, 'failed' => 0, 'skipped' => 0, 'reason' => null, 'would' => []];

        if (! $s['enabled']) {
            $out['reason'] = 'disabled';

            return $out;
        }

        $assistant = $this->assistantUser();
        if (! $assistant) {
            $out['reason'] = 'no assistant user (missing "client" role)';

            return $out;
        }

        $budget = min($limit, max(0, $s['daily_cap'] - $this->sentToday()));
        if ($budget === 0) {
            $out['reason'] = 'daily cap reached';

            return $out;
        }

        $jobs = collect();
        if ($s['ack_enabled']) {
            $jobs = $jobs->merge($this->ackQuery($s)->limit($budget)->get()->map(fn ($c) => [$c, self::STAGE_ACK]));
        }
        if ($s['escalation_enabled'] && $this->isOfficeOpen($s, Carbon::now())) {
            $jobs = $jobs->merge($this->escalationQuery($s)->limit($budget)->get()->map(fn ($c) => [$c, self::STAGE_ESCALATION]));
        }
        $jobs = $jobs->take($budget);

        foreach ($jobs as [$conversation, $stage]) {
            if ($dryRun) {
                $out['would'][] = [
                    'conversation_id' => $conversation->id,
                    'stage' => $stage === self::STAGE_ACK && $this->adminNumberNow($conversation, $s) ? 'escalation (agent inactive)' : $stage,
                ];

                continue;
            }
            [$result, $stage] = $stage === self::STAGE_ACK
                ? $this->sendFirstReply($conversation, $s, $assistant)
                : [$this->send($conversation, $stage, $s, $assistant), $stage];
            match ($result) {
                'sent' => $stage === self::STAGE_ACK ? $out['acks']++ : $out['escalations']++,
                'failed' => $out['failed']++,
                default => $out['skipped']++,
            };
        }

        if (! $dryRun) {
            Setting::set(self::LAST_RUN_KEY, json_encode([
                'ran_at' => Carbon::now(self::TIMEZONE)->toIso8601String(),
                'acks' => $out['acks'],
                'escalations' => $out['escalations'],
                'failed' => $out['failed'],
            ]));
        }

        return $out;
    }

    /**
     * Called the moment an inquiry is approved (moderator Accept, the
     * auto-approver, or a trusted sender's instant approval): sends the first
     * reply right away instead of waiting for the next 5-minute pass. Runs
     * AFTER the response is sent, so the moderator's click isn't slowed by the
     * email to the client, and after the approval's transaction has committed. The
     * scheduled pass stays as the safety net; the claim row stops a double send.
     */
    public function replySoon(int $conversationId): void
    {
        $s = $this->settings();
        if (! $s['enabled'] || ! $s['ack_enabled'] || $s['ack_delay_minutes'] > 0) {
            return;
        }

        dispatch(function () use ($conversationId) {
            app(self::class)->ackNow($conversationId);
        })->afterResponse();
    }

    /** @return 'sent'|'skipped'|'failed' */
    public function ackNow(int $conversationId): string
    {
        $s = $this->settings();
        if (! $s['enabled'] || ! $s['ack_enabled'] || $this->sentToday() >= $s['daily_cap']) {
            return 'skipped';
        }
        $conversation = $this->ackQuery($s)->whereKey($conversationId)->first();
        $assistant = $conversation ? $this->assistantUser() : null;
        if (! $conversation || ! $assistant) {
            return 'skipped';
        }

        try {
            return $this->sendFirstReply($conversation, $s, $assistant)[0];
        } catch (Throwable $e) {
            // Never let the assistant break an approval.
            Log::warning('Inquiry AI reply (on approval) failed', ['conversation_id' => $conversationId, 'error' => $e->getMessage()]);

            return 'failed';
        }
    }

    /**
     * The first reply. Normally the ack; but when the listing owner is
     * inactive (see agentInactive) the client gets the admin number right
     * away — recorded as the escalation, so the 3-hour message isn't sent
     * again and the inquiry shows under "Admin number given".
     *
     * @return array{0:'sent'|'skipped'|'failed', 1:string} result, stage actually used
     */
    private function sendFirstReply(Conversation $conversation, array $s, User $assistant): array
    {
        if (! $this->adminNumberNow($conversation, $s)) {
            return [$this->send($conversation, self::STAGE_ACK, $s, $assistant), self::STAGE_ACK];
        }

        // Close the ack stage first (also the lock against a second server).
        $claimed = DB::table('inquiry_ai_replies')->insertOrIgnore([
            'conversation_id' => $conversation->id,
            'stage' => self::STAGE_ACK,
            'status' => 'skipped',
            'source' => self::SOURCE_INACTIVE_AGENT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (! $claimed) {
            return ['skipped', self::STAGE_ACK];
        }

        return [$this->send($conversation, self::STAGE_ESCALATION, $s, $assistant, inactiveAgent: true), self::STAGE_ESCALATION];
    }

    /** Give the admin number in the first reply? Needs the option on, the admin-number message on, and a number to give. */
    public function adminNumberNow(Conversation $conversation, array $s): bool
    {
        return $s['inactive_agent_enabled']
            && $s['escalation_enabled']
            && ($s['contact_mobile'] !== '' || $s['contact_whatsapp'] !== '')
            && $this->agentInactive($conversation, $s);
    }

    /**
     * The listing owner never logged in, or has neither logged in nor been
     * active in the last N days. Activity (users.last_online_at) counts too:
     * app logins never expire, so an agent who logged in months ago may still
     * use the app every day — that agent is NOT inactive.
     */
    public function agentInactive(Conversation $conversation, array $s): bool
    {
        $userId = $conversation->agent_user_id;
        if (! $userId) {
            return false;
        }
        $cutoff = Carbon::now()->subDays($s['inactive_agent_days']);
        $lastLogin = DB::table('login_logs')->where('user_id', $userId)->max('logged_in_at');
        $lastActive = DB::table('users')->where('id', $userId)->value('last_online_at');

        $recent = fn ($at) => $at && Carbon::parse($at)->gte($cutoff);

        return ! $recent($lastLogin) && ! $recent($lastActive);
    }

    public function lastRun(): ?array
    {
        $raw = Setting::get(self::LAST_RUN_KEY);
        $v = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($v) ? $v : null;
    }

    /** @return 'sent'|'skipped'|'failed' */
    public function send(Conversation $conversation, string $stage, array $s, User $assistant, bool $inactiveAgent = false): string
    {
        // Claim first — the unique (conversation_id, stage) key makes this the
        // lock across overlapping runs and across servers.
        $claimed = DB::table('inquiry_ai_replies')->insertOrIgnore([
            'conversation_id' => $conversation->id,
            'stage' => $stage,
            'status' => 'claimed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (! $claimed) {
            return 'skipped';
        }
        $row = fn () => DB::table('inquiry_ai_replies')->where('conversation_id', $conversation->id)->where('stage', $stage);

        try {
            $conversation->loadMissing(['chat.user', 'chat.listing', 'agentUser']);

            // The agent may have replied between the query and the claim.
            if ($this->agentReplied($conversation) || in_array($conversation->fresh()?->status, ['rejected', 'closed'], true)) {
                $row()->update(['status' => 'skipped', 'updated_at' => now()]);

                return 'skipped';
            }

            [$body, $source, $tokens] = match ($stage) {
                self::STAGE_ACK => $this->composeAck($conversation, $s),
                self::STAGE_FOLLOWUP => [$this->followupText($conversation, $s), 'template', null],
                default => $inactiveAgent
                    ? [$this->inactiveAgentText($conversation, $s), self::SOURCE_INACTIVE_AGENT, null]
                    : [$this->escalationText($conversation, $s), 'template', null],
            };

            $message = DB::transaction(function () use ($conversation, $assistant, $body) {
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $assistant->id,
                    'body' => $body,
                    'type' => 'text',
                    'automated' => true,
                ]);
                $conversation->chat?->touch();
                // Same as a normal send: a new message brings an archived thread
                // back to everyone's Inbox (trashed/purged rows untouched).
                DB::table('conversation_users')
                    ->where('conversation_id', $conversation->id)
                    ->whereNotNull('archived_at')
                    ->whereNull('removed_at')
                    ->whereNull('purged_at')
                    ->update(['archived_at' => null]);

                return $message;
            });

            $row()->update([
                'status' => 'sent',
                'message_id' => $message->id,
                'source' => $source,
                'tokens' => $tokens,
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            $row()->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 1000), 'updated_at' => now()]);
            Log::warning('Inquiry AI reply failed', ['conversation_id' => $conversation->id, 'stage' => $stage, 'error' => $e->getMessage()]);

            return 'failed';
        }

        $this->notify($conversation, $message, $stage, $assistant, $inactiveAgent);

        return 'sent';
    }

    private function agentReplied(Conversation $conversation): bool
    {
        return Message::where('conversation_id', $conversation->id)
            ->where('automated', false)
            ->where('user_id', '!=', $conversation->chat->user_id)
            ->exists();
    }

    /**
     * Client: email (they're often not on the site any more). Agent: push +
     * in-app notification on the escalation only — on the ack they were just
     * notified of the approval itself. Failures never undo the posted message.
     */
    private function notify(Conversation $conversation, Message $message, string $stage, User $assistant, bool $inactiveAgent = false): void
    {
        $client = $conversation->chat?->user;
        $listing = $conversation->chat?->listing;
        $slug = $listing ? Str::slug($listing->name).'-'.$conversation->chat_id : 'chat-'.$conversation->chat_id;

        // The follow-up answers a message the client JUST sent from the chat —
        // they're on the page, so no email for it.
        if ($client?->email && $stage !== self::STAGE_FOLLOWUP) {
            try {
                $listing?->load(['agent', 'category', 'property.barangay.city.province', 'property.propertyAttribute.subtype.type']);
                MessageNotificationMailer::dispatchForInquiry(
                    $assistant,
                    $client,
                    $message->body,
                    $slug,
                    $client->role?->name ?? 'client',
                    MessageNotificationMailer::buildListingPayload($listing),
                    $conversation->agent_user_id,
                );
            } catch (Throwable $e) {
                Log::warning('Inquiry AI reply: client email failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
            }
        }

        if ($stage === self::STAGE_ESCALATION && $conversation->agentUser) {
            try {
                $clientName = $client?->name ?: 'A client';
                $about = $listing ? " about {$listing->name}" : '';
                app(ExpoPushService::class)->notify(
                    $conversation->agentUser,
                    'inquiry',
                    'Unanswered inquiry',
                    $inactiveAgent
                        ? "{$clientName} sent an inquiry{$about} and was given the admin number because you haven't been active recently. Please reply."
                        : "{$clientName} has waited ".$this->durationLabel((int) $conversation->reviewed_at?->diffInMinutes(now()))
                            .' and was given the admin number. Please reply'.$about.'.',
                    ['type' => 'inquiry', 'id' => $conversation->chat_id, 'conversation_id' => $conversation->id],
                );
            } catch (Throwable $e) {
                Log::warning('Inquiry AI reply: agent push failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Message text */
    /* ------------------------------------------------------------------ */

    /** @return array{0:string, 1:string, 2:?int} body, source, tokens */
    public function composeAck(Conversation $conversation, array $s): array
    {
        $facts = $this->facts($conversation);

        return [$this->sign($this->templateAck($facts)."\n\n".$this->closing($facts)), 'template', null];
    }

    /**
     * The client answered the first reply (e.g. confirmed their details) and
     * the agent still hasn't written: thank them once and say what happens
     * next. Fixed text, never AI.
     */
    public function followupText(Conversation $conversation, array $s): string
    {
        $f = $this->facts($conversation);
        $text = "Thank you for your response, {$f['client']}! {$f['agent']} will get back to you as soon as possible.";
        if ($s['escalation_enabled']) {
            $text .= "\nIf you haven't heard from {$f['agent']} within ".$this->durationLabel($s['escalation_after_minutes'])
                .', we will forward your inquiry to our office team so you can still be assisted.';
        }

        return $this->sign($text);
    }

    /**
     * Called when a message is posted (MessageController::store). If it's the
     * client answering the assistant's first reply and the agent still hasn't
     * written, the follow-up goes out right after the response — once per
     * inquiry, and never after the admin-number message.
     */
    public function clientReplied(Message $message, Conversation $conversation): void
    {
        if ($message->automated) {
            return;
        }
        $s = $this->settings();
        if (! $s['enabled'] || ! $s['followup_enabled']) {
            return;
        }
        $conversation->loadMissing('chat');
        if ($conversation->chat?->type !== 'listing' || (int) $message->user_id !== (int) $conversation->chat->user_id) {
            return;
        }

        $conversationId = (int) $conversation->id;
        dispatch(function () use ($conversationId) {
            app(self::class)->followupNow($conversationId);
        })->afterResponse();
    }

    /** @return 'sent'|'skipped'|'failed' */
    public function followupNow(int $conversationId): string
    {
        $s = $this->settings();
        if (! $s['enabled'] || ! $s['followup_enabled'] || $this->sentToday() >= $s['daily_cap']) {
            return 'skipped';
        }
        $stages = DB::table('inquiry_ai_replies')->where('conversation_id', $conversationId)->pluck('status', 'stage');
        // Only as an answer to our first reply, and never once the office
        // number has already been given.
        if (($stages[self::STAGE_ACK] ?? null) !== 'sent' || $stages->has(self::STAGE_ESCALATION) || $stages->has(self::STAGE_FOLLOWUP)) {
            return 'skipped';
        }
        $conversation = $this->awaitingAgentQuery($s)->whereKey($conversationId)->first();
        $assistant = $conversation ? $this->assistantUser() : null;
        if (! $conversation || ! $assistant) {
            return 'skipped';
        }

        try {
            return $this->send($conversation, self::STAGE_FOLLOWUP, $s, $assistant);
        } catch (Throwable $e) {
            Log::warning('Inquiry AI follow-up failed', ['conversation_id' => $conversationId, 'error' => $e->getMessage()]);

            return 'failed';
        }
    }

    public function escalationText(Conversation $conversation, array $s): string
    {
        $f = $this->facts($conversation);
        $lines = [
            // The property on its own lines so it's easy to read out on a call.
            $f['listing']
                ? "Hi {$f['client']}, we're sorry for the wait. {$f['agent']} hasn't been able to reply to your inquiry about\n{$f['listing']}"
                    .($f['code'] ? "\nProperty code: {$f['code']}" : '')
                    .($f['location'] ? "\nAddress: {$f['location']}" : '')
                : "Hi {$f['client']}, we're sorry for the wait. {$f['agent']} hasn't been able to reply to your inquiry yet.",
            '',
            'For faster assistance, you can contact our office directly:',
            ...$this->officeContactLines($s),
            '',
        ];
        // The client may be ON the call and unable to look at this message, so
        // nothing to read out: staff find the inquiry by the client's name
        // (the inquiries inbox search covers client and property names).
        $lines[] = "When you call, just give your name, some property details and our team will look up your inquiry. We'll also make sure an agent gets back to you as soon as possible.";

        return $this->sign(implode("\n", $lines));
    }

    /**
     * First reply when the listing owner is inactive: thanks the client and
     * gives the admin number straight away. Doesn't say the agent is inactive
     * (not the client's business) — just routes them to the office.
     */
    public function inactiveAgentText(Conversation $conversation, array $s): string
    {
        $f = $this->facts($conversation);
        $lines = [
            $f['listing']
                ? "Hi {$f['client']}! Thank you for your inquiry about\n{$f['listing']}"
                    .($f['code'] ? "\nProperty code: {$f['code']}" : '')
                    .($f['location'] ? "\nAddress: {$f['location']}" : '')
                : "Hi {$f['client']}! Thank you for your inquiry.",
            '',
            'To make sure you are assisted right away, please contact our office directly:',
            ...$this->officeContactLines($s),
            '',
            'When you call, just give your name, some property details and our team will look up your inquiry and assist you.',
            '',
            $this->contactBlock($f),
        ];

        return $this->sign(implode("\n", $lines));
    }

    /** "📱 mobile" / "💬 WhatsApp" (the chat and email show the WhatsApp logo) + office hours. */
    private function officeContactLines(array $s): array
    {
        $lines = [];
        if ($s['contact_mobile'] !== '') {
            $lines[] = "📱 {$s['contact_mobile']}";
        }
        if ($s['contact_whatsapp'] !== '') {
            $lines[] = "💬 {$s['contact_whatsapp']}";
        }
        $lines[] = 'Office hours: '.$this->officeHoursLabel($s).'.';

        return $lines;
    }

    /** @return array{client:string, agent:string, listing:?string, code:?string, message:string, asked:bool, email:?string, mobile:?string, agent_mobile:?string, agent_whatsapp:?string} */
    private function facts(Conversation $conversation): array
    {
        $conversation->loadMissing(['chat.user', 'chat.listing', 'agentUser.agent']);
        $clientMessage = (string) Message::where('conversation_id', $conversation->id)
            ->where('user_id', $conversation->chat->user_id)
            ->where('automated', false)
            ->orderBy('id')
            ->value('body');

        return [
            'client' => $this->firstName($conversation->chat->user?->name) ?: 'there',
            'agent' => $this->agentFirstName($conversation) ?: 'your agent',
            'listing' => $conversation->chat->listing?->name,
            // The listing's property code (listings.code, e.g. "CEB-16296").
            'code' => trim((string) $conversation->chat->listing?->code) ?: null,
            // The inquiry number = the last number of the chat link (chats.id).
            'inquiry' => (int) $conversation->chat_id,
            'location' => $this->listingLocation($conversation),
            'message' => Str::limit(trim($clientMessage), 1500),
            'asked' => ! $this->isDefaultInquiry($clientMessage),
            // Shown back to the client to confirm (the agent already sees both
            // in the chat's participant details).
            'email' => $conversation->chat->user?->email ?: null,
            'mobile' => trim((string) $conversation->chat->user?->mobile_no) ?: null,
            // The agent's own numbers (already public on their listings/profile).
            'agent_mobile' => trim((string) $conversation->agentUser?->agent?->mobile_no) ?: null,
            'agent_whatsapp' => trim((string) $conversation->agentUser?->agent?->whats_app_no) ?: null,
        ];
    }

    /**
     * Where the property is — the same public location the listing cards and
     * emails show (barangay, city, province), not the seller's full street
     * address; the address is only the fallback when those are missing.
     */
    private function listingLocation(Conversation $conversation): ?string
    {
        $listing = $conversation->chat->listing;
        if (! $listing) {
            return null;
        }
        $listing->loadMissing('property.barangay.city.province');
        $barangay = $listing->property?->barangay;
        $parts = array_filter([$barangay?->name, $barangay?->city?->name, $barangay?->city?->province?->name]);
        if ($parts) {
            return implode(', ', $parts);
        }
        $address = trim((string) $listing->property?->address);

        return $address !== '' ? preg_replace('/,\s*Philippines$/i', '', $address) : null;
    }

    /** The listing page's prefilled text with nothing added ("I'm interested in this property..."). */
    public function isDefaultInquiry(string $message): bool
    {
        $m = trim(Str::lower(preg_replace('/\s+/', ' ', $message)));
        $m = rtrim($m, ". \u{2026}");

        return $m === '' || in_array($m, ["i'm interested in this property", 'i’m interested in this property', 'i am interested in this property'], true);
    }

    private function templateAck(array $f): string
    {
        $about = $f['listing'] ? " about {$f['listing']}" : '';

        return "Hi {$f['client']}! Thank you for your inquiry{$about}. {$f['agent']} has been notified and will get in touch with you to assist you further.";
    }

    /** Everything after the greeting: the agent's direct numbers, then the client's details to confirm. */
    private function closing(array $f): string
    {
        $blocks = array_filter([
            implode("\n", array_filter([
                ! empty($f['code']) ? "🏠 Property code: {$f['code']}" : '',
                ! empty($f['inquiry']) ? "🔖 Inquiry number: {$f['inquiry']}" : '',
            ])),
            $this->agentBlock($f),
            $this->contactBlock($f),
        ]);

        return implode("\n\n", $blocks);
    }

    /** "You can also reach Juan directly" — the agent's mobile/WhatsApp, exact values. Empty when none is on file. */
    public function agentBlock(array $f): string
    {
        // 💬 = WhatsApp: the website chat and the email swap it for the
        // official WhatsApp logo (plain-text surfaces keep the emoji).
        $lines = array_values(array_filter([
            ! empty($f['agent_mobile']) ? "📞 {$f['agent_mobile']}" : null,
            ! empty($f['agent_whatsapp']) ? "💬 {$f['agent_whatsapp']}" : null,
        ]));

        return $lines ? "You can also reach {$f['agent']} directly:\n".implode("\n", $lines) : '';
    }

    /** "Are these still the right details?" — exact values, never written by the AI. */
    public function contactBlock(array $f): string
    {
        $lines = ['To make sure we can reach you, may we kindly confirm that your contact details are correct?'];
        $lines[] = $f['mobile'] ? "📱 {$f['mobile']}" : '📱 No mobile number yet — please share it here';
        if ($f['email']) {
            $lines[] = "✉️ {$f['email']}";
        }

        return implode("\n", $lines);
    }

    private function sign(string $text): string
    {
        return rtrim($text)."\n\n".self::SIGNATURE;
    }

    /* ------------------------------------------------------------------ */
    /* Assistant user + preview */
    /* ------------------------------------------------------------------ */

    /** The "Filipinohomes Assistant" sender. Created on first use with a random password; it never logs in. */
    public function assistantUser(): ?User
    {
        $email = (string) config('services.inquiry_ai.assistant_email', 'assistant@filipinohomes.com');
        if ($user = User::where('email', $email)->first()) {
            return $user;
        }
        $roleId = DB::table('roles')->where('name', 'client')->value('id');
        if (! $roleId) {
            return null;
        }

        return User::forceCreate([
            'name' => 'Filipinohomes Assistant',
            'email' => $email,
            'password' => Str::random(48),
            'role_id' => $roleId,
        ]);
    }

    /**
     * What the assistant would send for one conversation (default: the latest
     * accepted listing inquiry). Nothing is posted.
     */
    public function preview(?int $conversationId, array $s): ?array
    {
        // The number admins have is the last number of the chat link
        // (".../listing-inquiries/<slug>-1350") — the chat id, so look that up.
        $conversation = $conversationId
            ? Conversation::where('chat_id', $conversationId)->first()
            : Conversation::where('status', 'accepted')->whereHas('chat', fn ($c) => $c->where('type', 'listing'))->latest('id')->first();
        if (! $conversation) {
            return null;
        }
        [$ack, $source] = $this->composeAck($conversation, $s);
        $f = $this->facts($conversation);

        return [
            'conversation_id' => $conversation->id,
            'listing' => $f['listing'],
            'client_message' => $f['message'],
            'ack' => $ack,
            'ack_source' => $source,
            'escalation' => $this->escalationText($conversation, $s),
            // What this inquiry's first reply would be if its agent is inactive.
            'inactive_agent' => $this->inactiveAgentText($conversation, $s),
            'agent_inactive' => $this->agentInactive($conversation, $s),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function recent(int $limit = 10): Collection
    {
        return DB::table('inquiry_ai_replies as r')
            ->leftJoin('conversations as c', 'c.id', '=', 'r.conversation_id')
            ->leftJoin('chats as ch', 'ch.id', '=', 'c.chat_id')
            ->leftJoin('listings as l', 'l.id', '=', 'ch.type_id')
            ->leftJoin('messages as m', 'm.id', '=', 'r.message_id')
            ->orderByDesc('r.id')
            ->limit($limit)
            ->get(['r.conversation_id', 'r.stage', 'r.status', 'r.source', 'r.created_at', 'ch.id as chat_id', 'l.name as listing', 'm.body'])
            ->map(fn ($r) => [
                'conversation_id' => (int) $r->conversation_id,
                'chat_id' => $r->chat_id ? (int) $r->chat_id : null,
                'stage' => $r->stage,
                'status' => $r->status,
                'source' => $r->source,
                'listing' => $r->listing,
                'body' => $r->body ? Str::limit($r->body, 220) : null,
                'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
            ]);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * The agent's first name as on their agent profile — keeps Filipino double
     * first names whole ("John Mark", "Jay Ann") — in normal capitalisation
     * ("JUAN" → "Juan"). Falls back to the first word of the account name.
     */
    private function agentFirstName(Conversation $conversation): string
    {
        $first = trim((string) $conversation->agentUser?->agent?->first_name);
        if ($first === '') {
            $first = $this->firstName($conversation->agentUser?->name);
        }

        return $first === '' ? '' : Str::title(Str::lower($first));
    }

    private function firstName(?string $name): string
    {
        return Str::of((string) $name)->trim()->explode(' ')->first() ?? '';
    }

    public function durationLabel(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} min";
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h.' '.Str::plural('hour', $h).($m ? " {$m} min" : '');
    }

    /** @return int[] */
    private function sanitizeDays(array $days): array
    {
        $clean = array_values(array_unique(array_filter(array_map('intval', $days), fn ($d) => $d >= 0 && $d <= 6)));
        sort($clean);

        return $clean ?: self::DEFAULTS['office_days'];
    }

    private function validTime(mixed $v, string $default): string
    {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $default;
    }

    private function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }
}
