<?php

namespace App\Services\Inquiry;

use App\Models\Inquiry;
use App\Models\InquiryRead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Contact Inbox read state, per admin.
 *
 * A submission is read for an admin when their inquiry_reads row has read_at set. With no row,
 * the old shared inquiries.read_at decides (submissions read before per-admin tracking stay read
 * for everyone). A row with read_at null means the admin marked it unread.
 */
class InquiryReadState
{
    /** Limit a query to the submissions this admin hasn't read. */
    public function scopeUnread(Builder $query, int $userId): Builder
    {
        $mine = fn (QueryBuilder $q) => $q->from('inquiry_reads')
            ->whereColumn('inquiry_reads.inquiry_id', 'inquiries.id')
            ->where('inquiry_reads.user_id', $userId);

        return $query
            ->whereNotExists(fn (QueryBuilder $q) => $mine($q)->whereNotNull('inquiry_reads.read_at'))
            ->where(fn (Builder $q) => $q
                ->whereNull('inquiries.read_at')
                ->orWhereExists(fn (QueryBuilder $q) => $mine($q)->whereNull('inquiry_reads.read_at')));
    }

    public function unreadCount(int $userId): int
    {
        return $this->scopeUnread(Inquiry::query(), $userId)->count();
    }

    /** How many days a submission counts as recent (topbar notice). */
    public const RECENT_DAYS = 5;

    /**
     * This admin's unread submissions from the last RECENT_DAYS days: how many, plus the newest one.
     *
     * @return array{count: int, days: int, latest: array{id: int, name: string, created_at: string}|null}
     */
    public function recentUnread(int $userId): array
    {
        $recent = fn () => $this->scopeUnread(Inquiry::query(), $userId)->where('inquiries.created_at', '>=', now()->subDays(self::RECENT_DAYS));
        $latest = $recent()->orderByDesc('inquiries.created_at')->orderByDesc('inquiries.id')->first(['inquiries.id', 'inquiries.name', 'inquiries.created_at']);

        return [
            'count' => $recent()->count(),
            'days' => self::RECENT_DAYS,
            'latest' => $latest ? ['id' => $latest->id, 'name' => $latest->name, 'created_at' => $latest->created_at->toIso8601String()] : null,
        ];
    }

    /** Record that the admin opened it (first open only — the original time is kept). */
    public function markRead(Inquiry $inquiry, int $userId): void
    {
        $row = InquiryRead::firstOrNew(['inquiry_id' => $inquiry->id, 'user_id' => $userId]);
        if ($row->read_at === null) {
            $row->read_at = now();
            $row->save();
        }
    }

    public function markUnread(Inquiry $inquiry, int $userId): void
    {
        InquiryRead::updateOrCreate(['inquiry_id' => $inquiry->id, 'user_id' => $userId], ['read_at' => null]);
    }

    /**
     * Bulk read/unread for this admin (list checkboxes). Marking read keeps the first read time on
     * submissions they had already read.
     *
     * @param  int[]  $ids
     */
    public function markMany(array $ids, int $userId, bool $read): void
    {
        $now = now();
        if ($read) {
            $alreadyRead = InquiryRead::where('user_id', $userId)->whereIn('inquiry_id', $ids)->whereNotNull('read_at')->pluck('inquiry_id')->all();
            $ids = array_diff($ids, $alreadyRead);
        }
        if ($ids === []) {
            return;
        }

        InquiryRead::upsert(
            array_map(fn ($id) => ['inquiry_id' => $id, 'user_id' => $userId, 'read_at' => $read ? $now : null, 'created_at' => $now, 'updated_at' => $now], array_values($ids)),
            ['inquiry_id', 'user_id'],
            ['read_at', 'updated_at'],
        );
    }

    public function markAllRead(int $userId): void
    {
        $now = now();
        $this->scopeUnread(Inquiry::query(), $userId)->select('inquiries.id')->chunkById(500, function ($rows) use ($userId, $now) {
            InquiryRead::upsert(
                $rows->map(fn ($r) => ['inquiry_id' => $r->id, 'user_id' => $userId, 'read_at' => $now, 'created_at' => $now, 'updated_at' => $now])->all(),
                ['inquiry_id', 'user_id'],
                ['read_at', 'updated_at'],
            );
        }, 'inquiries.id', 'id');
    }

    /**
     * Set each submission's `read_at` to this admin's own read time (null = unread for them) and add
     * `read_by`: the admins who have read it, earliest first.
     *
     * @param  Collection<int, Inquiry>  $inquiries
     */
    public function present(Collection $inquiries, int $userId): void
    {
        if ($inquiries->isEmpty()) {
            return;
        }

        $reads = InquiryRead::with('user:id,name,avatar')
            ->whereIn('inquiry_id', $inquiries->pluck('id'))
            ->get()
            ->groupBy('inquiry_id');

        foreach ($inquiries as $inquiry) {
            $rows = $reads->get($inquiry->id, collect());
            $mine = $rows->firstWhere('user_id', $userId);

            $inquiry->setAttribute('read_at', $mine ? $mine->read_at : $inquiry->read_at);
            $inquiry->setAttribute('read_by', $rows
                ->filter(fn (InquiryRead $r) => $r->read_at !== null && $r->user !== null)
                ->sortBy('read_at')
                ->map(fn (InquiryRead $r) => [
                    'id' => $r->user->id,
                    'name' => $r->user->name,
                    'avatar' => $r->user->avatar,
                    'read_at' => $r->read_at?->toIso8601String(),
                ])
                ->values()
                ->all());
        }
    }
}
