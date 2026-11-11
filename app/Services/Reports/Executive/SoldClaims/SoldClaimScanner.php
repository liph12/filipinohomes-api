<?php

namespace App\Services\Reports\Executive\SoldClaims;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads each agent-side chat reply once and keeps the ones that say the
 * listing is gone ({@see SoldClaimMatcher}) in `listing_sold_claims`, so the
 * Executive Report never has to read `messages.body` in a page request.
 *
 * Forward pass — message ids after a watermark kept in `settings`, up to the
 * last message older than SETTLE_MINUTES (a hasty reply is often edited or
 * unsent within minutes), CHUNK replies at a time. Every chunk is one id
 * window: its claims are upserted on `message_id` and any other claim row in
 * the same window is deleted. Re-reading a window is therefore always safe —
 * two hosts that both run the scheduler converge on the same rows — and a
 * matcher VERSION bump, which restarts the pass from id 0, rewrites them all.
 *
 * Re-check pass — claims whose message was unsent, deleted or edited after it
 * was read are read again and dropped when the text no longer claims anything.
 * The report re-checks the LISTING's status on every read, so a listing that
 * gets marked sold needs nothing from here.
 */
final class SoldClaimScanner
{
    public const SETTING = 'reports.sold_claims.scan';

    public const CHUNK = 500;

    public const SETTLE_MINUTES = 15;

    public const RECHECK_LIMIT = 2000;

    private const LIVE = ['active', 'updated'];

    public function __construct(private readonly SoldClaimMatcher $matcher) {}

    /**
     * @param  int  $max  agent replies to read this run; 0 = until caught up
     * @param  bool  $dryRun  match and report, but write nothing (the watermark stays)
     * @param  bool  $restart  start again from the first message
     * @return array{from: int, to: int, upper: int, scanned: int, found: array<string, int>, removed: int,
     *               rechecked: int, dropped: int, done: bool, version_reset: bool, samples: array<int, array>}
     */
    public function run(int $max = 20000, bool $dryRun = false, bool $restart = false, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $stamp = $now->copy()->utc()->format('Y-m-d H:i:s');

        $state = $this->state();
        // Version 0 = never run (the first run starts at 0 anyway).
        $versionReset = $state['version'] !== 0 && $state['version'] !== SoldClaimMatcher::VERSION;
        $from = $restart || $versionReset ? 0 : $state['last_id'];
        $start = $from;
        $upper = $this->upperId($now);

        $scanned = 0;
        $removed = 0;
        $found = ['strong' => 0, 'medium' => 0, 'weak' => 0];
        $samples = [];

        while ($from < $upper && ($max === 0 || $scanned < $max)) {
            $limit = $max === 0 ? self::CHUNK : min(self::CHUNK, $max - $scanned);
            $replies = $this->replies($from, $upper, $limit);
            // A short chunk means nothing else qualifies up to $upper.
            $to = $replies->count() < $limit ? $upper : (int) $replies->last()->id;

            $claims = [];
            foreach ($replies as $reply) {
                $match = $this->matcher->match($reply->body, $reply->category === 'For Rent');
                if ($match === null) {
                    continue;
                }
                $claims[] = $this->claimRow($reply, $match, $stamp);
                $found[$match['tier']]++;
                if (count($samples) < 50) {
                    $samples[] = ['message_id' => (int) $reply->id, 'listing_id' => (int) $reply->listing_id] + $match;
                }
            }

            if (! $dryRun) {
                $removed += DB::transaction(function () use ($claims, $from, $to) {
                    if ($claims !== []) {
                        DB::table('listing_sold_claims')->upsert($claims, ['message_id'], [
                            'conversation_id', 'chat_id', 'listing_id', 'speaker_user_id', 'speaker_role', 'client_user_id',
                            'tier', 'phrase', 'snippet', 'said_at', 'matcher_version', 'checked_at', 'updated_at',
                        ]);
                    }

                    return DB::table('listing_sold_claims')
                        ->where('message_id', '>', $from)
                        ->where('message_id', '<=', $to)
                        ->whereNotIn('message_id', array_column($claims, 'message_id') ?: [0])
                        ->delete();
                });
                $this->saveState($to, $stamp, false);
            }

            $scanned += $replies->count();
            $from = $to;
        }

        if (! $dryRun) {
            $this->saveState($from, $stamp, $from >= $upper);
        }

        [$rechecked, $dropped] = $dryRun ? [0, 0] : $this->recheck($from, $stamp);

        return [
            'from' => $start,
            'to' => $from,
            'upper' => $upper,
            'scanned' => $scanned,
            'found' => $found,
            'removed' => $removed,
            'rechecked' => $rechecked,
            'dropped' => $dropped,
            'done' => $from >= $upper,
            'version_reset' => $versionReset,
            'samples' => $samples,
        ];
    }

    /**
     * The newest message id that has settled: one below the first message of
     * the last SETTLE_MINUTES. Two index seeks (created_at, then the primary
     * key) — never a scan of the table.
     */
    private function upperId(Carbon $now): int
    {
        $cutoff = $now->copy()->subMinutes(self::SETTLE_MINUTES)->utc()->format('Y-m-d H:i:s');
        $firstUnsettled = DB::table('messages')->where('created_at', '>=', $cutoff)
            ->orderBy('created_at')->orderBy('id')->value('id');

        return $firstUnsettled !== null ? (int) $firstUnsettled - 1 : (int) DB::table('messages')->max('id');
    }

