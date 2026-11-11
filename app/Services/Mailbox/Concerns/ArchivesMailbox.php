<?php

namespace App\Services\Mailbox\Concerns;

use App\Models\MailboxMessage;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * Mailbox archive — frees the shared mailbox's storage without losing the
 * website emails.
 *
 * Each website email (a row in mailbox_messages) whose period is finished is
 * copied to S3 as its complete original (.eml: text, design, attachments),
 * gzipped and private; only after the upload is checked is it removed from
 * the mail server. The row stays and points at the S3 file, so the Mailbox
 * keeps listing, opening and replying to it. Hidden duplicate copies (the
 * admin@/itadmin@ forwards) are removed without archiving — the primary holds
 * the same email. Mail that isn't a website email is never touched: only
 * rows in the index are considered.
 *
 * Periods (Philippine time): one per year up to `yearly_until`, then one per
 * quarter, each archived `delay_months` after it ends (Q1 → May 1 …).
 *
 * S3 layout: {prefix}/{address}/{period}/{date}_{folder}_{uid}_{category}.eml.gz
 * — the name alone says what the file is, and lets the index be rebuilt
 * from S3 (restoreArchiveIndex) on a database that never saw the archive run.
 */
trait ArchivesMailbox
{
    public function archiveEnabled(): bool
    {
        return (bool) config('mailbox.archive.enabled', false);
    }

    private function archiveDisk(): Filesystem
    {
        return Storage::disk((string) config('mailbox.archive.disk', 's3'));
    }

    private function archiveTz(): string
    {
        return (string) config('mailbox.archive.timezone', 'Asia/Manila');
    }

    private function archiveDelay(): int
    {
        return max(0, (int) config('mailbox.archive.delay_months', 1));
    }

    /** "2025" (yearly) or "2026-Q2" (quarterly) for an email sent at $sentAt. */
    public function archivePeriod(Carbon $sentAt): string
    {
        $d = $sentAt->copy()->setTimezone($this->archiveTz());

        return $d->year <= (int) config('mailbox.archive.yearly_until', 2025)
            ? (string) $d->year
            : $d->year.'-Q'.$d->quarter;
    }

