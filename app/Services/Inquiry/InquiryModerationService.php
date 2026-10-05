<?php

namespace App\Services\Inquiry;

use App\Jobs\SendInquiryReviewNotification;
use App\Mail\MessageNotificationMailer;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditMailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The accept/reject mutation + side-effect core, extracted from
 * ConversationController so a human moderator (the accept/reject/bulk-action
 * endpoints) and an unattended caller (the scheduled auto-approver,
 * App\Console\Commands\AutoApproveInquiries) run the exact same path — same
 * status write, same agent attach, same email, same audit shape.
 *
 * $actor is nullable so a scheduled run can call this with no signed-in user.
 * reviewed_by is a nullable FK (see the moderation-fields migration), so a
 * null actor stores cleanly; $actorLabel fills the audit sentence in that case
 * ("Automatic approver" rather than a blank name).
 */
class InquiryModerationService
{
    public function accept(
        Conversation $conversation,
        ?User $actor,
        string $auditSource = 'inquiry_accept',
        ?string $actorLabel = null,
    ): void {
        $latestMessage = $conversation->latestMessage;
        $message = $latestMessage?->body;
        $agent = $conversation->agentUser;
        $sender = $conversation->chat?->user;
        // Load the sender's agent profile so the email can surface
        // their WhatsApp number when they're an agent. Skipped
        // silently for regular clients (the relation returns null).
        $sender?->loadMissing('agent');
        $type = $conversation->chat?->listing;
        // Load the relations the email's property card needs so we
        // don't trigger N+1 queries inside the mailer payload builder.
        if ($type) {
            $type->load([
                'agent',
                'category',
                'property.barangay.city.province',
                'property.propertyAttribute.subtype.type',
            ]);
        }
        // Slug trailer must be the chat_id — the frontend's
        // ListingInquiries component matches `{slug}-{chat.id}` to
        // find the right inquiry. Using conversation.id here would
        // render the page with no inquiry selected (slugError =
        // "invalid"). Falls through gracefully when the chat has
        // no listing (agent-direct chats don't carry a listing).
        $slug = $type
            ? Str::slug($type->name) . '-' . $conversation->chat_id
            : 'chat-' . $conversation->chat_id;

        $listingName = $type?->name;
        $actorName = $actor?->name ?? $actorLabel ?? 'The system';
        $conversation->auditSource = $auditSource;
        $conversation->auditDescription = sprintf(
            '%s accepted the inquiry%s',
            $actorName,
            $listingName ? " on {$listingName}" : '',
        );

        $conversation->update([
            'status' => 'accepted',
            'reviewed_by' => $actor?->id,
            'reviewed_at' => now(),
        ]);

        // Add the agent to conversation_users so they can see the full history
        if ($conversation->agent_user_id) {
            $conversation->users()->syncWithoutDetaching([
                $conversation->agent_user_id => [
                    'last_read_at' => null,
                    'last_notified_at' => now(),
                ],
            ]);
        }

        // Strictly notify the agent. Admins + team leader already
        // saw the submission email/push when the client first filed the
        // inquiry, so a second copy here would just be inbox noise.
        //
        // The acceptance itself is already committed above — a
        // failing email transport MUST NOT break the caller. Log
        // the failure + write an audit row, then return success;
        // the email is a side-effect notification, not a critical
        // path. Particularly important for bulkAction() and the
        // scheduled approver, where one bad email shouldn't take
        // down a batch.
        if (!$agent || !$sender) {
            return; // nothing to notify (e.g. agent_user_id was null)
        }
        try {
            MessageNotificationMailer::dispatchForAcceptance(
                sender:      $sender,
                agent:       $agent,
                message:     $message ?? '',
                slug:        $slug,
                listing:     MessageNotificationMailer::buildListingPayload($type),
                agentUserId: $conversation->agent_user_id,
            );
        } catch (Throwable $e) {
            Log::warning('Acceptance email failed to dispatch', [
                'conversation_id' => $conversation->id,
                'agent_user_id'   => $conversation->agent_user_id,
                'error'           => $e->getMessage(),
            ]);
            app(AuditMailService::class)->recordFailure(
                $e,
                MessageNotificationMailer::class,
                $agent?->email ? [$agent->email] : [],
                'Inquiry accepted — agent notification',
                [
                    'auditable_type' => Conversation::class,
                    'auditable_id'   => $conversation->id,
                ],
            );
        }

        // Push is the agent's channel whenever they prefer it over email —
        // dispatchForAcceptance() above already no-ops silently for a
        // push-preferring agent (its own prefersInquiryPush gate), so this
        // is what actually reaches them in that case. Scoped to just the
        // agent (onlyUserId) so admins/the team leader — already notified
        // at submission time — don't get a second push here. Runs inline
        // (dispatchSync): production has no queue worker (see the comment
        // atop MessageNotificationMailer), and the Expo call is a
        // sub-second HTTP request, so this is cheap enough to not queue.
        try {
            SendInquiryReviewNotification::dispatchSync(
                $conversation->id,
                $latestMessage?->id ?? 0,
                $actor?->id ?? 0,
                $conversation->agent_user_id,
                true,
            );
        } catch (Throwable $e) {
            Log::warning('Acceptance push failed to dispatch', [
                'conversation_id' => $conversation->id,
                'agent_user_id'   => $conversation->agent_user_id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    /**
     * No email dispatch on the reject side (clients aren't notified of
     * rejection by design).
     */
    public function reject(
        Conversation $conversation,
        ?User $actor,
        string $auditSource = 'inquiry_reject',
        ?string $actorLabel = null,
    ): void {
        $listingName = $conversation->chat?->listing?->name;
        $actorName = $actor?->name ?? $actorLabel ?? 'The system';
        $conversation->auditSource = $auditSource;
        $conversation->auditDescription = sprintf(
            '%s rejected the inquiry%s',
            $actorName,
            $listingName ? " on {$listingName}" : '',
        );

        $conversation->update([
            'status' => 'rejected',
            'reviewed_by' => $actor?->id,
            'reviewed_at' => now(),
        ]);
    }
}
