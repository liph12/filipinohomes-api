<?php

namespace App\Http\Controllers;

use App\Mail\InquiryReplyMailer;
use App\Models\Inquiry;
use App\Models\InquiryReply;
use App\Services\AuditMailService;
use App\Services\Inquiry\InquiryReadState;
use App\Services\Mailbox\MailboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Admin-side inquiry inbox: list submissions from Get In Touch / Contact Us
 * and reply to them from info@filipinohomes.com so the inbox becomes a real
 * two-way conversation channel instead of a write-only log.
 */
class InquiryController extends Controller
{
    public function __construct(private InquiryReadState $reads) {}

    /**
     * Paginated list of inquiries with their replies eager-loaded for the
     * dashboard. Newest first.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:255',
            // Filter by form source. Known values posted by the public
            // forms are `home_get_in_touch`, `maintenance_page`, and
            // `contact_page` (see UserController::sendInquiry +
            // sendContactUs). Older rows persisted before the
            // add_source_to_inquiries_table migration may have a null
            // source — they're matched by the All filter (no `source`
            // param) only.
            'source' => 'nullable|string|max:64',
        ]);

        $query = Inquiry::with(['replies.admin:id,name,email,avatar', 'doneBy:id,name,avatar'])
            ->orderByDesc('created_at');

        if (! empty($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('message', 'like', $term);
            });
        }

        if (! empty($validated['source'])) {
            $query->where('source', $validated['source']);
        }

        $paginator = $query->paginate($validated['per_page'] ?? 20);
        $userId = $request->user()->id;
        // read_at = this admin's own read time; read_by = which admins have read it.
        $this->reads->present($paginator->getCollection(), $userId);

        // Total unread for this admin across ALL inquiries (not just this page/filter) so the
        // header + sidebar badge reflect their real backlog.
        return response()->json(
            $paginator->toArray() + ['unread_count' => $this->reads->unreadCount($userId)],
        );
    }

    /**
     * Lightweight unread tally (this admin's) for the sidebar + topbar Contact Inbox badges.
     */
    public function unreadCount(Request $request)
    {
        $userId = $request->user()->id;

        return response()->json([
            'unread_count' => $this->reads->unreadCount($userId),
            // Unread submissions from the last few days — the topbar pops a notice for these.
            'recent' => $this->reads->recentUnread($userId),
        ]);
    }

    public function show(Request $request, Inquiry $inquiry)
    {
        // Opening a submission marks it read for this admin only (first open time is kept).
        $userId = $request->user()->id;
        $this->reads->markRead($inquiry, $userId);
        $inquiry->load(['replies.admin:id,name,email,avatar', 'doneBy:id,name,avatar']);
        $this->reads->present(collect([$inquiry]), $userId);

        return response()->json(['data' => $inquiry]);
    }

    /**
     * Mark one inquiry read/unread for this admin without opening the thread (row toggle).
     */
    public function setRead(Request $request, Inquiry $inquiry)
    {
        $validated = $request->validate(['read' => 'required|boolean']);
        $userId = $request->user()->id;
        $validated['read'] ? $this->reads->markRead($inquiry, $userId) : $this->reads->markUnread($inquiry, $userId);
        $this->reads->present(collect([$inquiry]), $userId);

        return response()->json([
            'data' => ['id' => $inquiry->id, 'read_at' => $inquiry->read_at, 'read_by' => $inquiry->read_by],
            'unread_count' => $this->reads->unreadCount($userId),
        ]);
    }

    /**
     * Mark a submission done / not done. Shared by all admins; records who marked it.
     */
    public function setDone(Request $request, Inquiry $inquiry)
    {
        $validated = $request->validate(['done' => 'required|boolean']);

        $inquiry->forceFill($validated['done']
            ? ['done_at' => $inquiry->done_at ?? now(), 'done_by' => $inquiry->done_by ?? $request->user()->id]
            : ['done_at' => null, 'done_by' => null])->save();
        $inquiry->load('doneBy:id,name,avatar');

        return response()->json([
            'data' => ['id' => $inquiry->id, 'done_at' => $inquiry->done_at, 'done_by' => $inquiry->doneBy],
        ]);
    }

    /**
     * Mark the selected inquiries read/unread for this admin (list checkboxes).
     */
    public function setReadMany(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer|distinct|exists:inquiries,id',
            'read' => 'required|boolean',
        ]);
        $userId = $request->user()->id;
        $this->reads->markMany(array_map('intval', $validated['ids']), $userId, (bool) $validated['read']);

        return response()->json(['unread_count' => $this->reads->unreadCount($userId)]);
    }

    /**
     * Mark every inquiry read for this admin (inbox "mark all read"). Other admins keep their own state.
     */
    public function markAllRead(Request $request)
    {
        $this->reads->markAllRead($request->user()->id);

        return response()->json(['unread_count' => 0]);
    }

    /**
     * Persist + send an admin reply to the original submitter. Saves first so
     * the audit trail exists even if Mail::send fails; sent_at is set after a
     * successful send so the UI can distinguish queued/failed from delivered.
     */
    public function reply(Request $request, Inquiry $inquiry)
    {
        $validated = $request->validate([
            'subject' => 'nullable|string|max:255',
            'body' => 'required|string|max:10000',
            // Already-hosted S3 image URLs (uploaded via /upload before send) —
            // rendered inline in the email. No file handling happens here.
            'attachments' => 'nullable|array|max:6',
            'attachments.*' => 'url|max:2048',
        ]);

        $admin = $request->user();

        $reply = InquiryReply::create([
            'inquiry_id' => $inquiry->id,
            'admin_user_id' => $admin->id,
            'subject' => $validated['subject'] ?? 'Re: Filipino Homes — your message',
            'body' => $validated['body'],
            'attachments' => $validated['attachments'] ?? [],
        ]);

        try {
            // Through info@'s own mailbox (when connected) and filed in its Sent
            // folder, so the reply also shows in the dashboard Mailbox.
            $mailbox = app(MailboxService::class);
            $sent = ($mailbox->isConfigured() ? Mail::mailer('mailbox') : Mail::mailer())
                ->to($inquiry->email)
                ->send(new InquiryReplyMailer(
                    inquiry: $inquiry,
                    reply: $reply,
                    adminName: $admin->name ?? 'Filipino Homes',
                    adminAvatar: $admin->avatar ?? null,
                ));
            if ($sent !== null && $mailbox->isConfigured()) {
                $mailbox->appendToSent($sent->toString());
            }

            $reply->update(['sent_at' => now()]);
        } catch (\Throwable $e) {
            // Keep the row so the admin can retry from the dashboard, but
            // surface the failure so the UI shows an unsent state instead
            // of silently misleading the user.
            Log::error('InquiryController@reply: mail send failed', [
                'inquiry_id' => $inquiry->id,
                'reply_id' => $reply->id,
                'error' => $e->getMessage(),
            ]);
            app(AuditMailService::class)->recordFailure(
                $e,
                InquiryReplyMailer::class,
                [$inquiry->email],
                $validated['subject'] ?? 'Re: Filipino Homes — your message',
                ['auditable_type' => Inquiry::class, 'auditable_id' => $inquiry->id],
            );

            return response()->json([
                'message' => 'Reply saved but email delivery failed. Please retry.',
                'data' => $reply->fresh(),
            ], 502);
        }

        return response()->json(['data' => $reply->fresh()], 201);
    }
}