    /** When a period may be archived: its end + the delay (e.g. 2026-Q3 → Nov 1 2026). */
    public function archiveReadyAt(string $period): Carbon
    {
        $tz = $this->archiveTz();
        if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $m)) {
            $end = Carbon::create((int) $m[1], ((int) $m[2] - 1) * 3 + 1, 1, 0, 0, 0, $tz)->addMonthsNoOverflow(3);
        } else {
            $end = Carbon::create((int) $period + 1, 1, 1, 0, 0, 0, $tz);
        }

        return $end->addMonthsNoOverflow($this->archiveDelay())->utc();
    }

    /** S3 key of an archived email. */
    private function archiveKey(MailboxMessage $m): string
    {
        $date = $m->sent_at->copy()->setTimezone($this->archiveTz())->format('Y-m-d');

        return trim((string) config('mailbox.archive.prefix', 'filipinohomes-new/mailbox-archive'), '/')
            .'/'.strtolower($this->address())
            .'/'.$this->archivePeriod($m->sent_at)
            .'/'.$date.'_'.$m->folder.'_'.$m->uid.'_'.($m->category ?: 'other').'.eml.gz';
    }

    /**
     * Rows due for archiving now: not archived yet, with a date, in a period
     * whose archive date has passed. Primaries and their hidden copies.
     *
     * @return Collection<int, MailboxMessage>
     */
    public function archivePlan(?Carbon $now = null): Collection
    {
        $now ??= now();
        // Quarter cutoff as a fast SQL pre-filter; archiveReadyAt() decides per row.
        $cutoff = $now->copy()->setTimezone($this->archiveTz())
            ->subMonthsNoOverflow(3 + $this->archiveDelay())->startOfQuarter()->addMonthsNoOverflow(3)->utc();

        return MailboxMessage::query()
            ->where('account', $this->account())
            ->whereNull('archived_at')
            ->whereNotNull('sent_at')
            ->whereNotNull('category') // only recognised website emails (not look-alikes that slipped in)
            ->where('sent_at', '<', $cutoff)
            ->orderBy('folder_path')->orderBy('uid')
            ->get(['id', 'folder', 'folder_path', 'uid', 'message_id', 'is_primary', 'category', 'sent_at'])
            ->filter(fn (MailboxMessage $m) => $this->archiveReadyAt($this->archivePeriod($m->sent_at))->lte($now))
            ->values();
    }

    /**
     * Archive every due email. Dry run (default): reads sizes from the mail
     * server and (with $sample) downloads a few emails to check they parse and
     * to measure compression — nothing is uploaded, changed or deleted.
     *
     * @param  callable(string): void|null  $log
     * @return array<string, mixed>
     */
    public function archive(bool $dryRun = true, ?int $limit = null, int $sample = 0, ?callable $log = null): array
    {
        $log ??= static function (string $line): void {};
        $stats = [
            'dry_run' => $dryRun,
            'periods' => [],
            'emails' => 0, 'copies' => 0, 'bytes' => 0, 'copy_bytes' => 0,
            'stored_bytes' => 0, 'errors' => 0,
            'sample' => ['emails' => 0, 'raw' => 0, 'gz' => 0, 'unreadable' => 0, 'keys' => []],
        ];

        $lock = Cache::lock($this->ck('archive-lock'), 3600);
        if (! $lock->get()) {
            return $stats + ['skipped' => 'another archive run is going'];
        }

        try {
            $plan = $this->archivePlan();
            if ($plan->isEmpty()) {
                return $stats;
            }
            if ($limit !== null) {
                $keep = $plan->where('is_primary', true)->take($limit)->pluck('message_id', 'id');
                $mids = $keep->filter()->values()->all();
                $plan = $plan->filter(fn ($m) => $keep->has($m->id) || (! $m->is_primary && in_array($m->message_id, $mids, true)))->values();
            }

            foreach ($plan->groupBy('folder_path') as $path => $rows) {
                $this->withRetry(fn () => $this->client()->openFolder($path));
                $sizes = $this->withRetry(fn () => $this->rawSizes($rows->pluck('uid')->all()));
                $copiesByMid = $rows->where('is_primary', false)->groupBy(fn ($m) => (string) $m->message_id);
                // Copies whose primary was archived on an earlier run (e.g. that run's delete failed).
                $archivedMids = MailboxMessage::query()->where('account', $this->account())->where('folder_path', $path)
                    ->whereNotNull('archived_at')->whereIn('message_id', $copiesByMid->keys()->filter()->all())
                    ->pluck('message_id')->flip();

                $removeUids = [];
                $removeIds = [];
                foreach ($copiesByMid as $mid => $copies) {
                    if ($mid !== '' && isset($archivedMids[$mid])) {
                        foreach ($copies as $c) {
                            $this->countArchived($stats, $c, $sizes[$c->uid] ?? 0, copy: true);
                            $removeUids[] = (int) $c->uid;
                            $removeIds[] = $c->id;
                        }
                    }
                }

                foreach ($rows->where('is_primary', true)->chunk(20) as $chunk) {
                    $wantBodies = ! $dryRun || $stats['sample']['emails'] < $sample;
                    $bodies = $wantBodies ? $this->withRetry(fn () => $this->rawMessages($chunk->pluck('uid')->all())) : [];

                    foreach ($chunk as $m) {
                        $size = $sizes[$m->uid] ?? 0;
                        $blob = $bodies[$m->uid] ?? null;

                        if ($dryRun) {
                            $this->countArchived($stats, $m, $size);
                            if ($blob !== null && $stats['sample']['emails'] < $sample) {
                                $this->sampleCheck($stats, $m, $blob);
                            }
                        } else {
                            $stored = $this->archiveOne($m, $blob, $size, $log);
                            if ($stored === null) {
                                $stats['errors']++;

                                continue; // stays on the mail server; next run tries again
                            }
                            $this->countArchived($stats, $m, $size);
                            $stats['stored_bytes'] += $stored;
                            $removeUids[] = (int) $m->uid;
                        }

                        // Its hidden copies go with it (they are the same email).
                        foreach ($copiesByMid->get((string) $m->message_id, collect()) as $c) {
                            if ((string) $m->message_id === '') {
                                break;
                            }
                            $this->countArchived($stats, $c, $sizes[$c->uid] ?? 0, copy: true);
                            if (! $dryRun) {
                                $removeUids[] = (int) $c->uid;
                                $removeIds[] = $c->id;
                            }
                        }
                    }
                }

                if (! $dryRun && $removeUids !== []) {
                    $this->withRetry(function () use ($path, $removeUids) {
                        $this->client()->openFolder($path);
                        $this->expungeUids($removeUids);
                    });
                    foreach (array_chunk($removeIds, 1000) as $ids) {
                        MailboxMessage::query()->whereIn('id', $ids)->delete();
                    }
                    $this->folderChanged($path);
                    $log(sprintf('%s: archived and removed %d emails from the mail server', $path, count($removeUids)));
                }
            }

            if (! $dryRun) {
                $this->forgetCategoryCounts();
                Cache::forget($this->ck('quota'));
            }

            return $stats;
        } finally {
            $lock->release();
        }
    }

    /** Upload one email; returns the stored (gzipped) size, or null when it must stay on the server. */
    private function archiveOne(MailboxMessage $m, ?string $blob, int $size, callable $log): ?int
    {
        if ($blob === null || $blob === '') {
            $log("uid {$m->uid} ({$m->folder}): could not download — kept on the mail server");

            return null;
        }
        try {
            Message::fromString($blob, $this->client()->getConfig()); // must be readable later
        } catch (\Throwable $e) {
            $log("uid {$m->uid} ({$m->folder}): unreadable ({$e->getMessage()}) — kept on the mail server");

            return null;
        }

        $gz = gzencode($blob, 9);
        $key = $this->archiveKey($m);
        $disk = $this->archiveDisk();
        try {
            $ok = $disk->put($key, $gz, [
                'visibility' => 'private',
                'ContentType' => 'application/gzip',
                'CacheControl' => 'private, no-store',
            ]);
            // Checked by reading it back: same bytes or it doesn't count.
            $ok = $ok && $disk->get($key) === $gz;
        } catch (\Throwable $e) {
            Log::warning('Mailbox archive: upload failed', ['key' => $key, 'error' => $e->getMessage()]);
            $ok = false;
        }
        if (! $ok) {
            $log("uid {$m->uid} ({$m->folder}): upload to S3 failed — kept on the mail server");

            return null;
        }

        MailboxMessage::query()->whereKey($m->id)->update([
            'archived_at' => now(),
            'archive_path' => $key,
            'archive_bytes' => $size ?: strlen($blob),
        ]);

        return strlen($gz);
    }

    /** @param array<string, mixed> $stats */
    private function countArchived(array &$stats, MailboxMessage $m, int $size, bool $copy = false): void
    {
        $p = $this->archivePeriod($m->sent_at);
        $stats['periods'][$p] ??= ['emails' => 0, 'copies' => 0, 'bytes' => 0, 'ready_at' => $this->archiveReadyAt($p)->setTimezone($this->archiveTz())->toIso8601String()];
        $stats['periods'][$p][$copy ? 'copies' : 'emails']++;
        $stats['periods'][$p]['bytes'] += $size;
        $stats[$copy ? 'copies' : 'emails']++;
        $stats['bytes'] += $size;
        if ($copy) {
            $stats['copy_bytes'] += $size;
        }
    }

    /** Dry run: would this email archive fine, and how small does it get? @param array<string, mixed> $stats */
    private function sampleCheck(array &$stats, MailboxMessage $m, string $blob): void
    {
        $stats['sample']['emails']++;
        $stats['sample']['raw'] += strlen($blob);
        $stats['sample']['gz'] += strlen((string) gzencode($blob, 9));
        try {
            Message::fromString($blob, $this->client()->getConfig());
        } catch (\Throwable) {
            $stats['sample']['unreadable']++;
        }
        if (count($stats['sample']['keys']) < 3) {
            $stats['sample']['keys'][] = $this->archiveKey($m);
        }
    }

    /** uid => size in bytes, for the selected folder (one round-trip per 300). @return array<int, int> */
    private function rawSizes(array $uids): array
    {
        $conn = $this->client()->getConnection();
        $out = [];
        foreach (array_chunk($this->cleanUids($uids), 300) as $chunk) {
            $lines = $conn->requestAndResponse('UID FETCH', [implode(',', $chunk), '(RFC822.SIZE)'])->getResponse();
            foreach ((array) $lines as $line) {
                if (preg_match('/UID (\d+) RFC822\.SIZE (\d+)|RFC822\.SIZE (\d+) UID (\d+)/', (string) $line, $mm)) {
                    $mm[1] !== '' ? $out[(int) $mm[1]] = (int) $mm[2] : $out[(int) $mm[4]] = (int) $mm[3];
                }
            }
        }

        return $out;
    }

    /**
     * uid => the complete original email, for the selected folder. BODY.PEEK
     * so downloading doesn't mark anything read.
     *
     * @return array<int, string>
     */
    private function rawMessages(array $uids): array
    {
        $uids = $this->cleanUids($uids);
        if ($uids === []) {
            return [];
        }
        $rows = $this->client()->getConnection()
            ->fetch(['RFC822.SIZE', 'BODY.PEEK[]'], $uids, null, IMAP::ST_UID)->setCanBeEmpty(true)->validatedData();
        $out = [];
        foreach ((array) $rows as $uid => $row) {
            $blob = is_array($row) ? ($row['BODY[]'] ?? null) : null;
            if (is_string($blob) && $blob !== '') {
                $out[(int) $uid] = $blob;
            }
        }

        return $out;
    }

    /** Permanently remove exactly these UIDs from the selected folder (UID EXPUNGE — nothing else). */
    private function expungeUids(array $uids): void
    {
        $uids = $this->cleanUids($uids);
        $conn = $this->client()->getConnection();
        foreach (array_chunk($uids, 300) as $chunk) {
            $this->storeMany($chunk, '\\Deleted', true);
            $conn->requestAndResponse('UID EXPUNGE', [implode(',', $chunk)], true);
        }
    }

    // ── Reading archived emails ──────────────────────────────────────────

    /** The indexed row for (folder path, uid) if it's archived. */
    private function archivedRow(string $path, int $uid): ?MailboxMessage
    {
        if (! $this->indexReady()) {
            return null;
        }

        return MailboxMessage::query()->where('account', $this->account())->where('folder_path', $path)
            ->where('uid', $uid)->whereNotNull('archived_at')->first();
    }

    /** The archived email, parsed from its S3 file. */
    private function archivedMessage(MailboxMessage $row): Message
    {
        $gz = $row->archive_path ? $this->archiveDisk()->get($row->archive_path) : null;
        $blob = is_string($gz) ? gzdecode($gz) : false;
        abort_if(! is_string($blob) || $blob === '', 404, 'This archived email could not be read from the archive.');

        return Message::fromString($blob); // default parser config: no mail-server login needed
    }

    /** IMAP-style flags from the row, for archived emails (no server copy to ask). @return string[] */
    private function rowFlags(MailboxMessage $row): array
    {
        return array_values(array_filter([
            $row->seen ? '\\Seen' : null,
            $row->flagged ? '\\Flagged' : null,
            $row->answered ? '\\Answered' : null,
        ]));
    }

    /** UIDs among $uids (in $path) that are archived. @return int[] */
    private function archivedUids(string $path, array $uids): array
    {
        if ($uids === [] || ! $this->indexReady()) {
            return [];
        }

        return MailboxMessage::query()->where('account', $this->account())->where('folder_path', $path)
            ->whereIn('uid', $uids)->whereNotNull('archived_at')->pluck('uid')->map(fn ($u) => (int) $u)->all();
    }

    // ── Rebuilding the index from S3 ─────────────────────────────────────

    /**
     * Add rows for archived emails that are in S3 but not in this database
     * (e.g. the archive ran on another copy of the app). Reads only the
     * headers out of each file. Returns how many rows were added.
     */
    public function restoreArchiveIndex(?callable $log = null): int
    {
        $log ??= static function (string $line): void {};
        $root = trim((string) config('mailbox.archive.prefix'), '/').'/'.strtolower($this->address());
        $known = MailboxMessage::query()->where('account', $this->account())->whereNotNull('archive_path')
            ->pluck('archive_path')->flip();
        $paths = $this->folderMap();
        $added = 0;

        foreach ($this->archiveDisk()->allFiles($root) as $key) {
            if (isset($known[$key]) || ! preg_match('#/([a-z]+)_(\d+)_([a-z_]+)\.eml\.gz$#', $key, $m)) {
                continue;
            }
            [, $folder, $uid, $category] = $m;
            if (! isset($paths[$folder])) {
                continue;
            }
            $gz = $this->archiveDisk()->get($key);
            $blob = is_string($gz) ? gzdecode($gz) : false;
            if (! is_string($blob)) {
                $log("skip {$key}: unreadable");

                continue;
            }
            try {
                $message = Message::fromString($blob, $this->client()->getConfig());
            } catch (\Throwable $e) {
                $log("skip {$key}: {$e->getMessage()}");

                continue;
            }
            $header = $message->getHeader();
            $sum = $this->summaryFromHeader((int) $uid, $header, ['\\Seen']);
            $mid = strtolower(trim($this->headerString($header->get('message_id')), " \t<>"));
            $shownFrom = $this->displaySender($category === 'other' ? null : $category, $sum['from'], $this->addresses($header->get('reply_to')));
            $chatId = preg_match('/^X-FH-Chat-Id:\s*(\d+)/mi', $blob, $cm) ? (int) $cm[1] : null;

            MailboxMessage::query()->insertOrIgnore([
                'account' => $this->account(),
                'folder' => $folder,
                'folder_path' => $paths[$folder]['path'],
                'uidvalidity' => 0, // the server copy is gone; 0 never clashes with live rows
                'uid' => (int) $uid,
                'message_id' => $mid !== '' ? mb_substr('<'.$mid.'>', 0, 191) : null,
                'is_primary' => true,
                'category' => $category === 'other' ? null : $category,
                'chat_id' => $chatId,
                'chat_checked' => $chatId !== null,
                'from_name' => mb_substr($shownFrom['name'], 0, 191),
                'from_email' => mb_substr($shownFrom['email'], 0, 191),
                'to' => json_encode($sum['to']),
                'subject' => mb_substr($sum['subject'], 0, 500),
                'sent_at' => $sum['date'] ? Carbon::parse($sum['date'])->utc()->toDateTimeString() : null,
                'seen' => true,
                'flagged' => false,
                'answered' => false,
                'archived_at' => now(),
                'archive_path' => $key,
                'archive_bytes' => strlen($blob),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $added++;
        }
        if ($added > 0) {
            $this->forgetCategoryCounts();
        }

        return $added;
    }
}
