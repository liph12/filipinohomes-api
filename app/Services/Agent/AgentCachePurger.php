<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells the Next.js site to drop its cached copy of an agent profile (and,
 * when the directory listing itself changed, the /agents index) after a
 * write that affects what those pages show — so an edit is visible within
 * seconds instead of after the ISR window (/agents/{slug} and /agents both
 * revalidate every 10 minutes).
 *
 * ─── Why it is inline and not queued ────────────────────────────────────
 *
 * There is no queue worker on api2 (see app/Mail/MessageNotificationMailer.php
 * and routes/console.php) — a dispatched job would sit in the `jobs` table
 * forever. Under php-fpm, Symfony's Response::send() calls
 * fastcgi_finish_request() and flushes the response to the client BEFORE
 * Laravel's terminate() phase runs, so a synchronous HTTP call made from an
 * `app()->terminating()` callback is genuinely free for the request that
 * triggered it — the visitor already has their response.
 *
 * ─── Why DB::afterCommit() AND terminating(), not just one ─────────────
 *
 * DB::afterCommit() defers recording the purge until the write is actually
 * durable (runs immediately if there's no open transaction, at COMMIT if
 * there is one, and is silently dropped if the transaction rolls back — so
 * a failed save never fires a purge for data that was never persisted).
 * terminating() defers the actual HTTP flush until after the response has
 * been sent, and — because this class accumulates ids on itself across the
 * whole request — collapses a request that touches several agents (a bulk
 * listing import, a save that also updates the agent's user row) into ONE
 * POST instead of one per write.
 *
 * ─── Why it can never break a save ──────────────────────────────────────
 *
 * Same contract as PageBuilderCachePurger / Natcon\LandingCachePurger: the
 * content is already committed by the time this runs, so every failure is
 * logged and swallowed, never thrown. No config → silent no-op (matches
 * local dev, where FRONTEND_URL/REVALIDATION_SECRET are typically unset).
 *
 * Register as a singleton (see AppServiceProvider::register()) so the
 * per-request id/flag accumulation in $pending/$directory actually spans
 * every call made during the same request.
 */
class AgentCachePurger
{
    /** @var array<int, true> agent ids collected so far this request */
    private array $pending = [];

    private bool $directoryChanged = false;

    private bool $flushArmed = false;

    /**
     * Record that this agent's cached pages should be purged once the
     * write is durable. Safe to call multiple times per request (and per
     * agent) — everything collapses into one POST at request end.
     *
     * @param  bool  $directoryChanged  True when this write could also change
     *                                  what /agents shows (new agent, status
     *                                  flip, listing count crossing 0↔1,
     *                                  delete/restore) — purges `agents-index`
     *                                  too, alongside this agent's own tag.
     */
    public function purgeAgent(int $agentId, bool $directoryChanged = false): void
    {
        if ($agentId <= 0 || ! $this->enabled()) {
            return;
        }

        DB::afterCommit(function () use ($agentId, $directoryChanged) {
            $this->pending[$agentId] = true;
            $this->directoryChanged = $this->directoryChanged || $directoryChanged;
            $this->arm();
        });
    }

    /** Purge only the /agents directory (e.g. after the hourly response-metrics recompute touches every agent). */
    public function purgeDirectory(): void
    {
        if (! $this->enabled()) {
            return;
        }

        DB::afterCommit(function () {
            $this->directoryChanged = true;
            $this->arm();
        });
    }

    private function arm(): void
    {
        if ($this->flushArmed) {
            return;
        }
        $this->flushArmed = true;
        app()->terminating(fn () => $this->flush());
    }

    /** Collapses everything recorded this request into as few POSTs as the 50-tag cap allows. */
    private function flush(): void
    {
        $tags = array_map(fn (int $id) => "agent-{$id}", array_keys($this->pending));
        $directoryChanged = $this->directoryChanged;

        // Reset before sending — a failure below must not replay stale ids
        // into a later purge on the same (fpm-reused) worker process.
        $this->pending = [];
        $this->directoryChanged = false;
        $this->flushArmed = false;

        if ($directoryChanged) {
            $tags[] = 'agents-index';
        }

        $tags = array_values(array_unique($tags));
        if ($tags === []) {
            return;
        }

        foreach (array_chunk($tags, 50) as $i => $chunk) {
            // The /agents directory path only needs to ride along on one
            // chunk — revalidateTag already covers the rest.
            $this->send($directoryChanged && $i === 0 ? 'agents' : null, $chunk);
        }
    }

    /**
     * @param  array<string>  $tags
     */
    private function send(?string $slug, array $tags): void
    {
        $secret = (string) config('services.frontend.revalidation_secret');
        $base = rtrim((string) config('services.frontend.url'), '/');

        if ($secret === '' || $base === '') {
            return;
        }

        $payload = ['tags' => $tags];
        if ($slug !== null) {
            $payload['slug'] = $slug;
        }

        try {
            $res = Http::timeout((int) config('services.frontend.revalidation_timeout', 5))
                ->withHeaders(['x-revalidation-token' => $secret])
                ->post($base.'/api/revalidate', $payload);

            if ($res->failed()) {
                Log::warning('agent-cache: revalidate rejected', [
                    'slug' => $slug,
                    'tags' => $tags,
                    'status' => $res->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('agent-cache: revalidate failed', [
                'slug' => $slug,
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function enabled(): bool
    {
        return (string) config('services.frontend.revalidation_secret') !== ''
            && (string) config('services.frontend.url') !== '';
    }
}
