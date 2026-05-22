<?php

namespace App\Http\Controllers;

use App\Mail\InquiryReplyMailer;
use App\Models\Inquiry;
use App\Models\InquiryReply;
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
    /**
     * Paginated list of inquiries with their replies eager-loaded for the
     * dashboard. Newest first.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'search'   => 'nullable|string|max:255',
            // Filter by form source. Known values posted by the public
            // forms are `home_get_in_touch`, `maintenance_page`, and
            // `contact_page` (see UserController::sendInquiry +
            // sendContactUs). Older rows persisted before the
            // add_source_to_inquiries_table migration may have a null
            // source — they're matched by the All filter (no `source`
            // param) only.
            'source'   => 'nullable|string|max:64',
        ]);

        $query = Inquiry::with(['replies.admin:id,name,email,avatar'])
            ->orderByDesc('created_at');

        if (!empty($validated['search'])) {
            $term = '%' . $validated['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('message', 'like', $term);
            });
        }

        if (!empty($validated['source'])) {
            $query->where('source', $validated['source']);
        }

        return $query->paginate($validated['per_page'] ?? 20);
    }

    public function show(Inquiry $inquiry)
    {
        $inquiry->load(['replies.admin:id,name,email,avatar']);
        return response()->json(['data' => $inquiry]);
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
            'body'    => 'required|string|max:10000',
        ]);

        $admin = $request->user();

        $reply = InquiryReply::create([
            'inquiry_id'    => $inquiry->id,
            'admin_user_id' => $admin->id,
            'subject'       => $validated['subject'] ?? 'Re: Filipino Homes — your message',
            'body'          => $validated['body'],
        ]);

        try {
            Mail::to($inquiry->email)->send(new InquiryReplyMailer(
                inquiry:     $inquiry,
                reply:       $reply,
                adminName:   $admin->name ?? 'Filipino Homes',
                adminAvatar: $admin->avatar ?? null,
            ));

            $reply->update(['sent_at' => now()]);
        } catch (\Throwable $e) {
            // Keep the row so the admin can retry from the dashboard, but
            // surface the failure so the UI shows an unsent state instead
            // of silently misleading the user.
            Log::error('InquiryController@reply: mail send failed', [
                'inquiry_id' => $inquiry->id,
                'reply_id'   => $reply->id,
                'error'      => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Reply saved but email delivery failed. Please retry.',
                'data'    => $reply->fresh(),
            ], 502);
        }

        return response()->json(['data' => $reply->fresh()], 201);
    }
}
