<?php

namespace Tests\Concerns;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A fake Leuterio Realty for the LR status sync tests: an answer per address
 * (as the client sends it: trimmed, lowercased), and any address with no entry
 * is an unknown agent. `preventStrayRequests` is on, so nothing can reach the
 * real LR.
 *
 * A hit echoes the address it was asked about, as the real LR does — the client
 * refuses an answer that doesn't name the agent it asked about.
 */
trait FakesLrAgentStatus
{
    /**
     * @param  array<string, PromiseInterface|Closure>  $answers  keyed by lowercased address:
     *                                                            a ready response (used as-is) or lrHit()
     */
    protected function lr(array $answers): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($answers) {
            $email = $this->askedAbout($request);
            $answer = $answers[$email] ?? $this->lrMiss();

            return $answer instanceof Closure ? $answer($email) : $answer;
        });
    }

    /** LR knows the agent and says `$status` (null = it has none). */
    protected function lrHit(?string $status, int $remaining = 50): Closure
    {
        return fn (string $email) => $this->lrReply($email, $status, $remaining);
    }

    /** The response to a request for `$email` that LR knows — for a hand-written Http::fake closure. */
    protected function lrReply(string $email, ?string $status, int $remaining = 50): PromiseInterface
    {
        return Http::response(['id' => 1, 'email' => $email, 'status' => $status], 200, ['X-RateLimit-Remaining' => (string) $remaining]);
    }

    /** LR doesn't know the address: HTTP 403 "User not found.". */
    protected function lrMiss(): PromiseInterface
    {
        return Http::response(['message' => 'User not found.', 'success' => false], 403, ['X-RateLimit-Remaining' => '50']);
    }

    protected function askedAbout(Request $request): string
    {
        return urldecode(basename(parse_url($request->url(), PHP_URL_PATH)));
    }

    /** @return list<string> the addresses LR was asked about, in order */
    protected function asked(): array
    {
        $asked = [];
        Http::assertSent(function (Request $request) use (&$asked) {
            $asked[] = $this->askedAbout($request);

            return true;
        });

        return $asked;
    }
}
