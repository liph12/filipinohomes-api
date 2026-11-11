<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Mailbox\MailboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

/**
 * Admin Mailbox — the info@filipinohomes.com inbox in the dashboard.
 * Admin role only (see routes/api.php). All mail lives on the IMAP server;
 * see MailboxService.
 */
class MailboxController extends Controller
{
    private const FOLDERS = ['inbox', 'starred', 'sent', 'drafts', 'spam', 'trash', 'archive'];

    public function __construct(private readonly MailboxService $mailbox) {}

    /** GET /admin/mailbox/folders */
    public function folders(): JsonResponse
    {
        if (! $this->mailbox->isConfigured()) {
            return response()->json(['configured' => false, 'address' => $this->mailbox->address(), 'folders' => []]);
        }

        // Opening / polling the Mailbox keeps the database copy fresh even when
        // the scheduler isn't running (background sync after the response).
        $this->mailbox->syncIfStale();

        return $this->guard(fn () => response()->json([
            'configured' => true,
            'address' => $this->mailbox->address(),
            'folders' => $this->mailbox->folders(),
            'quota' => $this->mailbox->quota(),
            // When the database copy was last refreshed (null = live mode).
            'synced_at' => $this->mailbox->syncedAt()?->toIso8601String(),
        ]));
    }

