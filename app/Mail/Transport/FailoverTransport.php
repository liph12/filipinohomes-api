<?php

namespace App\Mail\Transport;

use App\Support\MailFailover;
use Closure;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Sends through the website's SMTP accounts in order (MailFailover::order()):
 * the first one, and if it's refused or doesn't answer, the same email through
 * the next. Like Symfony's FailoverTransport, but it remembers what happened —
 * which account delivered the email and which failed before it — so
 * AuditMailService can log it for Email Health. (Symfony's only says "All
 * transports failed", and nothing at all when a later account delivered.)
 *
 * An account that just failed is tried last for `retry_after` seconds, so a
 * dead account doesn't slow every email down.
 */
class FailoverTransport implements TransportInterface
{
    /**
     * What happened to the last email this process sent — read by the
     * MessageSent listener / recordFailure right after (sending is synchronous).
     *
     * @var array{via: string|null, failures: array<string, TransportExceptionInterface>}
     */
    public static array $last = ['via' => null, 'failures' => []];

    /** @var array<string, TransportInterface> */
    private array $transports = [];

    /** @var array<string, int> mailer => tried last until (unix time) */
    private array $failedUntil = [];

    /**
     * @param  Closure(string): TransportInterface  $resolve  builds an account's own transport
     */
    public function __construct(private Closure $resolve, private int $retryAfter = 60) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        self::$last = ['via' => null, 'failures' => []];

        foreach ($this->tryOrder() as $mailer) {
            try {
                $sent = $this->transport($mailer)->send(clone $message, $envelope);
                unset($this->failedUntil[$mailer]);
                self::$last['via'] = $mailer;

                return $sent;
            } catch (TransportExceptionInterface $e) {
                self::$last['failures'][$mailer] = $e;
                $this->failedUntil[$mailer] = time() + $this->retryAfter;
            }
        }

        throw new MailFailoverException(self::$last['failures']);
    }

    /** The configured order, with accounts that failed in the last retry_after seconds moved last. */
    private function tryOrder(): array
    {
        $order = MailFailover::order();
        $now = time();
        $fresh = array_filter($order, fn ($m) => ($this->failedUntil[$m] ?? 0) <= $now);

        return array_values(array_unique([...$fresh, ...$order]));
    }

    private function transport(string $mailer): TransportInterface
    {
        return $this->transports[$mailer] ??= ($this->resolve)($mailer);
    }

    public function __toString(): string
    {
        return 'failover('.implode(' ', MailFailover::order()).')';
    }
}