    /**
     * The next agent-side replies in listing chats: live, with text, written
     * by anyone but the client who opened the chat (the agent, a team leader
     * or an admin answering for them). Walks the messages primary key.
     */
    private function replies(int $after, int $upTo, int $limit): Collection
    {
        return DB::table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->join('chats as ch', 'ch.id', '=', 'c.chat_id')
            ->leftJoin('listings as l', 'l.id', '=', 'ch.type_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'l.category_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('roles as r', 'r.id', '=', 'u.role_id')
            ->where('m.id', '>', $after)
            ->where('m.id', '<=', $upTo)
            ->where('ch.type', 'listing')
            ->whereColumn('m.user_id', '<>', 'ch.user_id')
            ->whereIn('m.status', self::LIVE)
            ->whereNotNull('m.body')
            ->where('m.body', '<>', '')
            ->orderBy('m.id')
            ->limit($limit)
            ->get([
                'm.id', 'm.conversation_id', 'm.user_id', 'm.body', 'm.created_at',
                'c.chat_id', 'ch.type_id as listing_id', 'ch.user_id as client_id',
                'cat.name as category', 'r.name as role',
            ]);
    }

    private function claimRow(object $reply, array $match, string $stamp): array
    {
        return [
            'message_id' => (int) $reply->id,
            'conversation_id' => (int) $reply->conversation_id,
            'chat_id' => (int) $reply->chat_id,
            'listing_id' => (int) $reply->listing_id,
            'speaker_user_id' => (int) $reply->user_id,
            'speaker_role' => $reply->role !== null ? mb_substr((string) $reply->role, 0, 16) : null,
            'client_user_id' => (int) $reply->client_id,
            'tier' => $match['tier'],
            'phrase' => mb_substr($match['phrase'], 0, 64),
            'snippet' => mb_substr($match['snippet'], 0, 300),
            'said_at' => $reply->created_at,
            'matcher_version' => SoldClaimMatcher::VERSION,
            'checked_at' => $stamp,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }

    /**
     * Claims the forward pass already covered whose message has since gone
     * (unsent, deleted) or changed (edited — `updated_at` after `checked_at`).
     *
     * @return array{0: int, 1: int} [rechecked, dropped]
     */
    private function recheck(int $watermark, string $stamp): array
    {
        $rows = DB::table('listing_sold_claims as sc')
            ->leftJoin('messages as m', 'm.id', '=', 'sc.message_id')
            ->leftJoin('listings as l', 'l.id', '=', 'sc.listing_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'l.category_id')
            ->where('sc.message_id', '<=', $watermark)
            ->where(function ($q) {
                $q->whereNull('m.id')
                    ->orWhereNotIn('m.status', self::LIVE)
                    ->orWhereColumn('m.updated_at', '>', 'sc.checked_at');
            })
            ->orderBy('sc.id')
            ->limit(self::RECHECK_LIMIT)
            ->get(['sc.id', 'm.id as live_id', 'm.status', 'm.body', 'cat.name as category']);

        $drop = [];
        foreach ($rows as $row) {
            $match = $row->live_id !== null && in_array($row->status, self::LIVE, true)
                ? $this->matcher->match($row->body, $row->category === 'For Rent')
                : null;

            if ($match === null) {
                $drop[] = (int) $row->id;

                continue;
            }

            DB::table('listing_sold_claims')->where('id', $row->id)->update([
                'tier' => $match['tier'],
                'phrase' => mb_substr($match['phrase'], 0, 64),
                'snippet' => mb_substr($match['snippet'], 0, 300),
                'matcher_version' => SoldClaimMatcher::VERSION,
                'checked_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        }

        foreach (array_chunk($drop, 500) as $ids) {
            DB::table('listing_sold_claims')->whereIn('id', $ids)->delete();
        }

        return [$rows->count(), count($drop)];
    }

    /** @return array{version: int, last_id: int} */
    public function state(): array
    {
        $raw = Setting::get(self::SETTING);
        $state = is_string($raw) ? json_decode($raw, true) : null;

        return [
            'version' => (int) ($state['version'] ?? 0),
            'last_id' => (int) ($state['last_id'] ?? 0),
        ];
    }

    /**
     * When the scan last ran, and whether it had caught up with the settled
     * messages — the report says the list may be incomplete until it has.
     *
     * @return array{scanned_at: string|null, caught_up: bool}
     */
    public function freshness(): array
    {
        $raw = Setting::get(self::SETTING);
        $state = is_string($raw) ? json_decode($raw, true) : null;

        return [
            'scanned_at' => $state['scanned_at'] ?? null,
            'caught_up' => ($state['version'] ?? null) === SoldClaimMatcher::VERSION && ($state['caught_up'] ?? false) === true,
        ];
    }

    private function saveState(int $lastId, string $stamp, bool $caughtUp): void
    {
        Setting::set(self::SETTING, json_encode([
            'version' => SoldClaimMatcher::VERSION,
            'last_id' => $lastId,
            'scanned_at' => $stamp,
            'caught_up' => $caughtUp,
        ]));
    }
}