    /** GET /admin/mailbox/messages?folder=inbox&page=1&per_page=50&q= */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'folder' => ['required', Rule::in(self::FOLDERS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(MailboxService::categoryKeys())],
            'status' => ['nullable', Rule::in(array_keys(MailboxService::INQUIRY_STATUS_FILTERS))],
        ]);

        return $this->guard(fn () => response()->json($this->mailbox->list(
            $v['folder'],
            (int) ($v['page'] ?? 1),
            (int) ($v['per_page'] ?? 50),
            $v['q'] ?? null,
            $v['category'] ?? null,
            $v['status'] ?? null,
        )));
    }

    /** POST /admin/mailbox/sync — refresh the database copy now (the ⟳ button). */
    public function sync(): JsonResponse
    {
        return $this->guard(function () {
            $stats = $this->mailbox->sync();

            return response()->json([
                'ok' => true,
                'stats' => $stats,
                'synced_at' => $this->mailbox->syncedAt()?->toIso8601String(),
            ]);
        });
    }

    /** GET /admin/mailbox/categories — website-inquiry tabs with total/unread counts (inbox). */
    public function categories(): JsonResponse
    {
        return $this->guard(fn () => response()->json(['data' => $this->mailbox->categories()]));
    }

    /** GET /admin/mailbox/messages/{uid}?folder=inbox */
    public function show(Request $request, int $uid): JsonResponse
    {
        $v = $request->validate(['folder' => ['required', Rule::in(self::FOLDERS)]]);

        return $this->guard(function () use ($v, $uid) {
            // Marking read + refreshing the counts happens after the response (see show()).
            return response()->json(['data' => $this->mailbox->show($v['folder'], $uid)]);
        });
    }

    /** GET /admin/mailbox/messages/{uid}/attachments/{index}?folder=inbox */
    public function attachment(Request $request, int $uid, int $index): Response
    {
        $v = $request->validate(['folder' => ['required', Rule::in(self::FOLDERS)]]);

        return $this->guard(function () use ($v, $uid, $index) {
            $file = $this->mailbox->attachment($v['folder'], $uid, $index);

            // Always a download, never rendered by the browser (an attached
            // .html or .svg must not run on our origin).
            return response($file['content'], 200, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="'.addcslashes($file['name'], '"\\').'"; filename*=UTF-8\'\''.rawurlencode($file['name']),
                'X-Content-Type-Options' => 'nosniff',
                'X-Original-Content-Type' => $file['mime'],
                'Cache-Control' => 'private, no-store',
            ]);
        });
    }

    /** POST /admin/mailbox/flag  {folder, uids[], flag: seen|flagged, value: bool} */
    public function flag(Request $request): JsonResponse
    {
        $v = $request->validate([
            'folder' => ['required', Rule::in(self::FOLDERS)],
            'uids' => ['required', 'array', 'min:1', 'max:200'],
            'uids.*' => ['integer', 'min:1'],
            'flag' => ['required', Rule::in(['seen', 'flagged'])],
            'value' => ['required', 'boolean'],
        ]);

        return $this->guard(function () use ($v) {
            $this->mailbox->setFlag($v['folder'], $v['uids'], $v['flag'], (bool) $v['value']);
            $this->mailbox->forgetCategoryCounts();

            return response()->json(['ok' => true]);
        });
    }

    /** POST /admin/mailbox/move  {folder, uids[], target} */
    public function move(Request $request): JsonResponse
    {
        $v = $request->validate([
            'folder' => ['required', Rule::in(self::FOLDERS)],
            'uids' => ['required', 'array', 'min:1', 'max:200'],
            'uids.*' => ['integer', 'min:1'],
            'target' => ['required', Rule::in(array_diff(self::FOLDERS, ['starred']))],
        ]);

        return $this->guard(function () use ($v) {
            $this->mailbox->move($v['folder'], $v['uids'], $v['target']);
            $this->mailbox->forgetCategoryCounts();

            return response()->json(['ok' => true]);
        });
    }

    /** POST /admin/mailbox/delete  {folder, uids[]} — to Trash; from Trash, for good. */
    public function delete(Request $request): JsonResponse
    {
        $v = $request->validate([
            'folder' => ['required', Rule::in(self::FOLDERS)],
            'uids' => ['required', 'array', 'min:1', 'max:200'],
            'uids.*' => ['integer', 'min:1'],
        ]);

        return $this->guard(function () use ($v) {
            $this->mailbox->delete($v['folder'], $v['uids']);
            $this->mailbox->forgetCategoryCounts();

            return response()->json(['ok' => true]);
        });
    }

    /**
     * POST /admin/mailbox/send (multipart)
     * to[], cc[], bcc[], subject, mail_body, mode: new|reply|reply_all|forward,
     * ref_folder, ref_uid, files[]
     */
    public function send(Request $request): JsonResponse
    {
        $maxRecipients = (int) config('mailbox.max_recipients', 50);
        $maxMb = (int) config('mailbox.max_attachment_mb', 20);

        $v = $request->validate([
            'to' => ['required', 'array', 'min:1', 'max:'.$maxRecipients],
            'to.*' => ['required', 'email:rfc', 'max:254'],
            'cc' => ['nullable', 'array', 'max:'.$maxRecipients],
            'cc.*' => ['email:rfc', 'max:254'],
            'bcc' => ['nullable', 'array', 'max:'.$maxRecipients],
            'bcc.*' => ['email:rfc', 'max:254'],
            'subject' => ['required', 'string', 'max:300'],
            'mail_body' => ['nullable', 'string', 'max:100000'],
            // Reply-only mailbox: no new messages, no forwarding. Every send
            // must answer an existing email (ref_folder + ref_uid).
            'mode' => ['required', Rule::in(['reply', 'reply_all'])],
            'ref_folder' => ['required', Rule::in(self::FOLDERS)],
            'ref_uid' => ['required', 'integer', 'min:1'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:'.($maxMb * 1024)],
        ]);

        $files = $request->file('files', []);
        $totalBytes = array_sum(array_map(fn ($f) => $f->getSize(), $files));
        if ($totalBytes > $maxMb * 1024 * 1024) {
            return response()->json(['message' => "Attachments are over {$maxMb} MB in total."], 422);
        }

        $subject = trim(preg_replace('/[\r\n]+/', ' ', $v['subject']));

        return $this->guard(function () use ($v, $files, $subject, $request) {
            $this->mailbox->send([
                'to' => $v['to'],
                'cc' => $v['cc'] ?? [],
                'bcc' => $v['bcc'] ?? [],
                'subject' => $subject,
                'body' => $v['mail_body'] ?? '',
                'mode' => $v['mode'],
                'ref_folder' => $v['ref_folder'],
                'ref_uid' => $v['ref_uid'],
            ], $files, (string) ($request->user()->email ?? $request->user()->id), (int) $request->user()->id);

            return response()->json(['ok' => true]);
        });
    }

    /**
     * Turn IMAP/SMTP failures into a clean JSON error instead of a 500 page,
     * while letting abort() responses (404/422/503) through unchanged.
     */
    private function guard(callable $fn): Response
    {
        try {
            return $fn();
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (AuthFailedException $e) {
            Log::error('Mailbox: IMAP login failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Could not log in to the mailbox. Check MAILBOX_USERNAME / MAILBOX_PASSWORD.'], 503);
        } catch (TransportExceptionInterface $e) {
            Log::error('Mailbox: SMTP send failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'The mail server refused the message: '.$e->getMessage()], 502);
        } catch (ConnectionFailedException $e) {
            Log::error('Mailbox: IMAP connection failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Could not reach the mail server. Try again in a moment.'], 503);
        } catch (\Throwable $e) {
            // The server's own "wrong login" answer can come back as a plain
            // response error rather than AuthFailedException.
            if (str_contains($e->getMessage(), 'AUTHENTICATIONFAILED') || str_contains($e->getMessage(), 'Invalid credentials')) {
                Log::error('Mailbox: IMAP login rejected', ['error' => $e->getMessage()]);

                return response()->json(['message' => 'The mail server rejected the login. Check MAILBOX_USERNAME / MAILBOX_PASSWORD and that MAILBOX_IMAP_HOST is that account\'s server (e.g. imap.gmail.com for Gmail, imap.hostinger.com for info@).'], 503);
            }
            Log::error('Mailbox: request failed', ['error' => $e->getMessage(), 'class' => get_class($e)]);

            return response()->json(['message' => 'Mailbox error. Please try again.'], 500);
        }
    }
}
