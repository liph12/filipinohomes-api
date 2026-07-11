<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\User;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Writes Audit rows for outbound mail outcomes — every Mail::send
 * in the app. Two paths feed in:
 *
 *   recordSent($event)     — fired by a global MessageSent listener
 *                            registered in bootstrap/app.php. Catches
 *                            every successful send for free
 *                            regardless of which Mailable was used.
 *
 *   recordFailure(...)     — called inside try/catch blocks around
 *                            Mail::send / Mail::to(...)->send() /
 *                            MessageNotificationMailer::dispatchFor*().
 *                            Laravel doesn't ship a MessageFailed
 *                            event so failures are caught locally.
 *
 * Together: every send is either an Audit row with event='mailer_sent'
 * or an Audit row with event='mailer_failed'. Never silently dropped.
 */
class AuditMailService
{
    /**
     * Handle a successful mail send (called from the
     * MessageSent listener registered globally).
     */
    public function recordSent(MessageSent $event): void
    {
        try {
            $message    = $event->message;
            $subject    = method_exists($message, 'getSubject') ? (string) $message->getSubject() : '';
            $recipients = $this->extractRecipients($message);
            $mailable   = $this->detectMailableClass($event);
            $body       = $this->extractBody($message);
            $user       = Auth::user();

            $description = $this->buildSuccessDescription($subject, $recipients);

            Audit::create([
                'user_id'        => $user?->id,
                'user_type'      => $user ? User::class : null,
                'user_role'      => $user?->role?->name,
                'user_name'      => $user?->name,
                'event'          => 'mailer_sent',
                'category'       => 'mailer',
                'source'         => $mailable,
                'auditable_type' => null,
                'auditable_id'   => null,
                'subject_label'  => implode(', ', array_slice($recipients, 0, 3)),
                'description'    => $description,
                'old_values'     => null,
                // Capture the rendered body (what the recipient actually
                // received) so the audit-detail modal can show it. Stripped
                // from the list feed by ActivityLogController; served in full
                // only via the single-row detail endpoint.
                'new_values'     => array_merge([
                    'recipients'     => $recipients,
                    'subject'        => $subject,
                    'mailable_class' => $mailable,
                ], $body),
            ]);
        } catch (Throwable $e) {
            Log::warning('Mail audit (sent) write failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a failed mail send (called inside try/catch blocks).
     * Pass whatever context the call site has — subject + recipients
     * are recommended; mailable class name helps filter later.
     */
    public function recordFailure(
        Throwable $error,
        string $mailable,
        array $recipients = [],
        ?string $subject = null,
        array $context = [],
    ): void {
        try {
            $smtpCode     = null;
            $smtpResponse = null;
            if ($error instanceof TransportExceptionInterface) {
                $smtpCode     = method_exists($error, 'getCode') ? (string) $error->getCode() : null;
                $smtpResponse = $error->getMessage();
            }

            $user = Auth::user();

            $description = $this->buildFailureDescription(
                $subject ?? '(no subject)',
                $recipients,
                $smtpResponse ?? $error->getMessage(),
            );

            Audit::create([
                'user_id'        => $user?->id,
                'user_type'      => $user ? User::class : null,
                'user_role'      => $user?->role?->name,
                'user_name'      => $user?->name,
                'event'          => 'mailer_failed',
                'category'       => 'mailer',
                'source'         => $mailable,
                'auditable_type' => $context['auditable_type'] ?? null,
                'auditable_id'   => $context['auditable_id']   ?? null,
                'subject_label'  => implode(', ', array_slice($recipients, 0, 3)),
                'description'    => $description,
                'old_values'     => null,
                'new_values'     => array_merge([
                    'recipients'        => $recipients,
                    'subject'           => $subject,
                    'mailable_class'    => $mailable,
                    'exception_class'   => $error::class,
                    'exception_message' => $error->getMessage(),
                    'smtp_code'         => $smtpCode,
                    'smtp_response'     => $smtpResponse,
                    'transport_class'   => $this->detectTransportClass(),
                ], $context),
            ]);
        } catch (Throwable $e) {
            // Don't shadow the original mailer failure. Log this
            // bookkeeping miss to laravel.log and move on.
            Log::warning('Mail audit (failed) write failed', [
                'original_error' => $error->getMessage(),
                'audit_error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract recipient email addresses from a Symfony Email.
     * Falls back to an empty array if the message shape is unusual.
     */
    private function extractRecipients(object $message): array
    {
        if (!$message instanceof Email) {
            return [];
        }
        $out = [];
        foreach (array_merge($message->getTo(), $message->getCc(), $message->getBcc()) as $addr) {
            $out[] = $addr->getAddress();
        }
        return $out;
    }

    /**
     * Rendered message body as sent. Prefers the HTML part (what most
     * recipients see); also keeps the text part when present. Each is
     * capped so a pathological base64-image email can't bloat the row —
     * the column is longtext, but 256KB per part is plenty for real
     * transactional mail. Returns only the keys that have content.
     */
    private function extractBody(object $message): array
    {
        if (!$message instanceof Email) {
            return [];
        }
        $cap = 262144; // 256KB per part
        $out = [];

        $html = $message->getHtmlBody();
        if (is_string($html) && $html !== '') {
            $out['body_html'] = mb_substr($html, 0, $cap);
        }
        $text = $message->getTextBody();
        if (is_string($text) && $text !== '') {
            $out['body_text'] = mb_substr($text, 0, $cap);
        }
        return $out;
    }

    /**
     * Identify the Mailable class. Laravel sets the X-Mailer header
     * by default; we also check a custom X-FH-Mailer header that
     * Mailables can set if they want a clean identifier.
     */
    private function detectMailableClass(MessageSent $event): string
    {
        $headers = $event->message->getHeaders();
        if ($headers->has('X-FH-Mailer')) {
            return (string) $headers->get('X-FH-Mailer')->getBodyAsString();
        }
        // Fall back to the data-bag's __mailable key if any
        if (is_array($event->data ?? null) && isset($event->data['__mailable'])) {
            return (string) $event->data['__mailable'];
        }
        return 'unknown';
    }

    /**
     * Best-effort transport class detection. Reads the default
     * MAIL_MAILER from config — useful when the failure mode is
     * "wrong transport" rather than a per-message error.
     */
    private function detectTransportClass(): ?string
    {
        return (string) config('mail.default');
    }

    private function buildSuccessDescription(string $subject, array $recipients): string
    {
        $to = $this->humanizeRecipientList($recipients);
        $subj = $subject !== '' ? "\"{$subject}\"" : 'message';
        return "Sent {$subj} to {$to}";
    }

    private function buildFailureDescription(string $subject, array $recipients, string $error): string
    {
        $to = $this->humanizeRecipientList($recipients);
        $subj = $subject !== '' && $subject !== '(no subject)' ? "\"{$subject}\"" : 'message';
        // Trim the error tail so the row description stays scannable;
        // the full reason lives in new_values.smtp_response.
        $errTrim = mb_substr($error, 0, 180);
        return "Failed to send {$subj} to {$to}: {$errTrim}";
    }

    private function humanizeRecipientList(array $recipients): string
    {
        if (empty($recipients)) return 'recipient';
        if (count($recipients) === 1) return $recipients[0];
        if (count($recipients) <= 3) return implode(', ', $recipients);
        return $recipients[0] . ' and ' . (count($recipients) - 1) . ' others';
    }
}
