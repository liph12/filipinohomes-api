<?php

namespace App\Services\Agent;

use App\Jobs\PingIndexNow;
use App\Models\Agent;
use App\Services\IndexNowService;

/**
 * What has to happen after an agent's status really changes, whoever changed it
 * (the admin dropdown, accepting a reactivation request).
 */
class AgentStatusEffects
{
    public function __construct(
        private readonly AgentCachePurger $purger,
        private readonly IndexNowService $indexNow,
    ) {}

    public function afterChange(Agent $agent): void
    {
        // A status flip changes whether this agent's profile/listings are
        // publicly visible at all and whether they appear in the /agents
        // directory — purge regardless of the IndexNow setting below, which is a
        // separate, opt-in concern.
        $this->purger->purgeAgent((int) $agent->id, true);

        // Nudge search engines to recrawl this agent's listing URLs so the
        // now-hidden (blocked) or newly-restored (active) pages leave/return to
        // the index faster than the sitemap revalidate window. Uses raw
        // visibility (NOT publiclyListed(), which would exclude a blocked
        // agent's listings entirely).
        if (! config('services.indexnow.enabled')) {
            return;
        }

        $urls = $agent->listings()
            ->where('visibility', 'public')
            ->pluck('slug')
            ->filter(fn ($s) => is_string($s) && $s !== '' && ! str_starts_with($s, 'tmp-'))
            ->map(fn ($s) => $this->indexNow->listingUrl($s))
            ->values()
            ->all();

        foreach (array_chunk($urls, 10000) as $chunk) {
            PingIndexNow::dispatch($chunk)->afterCommit();
        }
    }
}
