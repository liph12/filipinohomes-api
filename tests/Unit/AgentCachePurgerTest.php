<?php

namespace Tests\Unit;

use App\Services\Agent\AgentCachePurger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Pure-HTTP tests for the agent-profile cache purger: per-request dedupe,
 * the commit/terminate deferral, and the degrade-never-throw contract.
 * Everything is Http::fake'd — no real frontend, no queue.
 *
 * AgentCachePurger is a singleton (see AppServiceProvider::register()), so
 * every `app(AgentCachePurger::class)` call in a test resolves the SAME
 * instance — matching how it accumulates ids across one real request.
 */
class AgentCachePurgerTest extends TestCase
{
    private function configurePurger(): void
    {
        config([
            'services.frontend.url' => 'https://filipinohomes.test',
            'services.frontend.revalidation_secret' => 'test-secret',
            'services.frontend.revalidation_timeout' => 5,
        ]);
    }

    public function test_multiple_calls_in_one_request_collapse_into_one_post(): void
    {
        $this->configurePurger();
        Http::fake(['*' => Http::response(['revalidated' => true])]);

        $purger = app(AgentCachePurger::class);
        $purger->purgeAgent(5);
        $purger->purgeAgent(5, true); // same agent again, now with directoryChanged
        $purger->purgeAgent(7);

        // DB::afterCommit() with no open transaction runs immediately, but the
        // actual HTTP flush is deferred to app()->terminating() — nothing has
        // gone out yet even though all three calls above already ran.
        Http::assertNothingSent();

        $this->app->terminate();

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = $request->data();
            $tags = $body['tags'] ?? [];
            sort($tags);
            return $request->url() === 'https://filipinohomes.test/api/revalidate'
                && $request->hasHeader('x-revalidation-token', 'test-secret')
                && ($body['slug'] ?? null) === 'agents'
                && $tags === ['agent-5', 'agent-7', 'agents-index'];
        });
    }

    public function test_rolled_back_transaction_sends_nothing(): void
    {
        $this->configurePurger();
        Http::fake(['*' => Http::response(['revalidated' => true])]);

        try {
            DB::transaction(function () {
                app(AgentCachePurger::class)->purgeAgent(9);
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException $e) {
            // expected — the purge must not have survived the rollback
        }

        $this->app->terminate();

        Http::assertNothingSent();
    }

    public function test_missing_config_sends_nothing(): void
    {
        config([
            'services.frontend.url' => null,
            'services.frontend.revalidation_secret' => null,
        ]);
        Http::fake(['*' => Http::response(['revalidated' => true])]);

        app(AgentCachePurger::class)->purgeAgent(11, true);
        $this->app->terminate();

        Http::assertNothingSent();
    }

    public function test_rejected_response_logs_a_warning_and_does_not_throw(): void
    {
        $this->configurePurger();
        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

        Log::shouldReceive('warning')
            ->once()
            ->with('agent-cache: revalidate rejected', \Mockery::type('array'));

        app(AgentCachePurger::class)->purgeAgent(13);

        // The assertion is really that this line is reached at all — a
        // thrown exception here would fail the test before the expectation
        // above ever gets checked.
        $this->app->terminate();
    }

    public function test_purge_directory_only_sends_the_agents_tag(): void
    {
        $this->configurePurger();
        Http::fake(['*' => Http::response(['revalidated' => true])]);

        app(AgentCachePurger::class)->purgeDirectory();
        $this->app->terminate();

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = $request->data();
            return ($body['slug'] ?? null) === 'agents'
                && ($body['tags'] ?? null) === ['agents-index'];
        });
    }
}
