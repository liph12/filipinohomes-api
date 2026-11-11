<?php

namespace App\Services\Mailbox;

use App\Models\Conversation;
use App\Models\Inquiry;
use App\Models\InquiryReply;
use App\Models\MailboxMessage;
use App\Services\Mailbox\Concerns\ArchivesMailbox;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Message as MailMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Exceptions\RuntimeException as ImapRuntimeException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * The info@filipinohomes.com mailbox, read over IMAP and sent through its own
 * SMTP transport (the "mailbox" mailer). Nothing is stored in our database:
 * Hostinger is the source of truth, so webmail, phones and this dashboard all
 * see the same mail. Messages are addressed by IMAP UID within a folder.
 *
 * Folders are exposed by a stable key (inbox, starred, sent, drafts, spam,
 * trash, archive) rather than the server's path, because servers name them
 * differently ("Sent" vs "INBOX.Sent" vs "Sent Items"). "starred" is virtual:
 * the INBOX filtered to \Flagged, like Gmail's.
 */
class MailboxService
{
    use ArchivesMailbox; // old website emails → S3, freed from the mail server

    /** Folder key → simple names that identify it on the server (lower-case). */
    private const SPECIAL = [
        'sent' => ['sent', 'sent items', 'sent messages', 'sent mail'],
        'drafts' => ['drafts', 'draft'],
        'spam' => ['spam', 'junk', 'junk e-mail', 'junk email', 'bulk mail'],
        'trash' => ['trash', 'deleted', 'deleted items', 'deleted messages', 'bin'],
        'archive' => ['archive', 'archives'],
    ];

    private const LABELS = [
        'inbox' => 'Inbox',
        'starred' => 'Starred',
        'sent' => 'Sent',
        'drafts' => 'Drafts',
        'spam' => 'Spam',
        'trash' => 'Trash',
        'archive' => 'Archive',
    ];

    private const FOLDER_CACHE_KEY = 'mailbox:folders:v1';

    /**
     * Website-form emails, by the subject each sender in this app writes
     * (see the Mailables). Order = tab order. "website" is all of them.
     *   listing         MessageNotificationMailer 'admin'        → /admin/listing-inquiries?tab=inquiries
     *   agent_profile   MessageNotificationMailer 'agent_profile'
     *   website_builder MessageNotificationMailer 'agent_page'   (Website Builder agent sites)
     *   messages        MessageNotificationMailer default        → /admin/listing-inquiries?tab=messages
     *   get_in_touch    InquiryMailer home_get_in_touch          → /admin/contact-inbox
     *   contact_us      ContactUsMailer                          → /admin/contact-inbox
     *   maintenance     InquiryMailer maintenance_page           → /admin/contact-inbox
     * "Get In Touch:" / "Contact Us:" keep their colon so "Contact Us (Maintenance):"
     * only lands in maintenance.
     */
    public const CATEGORIES = [
        // Listing inquiries in two steps: submitted (to admins / team leader, needs approval) …
        'listing' => ['label' => 'New Inquiries', 'subjects' => ['New inquiry pending review']],
        // … then, once approved, the agent's copy (MessageNotificationMailer 'agent').
        'assigned' => ['label' => 'Forwarded to Agents', 'subjects' => ['Inquiry assigned to you']],
        'agent_profile' => ['label' => 'Agent Profile', 'subjects' => ['New inquiry from your agent profile']],
        'website_builder' => ['label' => 'Agent Website', 'subjects' => ['New inquiry from your agent page']],
        'messages' => ['label' => 'Messages', 'subjects' => ['Filipino Homes - New message received']],
        'get_in_touch' => ['label' => 'Get In Touch', 'subjects' => ['Get In Touch:']],
        'contact_us' => ['label' => 'Contact Us', 'subjects' => ['Contact Us:', 'New Contact Us Submission']],
        'maintenance' => ['label' => 'Maintenance', 'subjects' => ['Contact Us (Maintenance)']],
    ];

    /** Every inbox view key the API accepts: website (all inquiries) and each inquiry type. */
    public static function categoryKeys(): array
    {
        return array_merge(['website'], array_keys(self::CATEGORIES));
    }

    private ?Client $client = null;

    /** Per-request memo of folder STATUS (path => messages/uidnext/uidvalidity). */
    private array $folderStates = [];

    public function isConfigured(): bool
    {
        return filled(config('mailbox.username')) && filled(config('mailbox.password'));
    }

    public function address(): string
    {
        return (string) config('mailbox.username');
    }

    /**
     * Cache-key prefix for this mailbox account. Folder names, UIDs, counts
     * and quota belong to one account, so switching MAILBOX_USERNAME /
     * MAILBOX_IMAP_HOST (e.g. info@ on Hostinger → the IT Gmail) must not
     * reuse the other account's cached data.
     */
    private function ck(string $key): string
    {
        return 'mailbox:'.substr(md5(strtolower($this->address()).'|'.config('mailbox.imap.host')), 0, 10).':'.$key;
    }

    // ── Folders ──────────────────────────────────────────────────────────

    /**
     * Sidebar folders (no counts — see categories()). Keys the server doesn't
     * have are left out (except inbox and starred, which always exist).
     *
     * @return array<int, array{key:string,label:string,total:int,unread:int}>
     */
    public function folders(): array
    {
        $map = $this->folderMap();
        $out = [];

        // Totals per folder from the database copy (one cheap GROUP BY) — same
        // rows the list shows: one per email, duplicates hidden. Zero until the
        // first sync, when counting would mean a slow server search per folder.
        $totals = [];
        $starred = 0;
        if ($this->indexReady()) {
            $totals = MailboxMessage::query()->where('account', $this->account())->where('is_primary', true)
                ->selectRaw('folder, count(*) as n')->groupBy('folder')->pluck('n', 'folder')->map(fn ($n) => (int) $n)->all();
            $starred = $this->indexQuery('inbox')->where('is_primary', true)->where('flagged', true)->count();
        }

        foreach (array_keys(self::LABELS) as $key) {
            if ($key === 'starred') {
                $out[] = ['key' => 'starred', 'label' => 'Starred', 'total' => $starred, 'unread' => 0];

                continue;
            }
            if (! isset($map[$key])) {
                continue;
            }
            // Inbox unread comes from categories(); live mode never counts here
            // (a server SEARCH per folder, ~2 s each on a 34k inbox).
            $out[] = ['key' => $key, 'label' => self::LABELS[$key], 'total' => $totals[$key] ?? 0, 'unread' => 0];
        }

        return $out;
    }

    /**
     * Mailbox storage from the server's IMAP QUOTA (Hostinger reports KB).
     * When it's full the server refuses new mail — info@ stopped receiving
     * on Sept 19 2026 at 10 GB / 10 GB. Null when the server has no quota.
     *
     * @return array{used_bytes:int, limit_bytes:int, messages:?int, message_limit:?int}|null
     */
    public function quota(): ?array
    {
        try {
            // Cached 5 minutes: storage barely moves and this runs on every page load.
            $data = Cache::remember($this->ck('quota'), 300, fn () => $this->client()->getConnection()->getQuotaRoot('INBOX')->validatedData());
        } catch (\Throwable $e) {
            Log::info('Mailbox: quota unavailable', ['error' => $e->getMessage()]);

            return null;
        }

        // [..., ['QUOTA', '<root>', ['STORAGE', used, limit, 'MESSAGE', used, limit]], ...]
        foreach ($data as $line) {
            if (($line[0] ?? null) !== 'QUOTA' || ! is_array($line[2] ?? null)) {
                continue;
            }
            $items = $line[2];
            $out = ['used_bytes' => 0, 'limit_bytes' => 0, 'messages' => null, 'message_limit' => null];
            for ($i = 0; $i + 2 < count($items) + 1; $i += 3) {
                $name = strtoupper((string) ($items[$i] ?? ''));
                $used = (int) ($items[$i + 1] ?? 0);
                $limit = (int) ($items[$i + 2] ?? 0);
                if ($name === 'STORAGE') {
                    $out['used_bytes'] = $used * 1024;
                    $out['limit_bytes'] = $limit * 1024;
                } elseif ($name === 'MESSAGE') {
                    $out['messages'] = $used;
                    $out['message_limit'] = $limit;
                }
            }

            return $out['limit_bytes'] > 0 ? $out : null;
        }

        return null;
    }

    public function hasFolder(string $key): bool
    {
        return $key === 'starred' || isset($this->folderMap()[$key]);
    }

    // ── Index: the database copy (Option B) ──────────────────────────────
    //
    // `mailbox:sync` (every minute) copies the website emails' headers and
    // flags into `mailbox_messages`. While that copy is fresh, the dashboard
    // reads it instead of logging in to the mail server on every click; when
    // it isn't (sync never ran, scheduler stopped), everything falls back to
    // the live IMAP path above, so the Mailbox keeps working either way.

    /** Older than this, opening the Mailbox starts a background sync (after the response). */
    private const INDEX_STALE_SECONDS = 120;

    /** Same account fingerprint as the cache prefix (address + server). */
    private function account(): string
    {
        return substr(md5(strtolower($this->address()).'|'.config('mailbox.imap.host')), 0, 10);
    }

