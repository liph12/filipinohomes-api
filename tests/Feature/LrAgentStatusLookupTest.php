<?php

namespace Tests\Feature;

use App\Services\LeuterioreRealty\LrApiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LrApiService::lookupAgentStatus — what LR says about one agent, as found /
 * not_found / error.
 *
 * The existing fetchAgentByEmail() collapses a miss, a 429 and an outage into
 * null, which is fine for a login path and wrong for a sync that demotes
 * people: reading "LR is rate-limiting us" as "this agent left" would hide a
 * working agent's listings. So only a clean answer counts, and anything
 * ambiguous is an error that is retried, never acted on.
 *
 * One Http::fake per test on purpose: Http::fake() ACCUMULATES stubs and the
 * first match wins, so faking again in a loop silently re-tests the first case.
 * Every request is faked; nothing reaches LR.
 */
class LrAgentStatusLookupTest extends TestCase
{
    private const URL = 'https://api.leuteriorealty.com/lr/v1/public/api/agent/*';
    private const EMAIL = 'agent@example.test';

    private function lookup(string $email = self::EMAIL): array
    {
        return (new LrApiService())->lookupAgentStatus($email);
    }

    /** A payload as LR returns it for a hit: the agent's own id and email, plus `status`. */
    private function payload(mixed $status, array $extra = []): array
    {
        return array_merge(['id' => 7, 'email' => self::EMAIL, 'status' => $status], $extra);
    }

    public function test_a_hit_returns_the_status_lowercased(): void
    {
        Http::fake([self::URL => Http::response($this->payload(' Terminated '), 200, ['X-RateLimit-Remaining' => '41'])]);

        $result = $this->lookup();

        $this->assertSame('found', $result['result']);
        $this->assertSame('terminated', $result['status']);
        $this->assertSame(41, $result['remaining']);
        $this->assertNull($result['error']);
    }

    public function test_a_hit_with_no_status_is_found_with_an_empty_status(): void
    {
        // About one agent in four has no status in LR. That is an answer, not a miss.
        Http::fake([self::URL => Http::response($this->payload(null), 200)]);

        $result = $this->lookup();

        $this->assertSame('found', $result['result']);
        $this->assertSame('', $result['status']);
    }

    public function test_an_oversized_status_is_capped_to_the_column_width(): void
    {
        Http::fake([self::URL => Http::response($this->payload(str_repeat('resigned ', 20)), 200)]);

        $result = $this->lookup();

        $this->assertSame('found', $result['result']);
        $this->assertLessThanOrEqual(32, mb_strlen($result['status']));
    }

    public function test_the_answer_must_be_about_the_agent_that_was_asked_about(): void
    {
        // A payload for a different address, or one that doesn't say whose it
        // is, is never trusted: demoting on someone else's record is the one
        // mistake that can't be undone by a retry.
        Http::fake([self::URL => Http::response($this->payload('terminated', ['email' => 'someone-else@example.test']), 200)]);
        $other = $this->lookup();

        $this->assertSame('error', $other['result']);
        $this->assertNull($other['status']);
        $this->assertStringContainsString('identity', (string) $other['error']);
    }

    public function test_a_hit_that_does_not_name_its_agent_is_an_error(): void
    {
        Http::fake([self::URL => Http::response(['id' => 7, 'status' => 'terminated'], 200)]);

        $this->assertSame('error', $this->lookup()['result']);
    }

    public function test_the_echoed_email_is_compared_case_and_space_insensitively(): void
    {
        Http::fake([self::URL => Http::response($this->payload('inactive', ['email' => ' Agent@Example.TEST ']), 200)]);

        $this->assertSame('found', $this->lookup()['result']);
    }

    public function test_lr_answers_an_unknown_agent_with_403_user_not_found(): void
    {
        Http::fake([self::URL => Http::response(['message' => 'User not found.', 'success' => false], 403, ['X-RateLimit-Remaining' => '58'])]);

        $result = $this->lookup();

        $this->assertSame('not_found', $result['result']);
        $this->assertNull($result['status']);
        $this->assertSame(403, $result['http_status']);
        $this->assertSame(58, $result['remaining']);
    }

    /** A miss is recognised by its body, whatever status code carries it. */
    #[DataProvider('missCodes')]
    public function test_a_miss_is_recognised_whatever_status_code_carries_it(int $code): void
    {
        Http::fake([self::URL => Http::response(['message' => 'User not found.', 'success' => false], $code)]);

        $this->assertSame('not_found', $this->lookup()['result']);
    }

    public static function missCodes(): array
    {
        return ['403' => [403], '404' => [404], '200' => [200]];
    }

    /** Anything ambiguous must come back as an error — never found, never a miss. */
    #[DataProvider('ambiguousResponses')]
    public function test_anything_ambiguous_is_an_error_never_a_miss(int $code, array|string $body, array $headers): void
    {
        Http::fake([self::URL => Http::response($body, $code, $headers)]);

        $result = $this->lookup();

        $this->assertSame('error', $result['result']);
        $this->assertNull($result['status']);
        $this->assertNotEmpty($result['error']);
    }

    public static function ambiguousResponses(): array
    {
        return [
            '429 rate limited' => [429, '', ['X-RateLimit-Remaining' => '0']],
            '500' => [500, '', []],
            '503 with a body' => [503, 'Service Unavailable', []],
            'a 403 that is not user-not-found' => [403, ['message' => 'Forbidden'], []],
            'a 403 that says success false but nothing else' => [403, ['success' => false], []],
            '200 without a status key' => [200, ['id' => 7, 'email' => self::EMAIL], []],
            '200 that is not JSON' => [200, '<html>maintenance</html>', []],
            '200 with an empty body' => [200, '', []],
            '404 that is not user-not-found' => [404, ['message' => 'Not Found'], []],
        ];
    }

    public function test_a_429_reports_its_status_so_the_caller_can_back_off(): void
    {
        Http::fake([self::URL => Http::response('', 429, ['X-RateLimit-Remaining' => '0'])]);

        $result = $this->lookup();

        $this->assertSame(429, $result['http_status']);
        $this->assertSame(0, $result['remaining']);
    }

    public function test_a_timeout_or_connection_failure_is_an_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $result = $this->lookup();

        $this->assertSame('error', $result['result']);
        $this->assertNull($result['http_status']);
        $this->assertStringContainsString('timed out', $result['error']);
    }

    public function test_the_email_is_trimmed_lowercased_and_url_encoded(): void
    {
        Http::fake([self::URL => Http::response($this->payload('active', ['email' => 'john.doe+fh@example.com']), 200)]);

        $result = $this->lookup('  John.Doe+fh@Example.COM ');

        Http::assertSent(fn (Request $request) => $request->url()
            === 'https://api.leuteriorealty.com/lr/v1/public/api/agent/john.doe%2Bfh%40example.com');
        $this->assertSame('found', $result['result']);
    }

    public function test_the_login_path_helpers_are_untouched(): void
    {
        // The login-time lookups still collapse failures to null; only the
        // new method is tri-state.
        Http::fake([self::URL => Http::response(['message' => 'User not found.', 'success' => false], 403)]);

        $this->assertNull((new LrApiService())->fetchAgentByEmail('agent@example.test'));
    }
}