    public function syncedAt(): ?Carbon
    {
        $at = Cache::get($this->ck('synced_at'));

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * True once the database copy exists (a sync has run at least once). Reads
     * then always come from the database — never live from the mail server —
     * even if the copy is a little old; syncIfStale() keeps it fresh.
     */
    public function indexReady(): bool
    {
        return $this->syncedAt() !== null;
    }

    /**
     * When the copy is older than INDEX_STALE_SECONDS (e.g. the scheduler isn't
     * running), sync after the response is sent — the viewer never waits, and
     * the sync lock stops several viewers from syncing at once.
     */
    public function syncIfStale(): void
    {
        $at = $this->syncedAt();
        if ($at !== null && $at->diffInSeconds(now(), true) < self::INDEX_STALE_SECONDS) {
            return;
        }
        app()->terminating(function () {
            try {
                $this->sync();
            } catch (\Throwable $e) {
                Log::warning('Mailbox: background sync failed', ['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Bring the database copy up to date with the mail server: new website
     * emails added, removed ones dropped, read/star changes picked up.
     * One login per run, whatever the number of dashboard users.
     *
     * @param  string[]|null  $only  folder keys to sync (null = all)
     * @return array<string, mixed>
     */
    public function sync(?array $only = null): array
    {
        $lock = Cache::lock($this->ck('sync-lock'), 300);
        if (! $lock->get()) {
            return ['skipped' => 'another sync is running'];
        }

        try {
            return $this->withRetry(function () use ($only) {
                $this->folderStates = [];
                $stats = [];
                foreach ($this->folderMap() as $key => $entry) {
                    if ($only !== null && ! in_array($key, $only, true)) {
                        continue;
                    }
                    $stats[$key] = $this->syncFolder($key, $entry['path']);
                }

                // Link older inquiry emails to their inquiry (for the approval badge),
                // and show the client as sender on older form emails.
                if ($only === null) {
                    $stats['linked'] = $this->linkInquiryEmails();
                    foreach ($this->folderMap() as $entry) {
                        $this->fixFormSenders($entry['path']);
                    }
                }

                // Storage for the meter, refreshed with every run.
                try {
                    Cache::put($this->ck('quota'), $this->client()->getConnection()->getQuotaRoot('INBOX')->validatedData(), 300);
                } catch (\Throwable) {
                    // optional
                }
                $this->forgetCategoryCounts();
                if ($only === null) {
                    Cache::forever($this->ck('synced_at'), now()->toIso8601String());
                }

                return $stats;
            });
        } finally {
            $lock->release();
        }
    }

    /** A full re-check of every email's read/star state at least this often (safety net). */
    private const FULL_FLAG_CHECK_SECONDS = 1800;

    /**
     * Incremental: the folder's change counter (CONDSTORE HIGHESTMODSEQ) is
     * compared with the last run — unchanged means nothing at all happened,
     * so the folder is skipped (the usual case). When it changed, only new /
     * removed emails are looked up (if the folder's size changed) and only the
     * emails whose flags changed since the last counter are fetched.
     *
     * @return array{added:int, removed:int, updated:int, skipped?:bool}
     */
    private function syncFolder(string $key, string $path): array
    {
        $state = $this->folderState($path);
        $account = $this->account();
        // Archived rows (S3) are no longer on the server — sync leaves them alone.
        $base = MailboxMessage::query()->where('account', $account)->where('folder_path', $path)->whereNull('archived_at');
        // Includes the website criteria: changing categories forces a full re-check.
        $prevKey = $this->ck('state:'.md5($path.'|'.$this->websiteCriteria()));
        $prev = Cache::get($prevKey);

        $sameFolder = is_array($prev) && $prev['uidvalidity'] === $state['uidvalidity'];
        $fullCheckDue = ! $sameFolder || ($prev['full_at'] ?? 0) < time() - self::FULL_FLAG_CHECK_SECONDS;
        $canTrackChanges = $sameFolder && $state['modseq'] > 0 && ($prev['modseq'] ?? 0) > 0;

        // Nothing changed since last run → skip entirely (no search, no flags).
        if ($canTrackChanges && ! $fullCheckDue && $prev['modseq'] === $state['modseq']) {
            return ['added' => 0, 'removed' => 0, 'updated' => 0, 'skipped' => true];
        }

        $this->client()->openFolder($path);
        $conn = $this->client()->getConnection();

        // A new UIDVALIDITY means the server renumbered the folder: start over.
        (clone $base)->where('uidvalidity', '!=', $state['uidvalidity'])->delete();

        // New or removed emails only when the folder's size/next-UID moved.
        $added = [];
        $removed = [];
        $membershipChanged = ! $sameFolder || $prev['uidnext'] !== $state['uidnext'] || $prev['messages'] !== $state['messages'];
        if ($membershipChanged || $fullCheckDue) {
            $serverUids = $this->liveSearch('ALL '.$this->websiteCriteria());
            if ($key === 'inbox') {
                $serverUids = array_values(array_unique(array_merge($serverUids, $this->clientFormReplyUids())));
            }
            $known = (clone $base)->pluck('uid')->map(fn ($u) => (int) $u)->all();

            $removed = array_values(array_diff($known, $serverUids));
            foreach (array_chunk($removed, 1000) as $chunk) {
                (clone $base)->whereIn('uid', $chunk)->delete();
            }

            $added = array_values(array_diff($serverUids, $known));
            $this->indexNewEmails($key, $path, $state['uidvalidity'], $added);
        }

        // Read / star / answered changes made elsewhere (webmail, phones).
        if ($canTrackChanges && ! $fullCheckDue) {
            $changed = $this->changedFlagsSince($prev['modseq']);        // only what changed
        } else {
            $changed = [];
            $uids = (clone $base)->pluck('uid')->map(fn ($u) => (int) $u)->all(); // full re-check
            foreach (array_chunk($uids, 1000) as $chunk) {
                $changed += $conn->flags($chunk, IMAP::ST_UID)->validatedData();
            }
        }
        $updated = $this->applyFlags($base, $changed);

        if ($added !== [] || $removed !== []) {
            $this->markPrimaries($path);
        }

        Cache::forever($prevKey, [
            'uidvalidity' => $state['uidvalidity'],
            'uidnext' => $state['uidnext'],
            'messages' => $state['messages'],
            'modseq' => $state['modseq'],
            'full_at' => $fullCheckDue ? time() : ($prev['full_at'] ?? time()),
        ]);

        return ['added' => count($added), 'removed' => count($removed), 'updated' => $updated];
    }

    /** Headers + flags of newly found website emails into the index. */
    private function indexNewEmails(string $key, string $path, int $uidvalidity, array $uids): void
    {
        $conn = $this->client()->getConnection();
        $config = $this->client()->getConfig();
        foreach (array_chunk($uids, 200) as $chunk) {
            $flags = $conn->flags($chunk, IMAP::ST_UID)->validatedData();
            $headers = $conn->headers($chunk, 'RFC822', IMAP::ST_UID)->validatedData();
            $rows = [];
            foreach ($chunk as $uid) {
                if (! isset($headers[$uid])) {
                    continue;
                }
                try {
                    $header = new Header($headers[$uid], $config);
                } catch (\Throwable) {
                    continue;
                }
                $sum = $this->summaryFromHeader($uid, $header, $flags[$uid] ?? []);
                $mid = strtolower(trim($this->headerString($header->get('message_id')), " \t"));
                $mid = $mid !== '' ? '<'.trim($mid, '<>').'>' : null;
                // Newer inquiry emails name their inquiry in X-FH-Chat-Id; older ones are linked from their body later.
                $chatId = preg_match('/^X-FH-Chat-Id:\s*(\d+)/mi', (string) $headers[$uid], $cm) ? (int) $cm[1] : null;
                $category = $this->categoryForSubject($sum['subject']);
                if ($category === null && str_contains(mb_strtolower($sum['subject']), 'your message')) {
                    $src = Inquiry::query()->where('email', $sum['from']['email'])->orderByDesc('created_at')->value('source');
                    $category = array_search($src, ['contact_us' => 'contact_page', 'get_in_touch' => 'home_get_in_touch', 'maintenance' => 'maintenance_page'], true) ?: 'get_in_touch';
                }
                $shownFrom = $this->displaySender($category, $sum['from'], $this->addresses($header->get('reply_to')));
                $rows[] = [
                    'chat_id' => $chatId,
                    'chat_checked' => $chatId !== null,
                    'account' => $this->account(),
                    'folder' => $key,
                    'folder_path' => $path,
                    'uidvalidity' => $uidvalidity,
                    'uid' => $uid,
                    'message_id' => $mid !== null ? mb_substr($mid, 0, 191) : null,
                    'category' => $category,
                    'from_name' => mb_substr($shownFrom['name'], 0, 191),
                    'from_email' => mb_substr($shownFrom['email'], 0, 191),
                    'to' => json_encode($sum['to']),
                    'subject' => mb_substr($sum['subject'], 0, 500),
                    'sent_at' => $sum['date'] ? Carbon::parse($sum['date'])->utc()->toDateTimeString() : null,
                    'seen' => $sum['seen'],
                    'flagged' => $sum['flagged'],
                    'answered' => $sum['answered'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($rows !== []) {
                MailboxMessage::query()->insertOrIgnore($rows);
            }
        }
    }

    /**
     * uid => flags for emails whose flags changed after $modseq (CONDSTORE
     * CHANGEDSINCE) in the selected folder — usually a handful, not thousands.
     *
     * @return array<int, array<int, string>>
     */
    private function changedFlagsSince(int $modseq): array
    {
        $conn = $this->client()->getConnection();
        $response = $conn->requestAndResponse(
            $conn->buildUIDCommand('FETCH', IMAP::ST_UID),
            ['1:*', '(UID FLAGS)', '(CHANGEDSINCE '.$modseq.')'],
        );
        $out = [];
        foreach ((array) $response->data() as $line) {
            if (($line[1] ?? null) !== 'FETCH' || ! is_array($line[2] ?? null)) {
                continue;
            }
            $items = $line[2];
            $uid = null;
            $flags = [];
            for ($i = 0; $i + 1 < count($items); $i += 2) {
                $name = strtoupper((string) $items[$i]);
                if ($name === 'UID') {
                    $uid = (int) $items[$i + 1];
                } elseif ($name === 'FLAGS') {
                    $flags = (array) $items[$i + 1];
                }
            }
            if ($uid) {
                $out[$uid] = $flags;
            }
        }

        return $out;
    }

    /** Write server flags onto indexed rows (except very recent dashboard clicks). */
    private function applyFlags($base, array $flagsByUid): int
    {
        if ($flagsByUid === []) {
            return 0;
        }
        $updated = 0;
        $hold = now()->subMinutes(2); // don't undo a dashboard click the server hasn't applied yet
        foreach (array_chunk(array_keys($flagsByUid), 1000) as $chunk) {
            $rows = (clone $base)->whereIn('uid', $chunk)->get(['id', 'uid', 'seen', 'flagged', 'answered', 'local_change_at']);
            foreach ($rows as $row) {
                if ($row->local_change_at && $row->local_change_at->gt($hold)) {
                    continue;
                }
                $f = array_map(fn ($x) => strtolower(ltrim((string) $x, '\\')), (array) ($flagsByUid[$row->uid] ?? []));
                $new = ['seen' => in_array('seen', $f, true), 'flagged' => in_array('flagged', $f, true), 'answered' => in_array('answered', $f, true)];
                if ($new['seen'] !== $row->seen || $new['flagged'] !== $row->flagged || $new['answered'] !== $row->answered) {
                    MailboxMessage::query()->whereKey($row->id)->update($new + ['local_change_at' => null]);
                    $updated++;
                }
            }
        }

        return $updated;
    }

    /** Categories whose emails belong to an inquiry chat (they carry an /inbox/{slug}-{chatId} link). */
    private const CHAT_CATEGORIES = ['listing', 'assigned', 'agent_profile', 'website_builder', 'messages'];

    /**
     * Older inquiry emails have no X-FH-Chat-Id header: read each one ONCE
     * (BODY.PEEK[] — leaves read/unread alone) just to find its
     * /inbox/{slug}-{chatId} link, and keep only that number (not the email).
     * Time-boxed per run; newest first; continues next run until done.
     */
    /**
     * One-time catch-up: link ALL older inquiry emails now instead of 20 s per
     * sync (`php artisan mailbox:sync --link-all`). Returns how many were linked.
     */
    public function linkAllInquiryEmails(?callable $progress = null): int
    {
        $total = 0;
        $lock = Cache::lock($this->ck('sync-lock'), 3600);
        if (! $lock->get()) {
            return 0;
        }
        try {
            do {
                $remaining = MailboxMessage::query()->where('account', $this->account())->where('is_primary', true)
                    ->whereIn('category', self::CHAT_CATEGORIES)->where('chat_checked', false)->whereNull('archived_at')->count();
                if ($remaining === 0) {
                    break;
                }
                $total += $this->withRetry(fn () => $this->linkInquiryEmails(60.0));
                $progress && $progress($total, $remaining);
            } while (true);
        } finally {
            $lock->release();
        }

        return $total;
    }

    private function linkInquiryEmails(float $seconds = 20.0): int
    {
        $started = microtime(true);
        $rows = MailboxMessage::query()->where('account', $this->account())->where('is_primary', true)
            ->whereIn('category', self::CHAT_CATEGORIES)->where('chat_checked', false)->whereNull('archived_at')
            ->orderByDesc('uid')->limit(200)->get(['id', 'folder_path', 'uid', 'message_id']);

        $linked = 0;
        $config = $this->client()->getConfig();
        foreach ($rows->groupBy('folder_path') as $path => $group) {
            $this->client()->openFolder((string) $path);
            foreach ($group as $row) {
                if (microtime(true) - $started > $seconds) {
                    break 2;
                }
                $uid = (int) $row->uid;
                $chatId = null;
                try {
                    $raw = $this->client()->getConnection()->fetch(['UID', 'BODY.PEEK[]'], [$uid], null, IMAP::ST_UID)->validatedData();
                    $blob = (string) ($raw[$uid]['BODY[]'] ?? '');
                    if ($blob !== '') {
                        $msg = Message::fromString($blob, $config);
                        $text = $msg->getHTMLBody().' '.$msg->getTextBody();
                        if (preg_match('#/inbox/(?:[a-z0-9-]*-|chat-)(\d+)(?=["\'\s<>?/]|$)#i', $text, $m)) {
                            $chatId = (int) $m[1];
                        }
                    }
                } catch (\Throwable) {
                    // unreadable → leave unlinked
                }
                // The email and its hidden copies share the link.
                MailboxMessage::query()->where('account', $this->account())->where('folder_path', $path)
                    ->where(fn ($w) => $w->whereKey($row->id)->when($row->message_id, fn ($x) => $x->orWhere('message_id', $row->message_id)))
                    ->update(['chat_id' => $chatId, 'chat_checked' => true]);
                $linked += $chatId ? 1 : 0;
            }
        }

        return $linked;
    }

    /**
     * Live approval status per inquiry: latest conversation status by chat.
     *
     * @param  int[]  $chatIds
     * @return array<int, string> chatId => pending|accepted|rejected|closed|active
     */
    private function inquiryStatuses(array $chatIds): array
    {
        $chatIds = array_values(array_unique(array_filter($chatIds)));
        if ($chatIds === []) {
            return [];
        }
        $out = [];
        foreach (Conversation::query()->whereIn('chat_id', $chatIds)->orderBy('id')->get(['chat_id', 'status']) as $c) {
            $out[(int) $c->chat_id] = (string) $c->status; // last (newest) wins
        }

        return $out;
    }

    /** Website-form emails: sent BY the website (From: info@) on the client's behalf, client in Reply-To. */
    private const FORM_CATEGORIES = ['contact_us', 'get_in_touch', 'maintenance'];

    /**
     * Who the Mailbox list shows as the sender. For form emails that's the
     * client (Reply-To) instead of "Filipinohomes <info@…>". Display only —
     * the email itself, its From and its Reply-To are unchanged.
     *
     * @param  array{name:string,email:string}  $from
     * @param  array<int, array{name:string,email:string}>  $replyTo
     * @return array{name:string,email:string}
     */
    private function displaySender(?string $category, array $from, array $replyTo): array
    {
        if (in_array($category, self::FORM_CATEGORIES, true) && ($replyTo[0]['email'] ?? '') !== '') {
            return $replyTo[0];
        }

        return $from;
    }

    /** Form emails indexed before displaySender() existed: show the client too (one header fetch each, once). */
    private function fixFormSenders(string $path): int
    {
        $rows = MailboxMessage::query()->where('account', $this->account())->where('folder_path', $path)
            ->whereIn('category', self::FORM_CATEGORIES)->where('from_email', strtolower($this->address()))
            ->whereNull('archived_at')->get(['id', 'uid', 'category']);
        if ($rows->isEmpty()) {
            return 0;
        }
        $this->client()->openFolder($path);
        $config = $this->client()->getConfig();
        $fixed = 0;
        foreach ($rows->chunk(100) as $chunk) {
            $headers = $this->client()->getConnection()->headers($chunk->pluck('uid')->map(fn ($u) => (int) $u)->all(), 'RFC822', IMAP::ST_UID)->validatedData();
            foreach ($chunk as $row) {
                if (! isset($headers[(int) $row->uid])) {
                    continue;
                }
                $replyTo = $this->addresses((new Header($headers[(int) $row->uid], $config))->get('reply_to'));
                if (($replyTo[0]['email'] ?? '') !== '') {
                    MailboxMessage::query()->whereKey($row->id)->update([
                        'from_name' => mb_substr($replyTo[0]['name'], 0, 191),
                        'from_email' => mb_substr($replyTo[0]['email'], 0, 191),
                    ]);
                    $fixed++;
                }
            }
        }

        return $fixed;
    }

    /**
     * File a sent email (raw MIME) in the mailbox's Sent folder — SMTP alone
     * never does that — then pick it up in the Mailbox. Never throws: the mail
     * already went out, only the copy is at stake.
     */
    public function appendToSent(string $raw): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }
        try {
            if (! $this->hasFolder('sent')) {
                return false;
            }
            $this->folder('sent')->appendMessage($raw, ['\\Seen'], Carbon::now());
            if ($this->indexReady()) {
                app()->terminating(fn () => rescue(fn () => $this->sync(['sent']), report: false));
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Mailbox: could not file copy in Sent', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /** Form category → Inquiry.source (Contact Inbox). Old Get In Touch rows may have no source. */
    private const FORM_SOURCES = [
        'contact_us' => ['contact_page'],
        'get_in_touch' => ['home_get_in_touch', null],
        'maintenance' => ['maintenance_page'],
    ];

    /** The Contact Inbox submission a form email belongs to (client email + time + form). */
    private function inquiryForFormEmail(string $category, string $clientEmail, ?Carbon $sentAt): ?Inquiry
    {
        $q = Inquiry::query()->where('email', $clientEmail);
        $sources = self::FORM_SOURCES[$category] ?? null;
        if ($sources !== null) {
            $q->where(fn ($w) => $w->whereIn('source', array_filter($sources))->when(in_array(null, $sources, true), fn ($x) => $x->orWhereNull('source')));
        }
        if ($sentAt) {
            $q->where('created_at', '<=', $sentAt->copy()->addMinutes(10));
        }

        return $q->orderByDesc('created_at')->first() ?? Inquiry::query()->where('email', $clientEmail)->orderByDesc('created_at')->first();
    }

    /** Mailbox reply to a form email → also an InquiryReply in that submission's Contact Inbox thread. */
    private function recordFormReply(Message $original, string $subject, string $body, ?int $userId): void
    {
        try {
            $category = $this->categoryForSubject($this->headerString($original->getSubject()));
            if (! in_array($category, self::FORM_CATEGORIES, true) || $userId === null) {
                return;
            }
            $client = $this->addresses($original->getReplyTo())[0]['email'] ?? '';
            if ($client === '') {
                return;
            }
            $date = $original->getDate()?->first();
            $inquiry = $this->inquiryForFormEmail($category, $client, $date instanceof Carbon ? $date : null);
            if ($inquiry === null) {
                return;
            }
            InquiryReply::create([
                'inquiry_id' => $inquiry->id,
                'admin_user_id' => $userId,
                'subject' => mb_substr($subject, 0, 255),
                'body' => $body,
                'attachments' => [],
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Mailbox: could not record the reply in the Contact Inbox', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Clients replying to a form email or to our reply: after tag_required_from
     * the subject rule alone would hide them (a client's email has no website
     * tag). Accept a reply if it's from an email address that submitted a form
     * (Contact Inbox), and its subject is about that form or our reply.
     *
     * @return int[]
     */
    private function clientFormReplyUids(): array
    {
        $tagFrom = Carbon::parse((string) config('mailbox.tag_required_from'))->format('j-M-Y');
        $uids = $this->liveSearch('SINCE '.$tagFrom.' NOT HEADER X-FH-Mailer "" OR OR SUBJECT "Contact Us" SUBJECT "Get In Touch" SUBJECT "your message"');
        if ($uids === []) {
            return [];
        }
        $config = $this->client()->getConfig();
        $fromByUid = [];
        foreach (array_chunk($uids, 200) as $chunk) {
            foreach ($this->client()->getConnection()->headers($chunk, 'RFC822', IMAP::ST_UID)->validatedData() as $uid => $raw) {
                $fromByUid[(int) $uid] = strtolower($this->addresses((new Header($raw, $config))->get('from'))[0]['email'] ?? '');
            }
        }
        $known = array_flip(array_map('strtolower', Inquiry::query()
            ->whereIn('email', array_values(array_filter(array_unique($fromByUid))))->pluck('email')->all()));

        return array_keys(array_filter($fromByUid, fn ($email) => $email !== '' && isset($known[$email])));
    }

    /** Newest copy per Message-ID is the one shown; the rest are hidden copies. */
    private function markPrimaries(string $path): void
    {
        $rows = MailboxMessage::query()->where('account', $this->account())->where('folder_path', $path)
            ->orderByDesc('uid')->get(['id', 'message_id', 'is_primary']);
        $seen = [];
        $primary = [];
        $copy = [];
        foreach ($rows as $row) {
            $id = (string) $row->message_id;
            $isPrimary = $id === '' || ! isset($seen[$id]);
            $seen[$id] = true;
            if ($isPrimary !== $row->is_primary) {
                $isPrimary ? $primary[] = $row->id : $copy[] = $row->id;
            }
        }
        foreach (array_chunk($primary, 1000) as $c) {
            MailboxMessage::query()->whereIn('id', $c)->update(['is_primary' => true]);
        }
        foreach (array_chunk($copy, 1000) as $c) {
            MailboxMessage::query()->whereIn('id', $c)->update(['is_primary' => false]);
        }
    }

    /** Same rule as the server search: the first category whose subject phrase appears. */
    private function categoryForSubject(string $subject): ?string
    {
        $s = mb_strtolower($subject);
        foreach (self::CATEGORIES as $key => $def) {
            foreach ($def['subjects'] as $phrase) {
                if (str_contains($s, mb_strtolower($phrase))) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function indexQuery(string $folderKey)
    {
        return MailboxMessage::query()->where('account', $this->account())->where('folder', $folderKey);
    }

    /** @return array{data: array<int, array<string, mixed>>, total:int, page:int, per_page:int} */
    /** Approval filters in the Mailbox → inquiry (conversation) statuses. */
    public const INQUIRY_STATUS_FILTERS = [
        'pending' => ['pending'],                         // waiting for approval
        'rejected' => ['rejected'],
        'forwarded' => ['accepted', 'active', 'closed'],  // approved → sent to the agent (even if later closed)
        'open' => ['accepted', 'active'],                 // conversation still open
        'closed' => ['closed'],
    ];

    private function listFromIndex(string $key, int $page, int $perPage, ?string $search, ?string $category, ?string $status = null): array
    {
        $q = $this->indexQuery($key === 'starred' ? 'inbox' : $key)->where('is_primary', true);
        if ($key === 'starred') {
            $q->where('flagged', true);
        }
        $category ??= 'allowed';
        if ($category === 'website') {
            $q->whereIn('category', array_keys(self::CATEGORIES));
        } elseif ($category !== 'allowed') {
            $q->where('category', $category);
        }
        $search = $this->cleanSearch($search);
        if ($search !== null) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $q->where(fn ($w) => $w->where('subject', 'like', $like)
                ->orWhere('from_email', 'like', $like)
                ->orWhere('from_name', 'like', $like)
                ->orWhere('to', 'like', $like));
        }

        // Live approval counts for this view (before the status filter), from the
        // inquiries' current status — e.g. how many New Inquiries still wait for approval.
        $chatIds = (clone $q)->whereNotNull('chat_id')->pluck('chat_id')->map(fn ($v) => (int) $v)->all();
        $allStatuses = $this->inquiryStatuses($chatIds);
        $statusCounts = array_fill_keys(array_keys(self::INQUIRY_STATUS_FILTERS), 0);
        foreach ($chatIds as $cid) {
            foreach (self::INQUIRY_STATUS_FILTERS as $filter => $values) {
                if (in_array($allStatuses[$cid] ?? null, $values, true)) {
                    $statusCounts[$filter]++;
                }
            }
        }
        // Linked to an inquiry this database doesn't have (e.g. production data seen locally).
        $statusCounts['unknown'] = count(array_filter($chatIds, fn ($cid) => ! isset($allStatuses[$cid])));
        // Not linked to an inquiry yet (older emails are linked in the background).
        $statusCounts['unlinked'] = (clone $q)->whereNull('chat_id')->count();

        $statusTotal = (clone $q)->count(); // the "All" chip: this view before any status filter

        if ($status !== null && isset(self::INQUIRY_STATUS_FILTERS[$status])) {
            $want = self::INQUIRY_STATUS_FILTERS[$status];
            $ids = array_keys(array_filter($allStatuses, fn ($st) => in_array($st, $want, true)));
            $q->whereIn('chat_id', $ids ?: [0]);
        }

        $total = (clone $q)->count();
        $page = max(1, $page);
        $rows = $q->orderByDesc('uid')->forPage($page, $perPage)->get();
        $statuses = $allStatuses;

        return [
            'data' => $rows->map(fn (MailboxMessage $m) => $this->rowSummary($m) + [
                // Live approval status of the inquiry this email is about (null when not linked).
                'inquiry' => $m->chat_id ? ['chat_id' => (int) $m->chat_id, 'status' => $statuses[(int) $m->chat_id] ?? null] : null,
            ])->all(),
            // Only meaningful for inquiry categories (emails linked to an inquiry).
            'status_counts' => $chatIds !== [] ? $statusCounts : null,
            'status_total' => $statusTotal,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** @return array<string, mixed> same shape as summaryFromHeader() */
    private function rowSummary(MailboxMessage $m): array
    {
        return [
            'uid' => (int) $m->uid,
            'from' => ['name' => (string) $m->from_name, 'email' => (string) $m->from_email],
            'to' => $m->to ?? [],
            'subject' => (string) $m->subject,
            'date' => $m->sent_at?->toIso8601String(),
            'seen' => (bool) $m->seen,
            'flagged' => (bool) $m->flagged,
            'answered' => (bool) $m->answered,
            'archived' => $m->archived_at !== null, // lives in the S3 archive, not on the mail server
        ];
    }

    /** @return array<int, array{key:string,label:string,total:int,unread:int}> */
    private function categoriesFromIndex(): array
    {
        $rows = $this->indexQuery('inbox')->where('is_primary', true)
            ->selectRaw('category, count(*) as total, sum(case when seen then 0 else 1 end) as unread')
            ->groupBy('category')->get()->keyBy('category')->toBase(); // plain collection: only() by category key

        $out = [];
        foreach (self::categoryKeys() as $key) {
            if ($key === 'website') {
                $set = $rows->only(array_keys(self::CATEGORIES));
                $total = (int) $set->sum('total');
                $unread = (int) $set->sum('unread');
            } else {
                $total = (int) ($rows[$key]->total ?? 0);
                $unread = (int) ($rows[$key]->unread ?? 0);
            }
            $out[] = [
                'key' => $key,
                'label' => $key === 'website' ? 'All website inquiries' : self::CATEGORIES[$key]['label'],
                'total' => $total,
                'unread' => $unread,
            ];
        }

        return $out;
    }

    /** Rows for these UIDs plus their hidden duplicate copies, in one folder. */
    private function indexRowsWithCopies(string $path, array $uids)
    {
        $base = MailboxMessage::query()->where('account', $this->account())->where('folder_path', $path);
        $mids = (clone $base)->whereIn('uid', $uids)->whereNotNull('message_id')->pluck('message_id')->all();

        return (clone $base)->where(fn ($w) => $w->whereIn('uid', $uids)->orWhereIn('message_id', $mids ?: ['']));
    }

    // ── Reading ──────────────────────────────────────────────────────────

    /**
     * One page of a folder, newest first. Headers only (no bodies), so a page
     * of mail with big attachments still loads quickly.
     *
     * @return array{data: array<int, array<string, mixed>>, total:int, page:int, per_page:int}
     */
    public function list(string $key, int $page = 1, int $perPage = 50, ?string $search = null, ?string $category = null, ?string $status = null): array
    {
        if ($this->indexReady()) {
            return $this->listFromIndex($key, $page, $perPage, $search, $category, $status);
        }
        $folder = $this->folder($key);

        return $this->withRetry(function () use ($folder, $key, $page, $perPage, $search, $category) {
            $conn = $this->client()->getConnection();
            $this->client()->openFolder($folder->path);

            // Raw IMAP instead of the library's Message objects: building one
            // Message costs ~2 s of round-trips on Hostinger, so a page of 50 took
            // 100 s. SEARCH + one FLAGS + one header FETCH for the page is ~1 s.
            // Website inquiries only, in every folder: other mail is never listed.
            $criteria = ($key === 'starred' ? 'FLAGGED' : 'ALL').' '.$this->scoped($this->categoryCriteria($category ?? 'allowed'));
            $search = $this->cleanSearch($search);
            if ($search !== null) {
                // Header-only search (fast everywhere); TEXT/BODY can take seconds on 30k+ mails.
                $s = '"'.$search.'"';
                $criteria .= " OR FROM {$s} OR TO {$s} SUBJECT {$s}";
            }

            // The plain category list only changes when mail arrives/moves, so it's
            // cached per folder state; a free-text search or Starred (flags) runs live.
            $uids = ($search === null && $key !== 'starred')
                ? $this->cachedSearch($folder->path, $criteria)
                : $this->liveSearch($criteria);
            $uids = $this->withoutCopies($folder->path, $uids); // one row per email, not per copy
            $total = count($uids);
            $page = max(1, $page);
            $pageUids = array_slice($uids, ($page - 1) * $perPage, $perPage);

            $data = [];
            if ($pageUids !== []) {
                $flags = $conn->flags($pageUids, IMAP::ST_UID)->validatedData();
                $headers = $conn->headers($pageUids, 'RFC822', IMAP::ST_UID)->validatedData();
                $config = $this->client()->getConfig();

                foreach ($pageUids as $uid) {
                    if (! isset($headers[$uid])) {
                        continue;
                    }
                    try {
                        $header = new Header($headers[$uid], $config);
                    } catch (\Throwable $e) {
                        Log::warning('Mailbox: unreadable header', ['uid' => $uid, 'error' => $e->getMessage()]);

                        continue;
                    }
                    $data[] = $this->summaryFromHeader($uid, $header, $flags[$uid] ?? []);
                }
            }

            return [
                'data' => $data,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
            ];
        });
    }

    /**
     * Tab counts for the inbox: total and unread per website category.
     * Cached a minute so tab switches are free.
     *
     * @return array<int, array{key:string,label:string,total:int,unread:int}>
     */
    public function categories(): array
    {
        if ($this->indexReady()) {
            return $this->categoriesFromIndex();
        }

        return Cache::remember($this->ck('categories:v8'), 60, function () {
            $folder = $this->folder('inbox');

            return $this->withRetry(function () use ($folder) {
                $this->client()->openFolder($folder->path);
                // Totals come from cached per-category searches (they only change
                // when mail arrives/moves); unread is ONE live UNSEEN search,
                // intersected in PHP — instead of 16 server searches.
                $unseen = array_flip($this->liveSearch('UNSEEN '.$this->websiteCriteria()));

                $out = [];
                foreach (self::categoryKeys() as $key) {
                    $uids = $this->withoutCopies(
                        $folder->path,
                        $this->cachedSearch($folder->path, 'ALL '.$this->scoped($this->categoryCriteria($key))),
                    );
                    $out[] = [
                        'key' => $key,
                        'label' => $key === 'website' ? 'All website inquiries' : self::CATEGORIES[$key]['label'],
                        'total' => count($uids),
                        'unread' => count(array_filter($uids, fn ($u) => isset($unseen[$u]))),
                    ];
                }

                return $out;
            });
        });
    }

    public function forgetCategoryCounts(): void
    {
        Cache::forget($this->ck('categories:v8'));
    }

    // ── Duplicate copies ─────────────────────────────────────────────────
    //
    // One email sent To info@ with info@ (or an address that forwards to it)
    // also in BCC is delivered several times: identical copies, same
    // Message-ID (one Contact Us submission arrived up to 6×). The list shows
    // each email once; actions apply to every copy.

    /**
     * uid => Message-ID (lower-case, '' when missing) for UIDs in the
     * selected folder. Read from IMAP ENVELOPE, which is slow in bulk (~2 ms
     * per mail), so it's cached per folder + UIDVALIDITY (a UID's message
     * never changes) and only UIDs not seen before are fetched.
     *
     * @param  int[]  $uids
     * @return array<int, string>
     */
    private function messageIds(string $path, array $uids): array
    {
        $conn = $this->client()->getConnection();
        $validity = (string) $this->folderState($path)['uidvalidity'];
        $cacheKey = $this->ck('mids:'.md5($path).':'.$validity);
        $map = Cache::get($cacheKey, []);

        $missing = array_values(array_diff($uids, array_keys($map)));
        foreach (array_chunk($missing, 1000) as $chunk) {
            $envelopes = $conn->fetch(['ENVELOPE'], $chunk, null, IMAP::ST_UID)->validatedData();
            foreach ($chunk as $uid) {
                $envelope = $envelopes[$uid] ?? null;
                $id = '';
                // Message-ID is the envelope's last field.
                if (is_array($envelope)) {
                    $last = end($envelope);
                    if (is_string($last) && preg_match('/<[^<>\s]+>/', $last, $m)) {
                        $id = strtolower($m[0]);
                    }
                }
                $map[$uid] = $id;
            }
        }
        if ($missing !== []) {
            Cache::forever($cacheKey, $map);
        }

        return array_intersect_key($map, array_flip($uids));
    }

    /**
     * Drop duplicate copies, keeping the first UID seen per Message-ID (pass
     * newest-first to keep the newest copy). Mail without a Message-ID stays.
     *
     * @param  int[]  $uids
     * @return int[]
     */
    private function withoutCopies(string $path, array $uids): array
    {
        if (count($uids) < 2) {
            return $uids;
        }
        $ids = $this->messageIds($path, $uids);
        $seen = [];
        $out = [];
        foreach ($uids as $uid) {
            $id = $ids[$uid] ?? '';
            if ($id !== '') {
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
            }
            $out[] = $uid;
        }

        return $out;
    }

    /**
     * The given UIDs plus every other website-mail copy of the same emails in
     * the selected folder.
     *
     * @param  int[]  $uids
     * @return int[]
     */
    private function withCopies(string $path, array $uids): array
    {
        if ($this->indexReady()) {
            return array_values(array_unique(array_merge(
                $uids,
                $this->indexRowsWithCopies($path, $uids)->pluck('uid')->map(fn ($u) => (int) $u)->all(),
            )));
        }
        $all = $this->allowedUids($path);
        $ids = $this->messageIds($path, array_values(array_unique(array_merge($all, $uids))));
        $wanted = array_filter(array_map(fn ($u) => $ids[$u] ?? '', $uids));
        if ($wanted === []) {
            return $uids;
        }
        $wanted = array_flip($wanted);
        $copies = array_keys(array_filter($ids, fn ($id) => $id !== '' && isset($wanted[$id])));

        return array_values(array_unique(array_merge($uids, $copies)));
    }

    private function websiteCriteria(): string
    {
        return $this->scoped($this->categoryCriteria('allowed'));
    }

    /**
     * Narrow a subject match to genuine website mail, by arrival date:
     *   before mailbox.since              → hidden
     *   since … until tag_required_from   → the subject is enough
     *   from tag_required_from on          → must also carry X-FH-Mailer, the
     *                                        header every Mailable in this app
     *                                        stamps (TagsFhMailerHeader). Stops
     *                                        outside mail that copies a title.
     * IMAP SINCE/BEFORE compare the day the server received the message.
     */
    private function scoped(string $expr): string
    {
        $since = Carbon::parse((string) config('mailbox.since'))->format('j-M-Y');
        $tagFrom = Carbon::parse((string) config('mailbox.tag_required_from'))->format('j-M-Y');

        return $expr.' SINCE '.$since.' OR BEFORE '.$tagFrom.' HEADER X-FH-Mailer ""';
    }

    /**
     * The dashboard only ever touches website inquiries. Before reading or
     * changing messages, confirm every UID matches the website criteria in the
     * currently selected folder; anything else is "not found" — even when the
     * UID exists — so other mail can't be opened through the API.
     */
    private function assertWebsiteMessages(string $path, array $uids): void
    {
        $uids = $this->cleanUids($uids);
        if ($uids === []) {
            return;
        }
        if ($this->indexReady()) {
            $found = MailboxMessage::query()->where('account', $this->account())->where('folder_path', $path)
                ->whereIn('uid', $uids)->count();
            abort_unless($found === count($uids), 404, 'Message not found.');

            return;
        }
        $allowed = array_flip($this->allowedUids($path));
        foreach ($uids as $uid) {
            abort_unless(isset($allowed[$uid]), 404, 'Message not found.');
        }
    }

    // ── Speed: fewer round-trips to the mail server ──────────────────────
    //
    // Every IMAP command is a round-trip to Hostinger (~0.25 s from here), and
    // the website searches don't change unless mail arrives or moves. So:
    // searches are cached per folder state (UIDVALIDITY + UIDNEXT + MESSAGES —
    // any new, moved or deleted mail changes it), and the state itself is read
    // once per request.

    /** @return array{messages:int, uidnext:int, uidvalidity:int, modseq:int} */
    private function folderState(string $path): array
    {
        if (! isset($this->folderStates[$path])) {
            $conn = $this->client()->getConnection();
            $status = fn () => $conn->folderStatus($path, ['MESSAGES', 'UIDNEXT', 'UIDVALIDITY', 'HIGHESTMODSEQ'])->validatedData();
            try {
                $st = $status();
            } catch (\Throwable) {
                // STATUS on the open folder sometimes comes back unparsable (pending
                // untagged updates). Flush them with a NOOP and ask again; failing
                // that, read the same numbers from EXAMINE and re-open the folder
                // read-write (no change counter then — the sync just does a full check).
                try {
                    $conn->noop();
                    $st = $status();
                } catch (\Throwable) {
                    $ex = $conn->examineFolder($path)->validatedData();
                    $st = ['messages' => $ex['exists'] ?? 0, 'uidnext' => $ex['uidnext'] ?? 0, 'uidvalidity' => $ex['uidvalidity'] ?? 0];
                    $this->client()->openFolder($path, true);
                }
            }
            $this->folderStates[$path] = [
                'messages' => (int) ($st['messages'] ?? 0),
                'uidnext' => (int) ($st['uidnext'] ?? 0),
                'uidvalidity' => (int) ($st['uidvalidity'] ?? 0),
                // CONDSTORE change counter: bumps on ANY change (new mail, read, star, delete).
                'modseq' => (int) ($st['highestmodseq'] ?? 0),
            ];
        }

        return $this->folderStates[$path];
    }

    /** Forget the memoized state after this request moved/deleted mail. */
    private function folderChanged(string $path): void
    {
        unset($this->folderStates[$path]);
    }

    /**
     * UIDs (newest first) for a criteria in the selected folder, cached until
     * the folder changes. Only for criteria that don't depend on flags.
     *
     * @return int[]
     */
    private function cachedSearch(string $path, string $criteria): array
    {
        $st = $this->folderState($path);
        $key = $this->ck('s:'.md5($path.'|'.$criteria).':'.$st['uidvalidity'].':'.$st['uidnext'].':'.$st['messages']);

        return Cache::remember($key, 900, fn () => $this->liveSearch($criteria));
    }

    /** @return int[] newest first */
    private function liveSearch(string $criteria): array
    {
        $uids = array_map('intval', $this->client()->getConnection()->search([$criteria], IMAP::ST_UID)->validatedData());
        rsort($uids, SORT_NUMERIC);

        return $uids;
    }

    /** Every website-mail UID (inquiries + reports) in the selected folder. @return int[] */
    private function allowedUids(string $path): array
    {
        return $this->cachedSearch($path, 'ALL '.$this->websiteCriteria());
    }

    /** One UID STORE for many messages (instead of one command per message). */
    private function storeMany(array $uids, string $imapFlag, bool $on): void
    {
        $uids = $this->cleanUids($uids);
        if ($uids === []) {
            return;
        }
        $conn = $this->client()->getConnection();
        $conn->requestAndResponse(
            $conn->buildUIDCommand('STORE', IMAP::ST_UID),
            [implode(',', $uids), ($on ? '+' : '-').'FLAGS.SILENT', $conn->escapeList([$imapFlag])],
            true,
        );
    }

    /** IMAP search expression matching a category's subjects (OR-chained, prefix form). */
    private function categoryCriteria(string $category): string
    {
        $subjects = match (true) {
            // Main Inbox: every website inquiry (not reports).
            $category === 'website' => array_merge(...array_column(self::CATEGORIES, 'subjects')),
            // Everything the dashboard may show or touch: inquiries + reports.
            $category === 'allowed' => array_merge(...array_column(self::CATEGORIES, 'subjects')),
            default => self::CATEGORIES[$category]['subjects'] ?? null,
        };
        abort_if($subjects === null, 422, 'Unknown category.');

        $terms = array_map(fn ($s) => 'SUBJECT "'.$s.'"', $subjects);
        if ($category === 'allowed') {
            // Replies we send (Mailbox / Contact Inbox) carry our tag: show them (e.g. in Sent).
            $terms[] = 'HEADER X-FH-Mailer "MailboxReply"';
            $terms[] = 'HEADER X-FH-Mailer "InquiryReplyMailer"';
        }
        // IMAP OR takes exactly two keys: OR a OR b c …
        $expr = array_pop($terms);
        while ($terms !== []) {
            $expr = 'OR '.array_pop($terms).' '.$expr;
        }

        return $expr;
    }

    /**
     * A full message. Marks it read, as opening it in any mail client does.
     *
     * @return array<string, mixed>
     */
    public function show(string $key, int $uid): array
    {
        // Indexed + opened before: serve the cached view, no mail server round-trip.
        if ($this->indexReady()) {
            $row = $this->indexQuery($key === 'starred' ? 'inbox' : $key)->where('uid', $uid)->first();
            abort_if($row === null, 404, 'Message not found.');
            if (is_array($row->detail)) {
                $this->setFlag($key, [$uid], 'seen', true);

                return array_merge($this->rowSummary($row), $row->detail, ['seen' => true]);
            }
        }

        [$message, $rawFlags] = $this->fetchMessage($key, $uid);

        // Fetching the full message already marks THIS copy read on the server.
        // Copies + counts: with the index, the database is updated now and the
        // server after the response; without it, all of it after the response.
        if ($this->indexReady()) {
            $this->setFlag($key, [$uid], 'seen', true);
        } else {
            app()->terminating(function () use ($key, $uid) {
                try {
                    $this->setFlag($key, [$uid], 'seen', true);
                    $this->forgetCategoryCounts();
                } catch (\Throwable $e) {
                    Log::warning('Mailbox: mark seen failed', ['uid' => $uid, 'error' => $e->getMessage()]);
                }
            });
        }

        $detail = $this->buildDetail($message);

        // Keep the opened view for next time (skip huge ones, e.g. big inline images).
        if ($this->indexReady() && strlen((string) json_encode($detail)) < 2_000_000) {
            $this->indexQuery($key === 'starred' ? 'inbox' : $key)->where('uid', $uid)->update(['detail' => json_encode($detail)]);
        }

        return array_merge($this->summaryFromHeader($uid, $message->getHeader(), $rawFlags), $detail, [
            'seen' => true,
            'archived' => $this->archivedRow($this->folderPath($key), $uid) !== null,
        ]);
    }

    /** The opened-email view (everything but the summary fields). @return array<string, mixed> */
    private function buildDetail(Message $message): array
    {
        $attachments = [];
        foreach ($message->getAttachments()->values() as $i => $att) {
            $attachments[] = [
                'index' => $i,
                'name' => $this->attachmentName($att, $i),
                'mime' => (string) ($att->getMimeType() ?: $att->content_type ?: 'application/octet-stream'),
                'size' => (int) ($att->size ?? strlen((string) $att->content)),
                'inline' => $this->isInline($att),
            ];
        }

        return [
            'to' => $this->addresses($message->getTo()),
            'cc' => $this->addresses($message->getCc()),
            'reply_to' => $this->addresses($message->getReplyTo()),
            'message_id' => $this->headerString($message->getMessageId()),
            'html' => $this->renderableHtml($message),
            'attachments' => $attachments,
        ];
    }

    /**
     * @return array{name:string, mime:string, content:string}
     */
    public function attachment(string $key, int $uid, int $index): array
    {
        [$message] = $this->fetchMessage($key, $uid);
        $att = $message->getAttachments()->values()->get($index);
        abort_if($att === null, 404, 'Attachment not found.');

        return [
            'name' => $this->attachmentName($att, $index),
            'mime' => (string) ($att->getMimeType() ?: 'application/octet-stream'),
            'content' => (string) $att->content,
        ];
    }

    // ── Changing ─────────────────────────────────────────────────────────

    /** Set or clear \Seen or \Flagged on several messages. */
    public function setFlag(string $key, array $uids, string $flag, bool $on): void
    {
        $imapFlag = ['seen' => '\\Seen', 'flagged' => '\\Flagged'][$flag] ?? null;
        abort_if($imapFlag === null, 422, 'Unknown flag.');

        if ($this->indexReady()) {
            // Database first (instant for every viewer), the mail server after the response.
            $uids = $this->cleanUids($uids);
            $path = $this->folderPath($key); // cached map — no login needed
            $found = MailboxMessage::query()->where('account', $this->account())->where('folder_path', $path)->whereIn('uid', $uids)->count();
            abort_unless($found === count($uids), 404, 'Message not found.');
            $this->indexRowsWithCopies($path, $uids)->update([$flag => $on, 'local_change_at' => now()]);
            $this->forgetCategoryCounts();
            app()->terminating(function () use ($key, $uids, $imapFlag, $on) {
                try {
                    $this->storeFlags($key, $uids, $imapFlag, $on);
                } catch (\Throwable $e) {
                    Log::warning('Mailbox: flag sync to server failed', ['uids' => $uids, 'error' => $e->getMessage()]);
                }
            });

            return;
        }

        $this->storeFlags($key, $uids, $imapFlag, $on);
    }

    private function storeFlags(string $key, array $uids, string $imapFlag, bool $on): void
    {
        // Archived emails have no server copy: their read/star lives in the database only.
        $uids = $this->cleanUids($uids);
        $uids = array_values(array_diff($uids, $this->archivedUids($this->folderPath($key), $uids)));
        if ($uids === []) {
            return;
        }
        $folder = $this->folder($key);
        $this->withRetry(function () use ($folder, $uids, $imapFlag, $on) {
            $this->client()->openFolder($folder->path);
            $this->assertWebsiteMessages($folder->path, $uids);
            // Read / star the hidden duplicate copies too, so counts stay right — one command for all.
            $this->storeMany($this->withCopies($folder->path, $uids), $imapFlag, $on);
        });
    }

    /** Move messages to another folder (spam, inbox, archive, trash…). */
    public function move(string $key, array $uids, string $targetKey): void
    {
        $uids = $this->cleanUids($uids);
        abort_if($this->archivedUids($this->folderPath($key), $uids) !== [], 422, 'Archived emails stay where they are — they are stored in the archive, not on the mail server. Unselect them and try again.');

        $from = $this->folder($key);
        $to = $this->folder($targetKey);
        abort_if($targetKey === 'starred' || $from->path === $to->path, 422, 'Pick another folder.');

        if ($uids === []) {
            return;
        }
        $this->client()->openFolder($from->path);
        $this->assertWebsiteMessages($from->path, $uids);
        // Move the hidden duplicate copies along, or they'd pop up in the list.
        $uids = $this->withCopies($from->path, $uids);
        $this->client()->getConnection()->moveManyMessages($uids, $to->path, IMAP::ST_UID);
        $this->folderChanged($from->path);
        $this->folderChanged($to->path);
        $this->afterServerChange($from->path, $uids, [$key === 'starred' ? 'inbox' : $key, $targetKey]);
    }

    /** Drop moved/deleted rows from the index now; pick up their new place after the response. */
    private function afterServerChange(string $path, array $uids, array $resyncKeys): void
    {
        MailboxMessage::query()->where('account', $this->account())->where('folder_path', $path)->whereIn('uid', $uids)->delete();
        $this->forgetCategoryCounts();
        if ($this->indexReady()) {
            app()->terminating(function () use ($resyncKeys) {
                try {
                    $this->sync(array_values(array_unique($resyncKeys)));
                } catch (\Throwable $e) {
                    Log::warning('Mailbox: resync after move failed', ['error' => $e->getMessage()]);
                }
            });
        }
    }

    /**
     * Delete = move to Trash. Inside Trash (or when the server has no Trash)
     * it deletes for good, as webmail does.
     */
    public function delete(string $key, array $uids): void
    {
        $uids = $this->cleanUids($uids);
        if ($uids === []) {
            return;
        }

        if ($key !== 'trash' && $this->hasFolder('trash')) {
            $this->move($key, $uids, 'trash');

            return;
        }

        // The S3 archive is separate from the mail server: deleting here never
        // touches it (and clearing the archive in S3 never touches the mail server).
        abort_if($this->archivedUids($this->folderPath($key), $uids) !== [], 422, 'Archived emails are kept in the S3 archive and can\'t be deleted from the Mailbox. Unselect them and try again.');

        $folder = $this->folder($key);
        $this->client()->openFolder($folder->path);
        $this->assertWebsiteMessages($folder->path, $uids);
        $all = $this->withCopies($folder->path, $uids);
        $this->storeMany($all, '\\Deleted', true);
        $this->client()->expunge();
        $this->folderChanged($folder->path);
        $this->afterServerChange($folder->path, $all, [$key]);
    }

    // ── Sending ──────────────────────────────────────────────────────────

    /**
     * Send as info@ through the mailbox's own SMTP, then file a copy in Sent
     * (SMTP alone never does that; webmail and phones expect it).
     *
     * @param  array{to:array,cc?:array,bcc?:array,subject:string,body:string,mode:string,ref_folder?:?string,ref_uid?:?int}  $data
     * @param  UploadedFile[]  $files
     */
    public function send(array $data, array $files, string $senderName, ?int $userId = null): void
    {
        // Reply-only: every send answers a website inquiry (fetchMessage 404s on anything else).
        $mode = $data['mode'] ?? 'reply';
        abort_unless(in_array($mode, ['reply', 'reply_all'], true) && ! empty($data['ref_folder']) && ! empty($data['ref_uid']), 422, 'Only replies can be sent.');
        [$original] = $this->fetchMessage($data['ref_folder'], (int) $data['ref_uid']);

        $html = $this->composeHtml((string) $data['body'], $mode, $original);
        $text = $this->composeText((string) $data['body'], $mode, $original);

        $fromAddress = $this->address();
        $fromName = (string) config('mailbox.from_name', 'Filipino Homes');

        $sent = Mail::mailer('mailbox')->send([], [], function (MailMessage $m) use ($data, $files, $html, $text, $fromAddress, $fromName, $original, $mode) {
            $m->from($fromAddress, $fromName);
            $m->to($data['to']);
            if (! empty($data['cc'])) {
                $m->cc($data['cc']);
            }
            if (! empty($data['bcc'])) {
                $m->bcc($data['bcc']);
            }
            $m->subject($data['subject']);

            $symfony = $m->getSymfonyMessage();
            $symfony->html($html);
            $symfony->text($text);
            // Same stamp as every other app email, so the reply's copy in Sent
            // still counts as website mail after tag_required_from (see scoped()).
            $symfony->getHeaders()->addTextHeader('X-FH-Mailer', 'MailboxReply');

            foreach ($files as $file) {
                $m->attach($file->getRealPath(), [
                    'as' => $file->getClientOriginalName(),
                    'mime' => $file->getMimeType() ?: 'application/octet-stream',
                ]);
            }

            if ($original !== null && $mode === 'forward') {
                foreach ($original->getAttachments()->values() as $i => $att) {
                    if ($this->isInline($att)) {
                        continue;
                    }
                    $m->attachData((string) $att->content, $this->attachmentName($att, $i), [
                        'mime' => (string) ($att->getMimeType() ?: 'application/octet-stream'),
                    ]);
                }
            }

            if ($original !== null && $mode !== 'forward') {
                $origId = trim($this->headerString($original->getMessageId()), '<> ');
                if ($origId !== '') {
                    $refs = preg_split('/\s+/', trim($this->headerString($original->getReferences())), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $refs = array_values(array_unique(array_merge(array_map(fn ($r) => trim($r, '<>'), $refs), [$origId])));
                    $headers = $symfony->getHeaders();
                    $headers->addIdHeader('In-Reply-To', $origId);
                    $headers->addIdHeader('References', $refs);
                }
            }
        });

        if ($original !== null && $mode !== 'forward') {
            try {
                $this->storeFlags($data['ref_folder'], [(int) $data['ref_uid']], '\\Answered', true);
                // Archived originals keep "answered" in the database (no server copy).
                if ($this->archivedUids($path = $this->folderPath($data['ref_folder']), [(int) $data['ref_uid']]) !== []) {
                    MailboxMessage::query()->where('account', $this->account())->where('folder_path', $path)
                        ->where('uid', (int) $data['ref_uid'])->update(['answered' => true]);
                }
            } catch (\Throwable) {
                // cosmetic only
            }
        }

        if ($sent !== null) {
            $this->appendToSent($sent->toString());
        }

        // A reply to a website form (Contact Us / Get In Touch / Maintenance) also
        // goes into that submission's thread in the Contact Inbox.
        if ($original !== null && $mode !== 'forward') {
            $this->recordFormReply($original, (string) $data['subject'], (string) $data['body'], $userId);
        }

        Log::info('Mailbox: message sent', [
            'by' => $senderName,
            'mode' => $mode,
            'to' => $data['to'],
            'subject' => $data['subject'],
        ]);
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        abort_unless($this->isConfigured(), 503, 'Mailbox is not configured. Set MAILBOX_PASSWORD in the API .env.');

        $manager = new ClientManager([
            'options' => [
                'fetch' => IMAP::FT_PEEK,
                'fetch_order' => 'desc',
                'soft_fail' => true,
                'message_key' => 'uid',
                'sequence' => IMAP::ST_UID,
            ],
        ]);

        $this->client = $manager->make([
            'host' => config('mailbox.imap.host'),
            'port' => config('mailbox.imap.port'),
            'encryption' => config('mailbox.imap.encryption'),
            'validate_cert' => true,
            'username' => config('mailbox.username'),
            'password' => config('mailbox.password'),
            'protocol' => 'imap',
            'timeout' => config('mailbox.imap.timeout'),
        ]);
        $this->client->connect();

        return $this->client;
    }

    public function __destruct()
    {
        try {
            $this->client?->disconnect();
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * key → ['path' => server path, 'delimiter' => …]. Cached for an hour;
     * listing folders is a round-trip and they practically never change.
     *
     * @return array<string, array{path:string, delimiter:string}>
     */
    private function folderMap(): array
    {
        return Cache::remember($this->ck(self::FOLDER_CACHE_KEY), 3600, function () {
            $map = [];
            foreach ($this->client()->getFolders(false) as $folder) {
                /** @var Folder $folder */
                $entry = ['path' => $folder->path, 'delimiter' => $folder->delimiter];
                if (strtoupper($folder->path) === 'INBOX') {
                    $map['inbox'] = $entry;

                    continue;
                }
                $name = strtolower(trim($folder->name));
                foreach (self::SPECIAL as $key => $names) {
                    if (! isset($map[$key]) && in_array($name, $names, true)) {
                        $map[$key] = $entry;
                    }
                }
            }
            $map['inbox'] ??= ['path' => 'INBOX', 'delimiter' => '.'];

            return $map;
        });
    }

    /** Server path for a folder key from the cached map (no connection needed when cached). */
    private function folderPath(string $key): string
    {
        $map = $this->folderMap();
        $entry = $key === 'starred' ? $map['inbox'] : ($map[$key] ?? null);
        abort_if($entry === null, 404, 'Folder not found.');

        return $entry['path'];
    }

    private function folder(string $key): Folder
    {
        $map = $this->folderMap();
        $entry = $key === 'starred' ? $map['inbox'] : ($map[$key] ?? null);
        abort_if($entry === null, 404, 'Folder not found.');

        return new Folder($this->client(), $entry['path'], $entry['delimiter'], []);
    }

    /**
     * One full message, fetched raw in a single round-trip and parsed locally. The
     * library's getMessageByUid() makes several round-trips per message
     * (2-5 s on Hostinger); this is a few hundred ms plus download time.
     *
     * @return array{0: Message, 1: array<int|string, string>} the message and its raw IMAP flags
     */
    private function fetchMessage(string $key, int $uid): array
    {
        // Archived: read from the S3 archive — no mail-server login at all.
        if ($row = $this->archivedRow($this->folderPath($key), $uid)) {
            return [$this->archivedMessage($row), $this->rowFlags($row)];
        }

        $folder = $this->folder($key);

        return $this->withRetry(function () use ($folder, $uid) {
            $this->client()->openFolder($folder->path);
            $this->assertWebsiteMessages($folder->path, [$uid]);
            // Flags + the whole raw message in ONE fetch (was three).
            $row = $this->client()->getConnection()
                ->fetch(['FLAGS', 'RFC822'], [$uid], null, IMAP::ST_UID)->setCanBeEmpty(true)->validatedData()[$uid] ?? null;
            $blob = is_array($row) ? (string) ($row['RFC822'] ?? '') : '';
            abort_if($blob === '', 404, 'Message not found.');

            return [Message::fromString($blob, $this->client()->getConfig()), (array) ($row['FLAGS'] ?? [])];
        });
    }

    /**
     * Run IMAP work; if the connection hiccups ("empty response", dropped
     * socket) reconnect once and try again. abort() responses pass through.
     */
    private function withRetry(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (ImapRuntimeException|ConnectionFailedException|ResponseException $e) {
            Log::info('Mailbox: retrying after IMAP error', ['error' => $e->getMessage()]);
            try {
                $this->client?->disconnect();
            } catch (\Throwable) {
                // ignore
            }
            $this->client = null;

            return $fn();
        }
    }

    /** Same shape as summary(), from a raw parsed header + FLAGS (list view). */
    private function summaryFromHeader(int $uid, Header $header, array $rawFlags): array
    {
        $flags = array_map(fn ($f) => strtolower(ltrim((string) $f, '\\')), $rawFlags);
        $from = $this->addresses($header->get('from'));
        $date = $header->get('date')->first();

        return [
            'uid' => $uid,
            'from' => $from[0] ?? ['name' => '', 'email' => ''],
            'to' => $this->addresses($header->get('to')),
            'subject' => $this->headerString($header->get('subject')),
            'date' => $date instanceof Carbon ? $date->toIso8601String() : null,
            'seen' => in_array('seen', $flags, true),
            'flagged' => in_array('flagged', $flags, true),
            'answered' => in_array('answered', $flags, true),
        ];
    }

    /** @return array<int, array{name:string, email:string}> */
    private function addresses($attribute): array
    {
        if ($attribute === null) {
            return [];
        }
        $out = [];
        foreach ($attribute->toArray() as $addr) {
            if ($addr instanceof Address) {
                $out[] = ['name' => trim((string) $addr->personal, " \"'"), 'email' => strtolower((string) $addr->mail)];
            }
        }

        return $out;
    }

    private function headerString($attribute): string
    {
        if ($attribute === null) {
            return '';
        }

        $value = trim((string) $attribute->toString());
        // The library leaves mixed plain + encoded words ("review =?utf-8?Q?=E2=80=94?= Sing…")
        // undecoded; finish the job.
        if (str_contains($value, '=?')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                $value = $decoded;
            }
        }

        return $value;
    }

    private function isInline($att): bool
    {
        return strtolower((string) $att->disposition) === 'inline' && filled($att->id);
    }

    private function attachmentName($att, int $index): string
    {
        $name = trim((string) ($att->getName() ?: $att->filename ?: ''));
        if ($name === '') {
            $ext = $att->getExtension() ?: 'bin';
            $name = "attachment-{$index}.{$ext}";
        }

        return str_replace(["\r", "\n", '"', '/', '\\'], '', $name);
    }

    /**
     * HTML for the dashboard viewer. The frontend renders it in a sandboxed
     * iframe with scripts disabled; stripping active content here is a second
     * line of defence. Inline (cid:) images become data URIs so they show.
     */
    private function renderableHtml(Message $message): string
    {
        if ($message->hasHTMLBody()) {
            $html = $message->getHTMLBody();
        } else {
            $html = '<div style="white-space:pre-wrap;font-family:inherit">'.$this->linkify(e($message->getTextBody())).'</div>';
        }

        foreach ($message->getAttachments() as $att) {
            $cid = trim((string) $att->id, '<> ');
            $mime = (string) $att->getMimeType();
            if ($cid === '' || ! str_starts_with($mime, 'image/') || strlen((string) $att->content) > 3 * 1024 * 1024) {
                continue;
            }
            $html = str_ireplace('cid:'.$cid, 'data:'.$mime.';base64,'.base64_encode((string) $att->content), $html);
        }

        return $this->stripActiveContent($html);
    }

    private function stripActiveContent(string $html): string
    {
        $html = preg_replace('#<(script|iframe|object|embed|form|meta|base|link)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
        $html = preg_replace('#<(script|iframe|object|embed|form|meta|base|link|input|button)\b[^>]*/?>#i', '', $html) ?? $html;
        $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
        $html = preg_replace('#(href|src)\s*=\s*(["\']?)\s*javascript:#i', '$1=$2#', $html) ?? $html;

        return $html;
    }

    private function linkify(string $escaped): string
    {
        return preg_replace('#\bhttps?://[^\s<]+#i', '<a href="$0" target="_blank" rel="noopener">$0</a>', $escaped) ?? $escaped;
    }

    private function composeHtml(string $body, string $mode, ?Message $original): string
    {
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#222">'
            .nl2br($this->linkify(e($body)))
            .'</div>';

        if ($original === null) {
            return $html;
        }

        $origHtml = $original->hasHTMLBody()
            ? $this->stripActiveContent($original->getHTMLBody())
            : '<div style="white-space:pre-wrap">'.e($original->getTextBody()).'</div>';
        $from = $this->addresses($original->getFrom())[0] ?? ['name' => '', 'email' => ''];
        $fromLabel = e(trim($from['name'].' <'.$from['email'].'>'));
        $date = $original->getDate()?->first();
        $dateLabel = $date instanceof Carbon ? e($date->timezone('Asia/Manila')->format('D, M j, Y \a\t g:i A')) : '';

        if ($mode === 'forward') {
            $to = implode(', ', array_map(fn ($a) => $a['email'], $this->addresses($original->getTo())));

            return $html.'<br><div style="font-family:Arial,sans-serif;font-size:13px;color:#555">'
                .'---------- Forwarded message ---------<br>'
                .'From: '.$fromLabel.'<br>'
                .'Date: '.$dateLabel.'<br>'
                .'Subject: '.e($this->headerString($original->getSubject())).'<br>'
                .'To: '.e($to).'</div><br>'.$origHtml;
        }

        return $html.'<br><div style="font-family:Arial,sans-serif;font-size:13px;color:#555">On '.$dateLabel.', '.$fromLabel.' wrote:</div>'
            .'<blockquote style="margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex">'.$origHtml.'</blockquote>';
    }

    private function composeText(string $body, string $mode, ?Message $original): string
    {
        if ($original === null) {
            return $body;
        }
        $origText = $original->hasTextBody() ? $original->getTextBody() : trim(html_entity_decode(strip_tags($original->getHTMLBody())));
        $from = $this->addresses($original->getFrom())[0] ?? ['name' => '', 'email' => ''];

        if ($mode === 'forward') {
            return $body."\n\n---------- Forwarded message ---------\nFrom: {$from['name']} <{$from['email']}>\nSubject: "
                .$this->headerString($original->getSubject())."\n\n".$origText;
        }

        $quoted = implode("\n", array_map(fn ($l) => '> '.$l, explode("\n", $origText)));

        return $body."\n\nOn ".($original->getDate()?->first()?->format('D, M j, Y g:i A') ?? '').", {$from['name']} <{$from['email']}> wrote:\n".$quoted;
    }

    private function cleanSearch(?string $search): ?string
    {
        $search = trim(preg_replace('/[\x00-\x1F"\\\\]/u', ' ', (string) $search) ?? '');

        return $search === '' ? null : mb_substr($search, 0, 100);
    }

    /** @return int[] */
    private function cleanUids(array $uids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $uids), fn ($u) => $u > 0)));
    }
}
