<?php

namespace App\Natcon\Console;

use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\Recipient;
use App\Natcon\Services\AwardeeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fills in the Leuterio Realty snapshot for recipients that don't have one yet.
 *
 * Import deliberately doesn't call LR (they rate-limit to 60 req/min from a single
 * IP), so rows arrive as lr_lookup_status='pending' and this drains them at a safe
 * pace. Also retries 'error' rows, which is the whole reason AwardeeService
 * distinguishes error from not_found: a 429 or a timeout must be tried again, and
 * a genuine miss must not be.
 *
 * Idempotent and self-limiting, so it can run hourly forever.
 */
class HydrateAwardees extends Command
{
    protected $signature = 'natcon:hydrate-awardees
                            {--event=        : NatconEvent slug (default: the active event)}
                            {--limit=200     : Maximum lookups this run}
                            {--fresh         : Bypass the local cache}
                            {--retry-found   : Also refresh rows already marked found}
                            {--dry-run       : Report what would be looked up, call nothing}';

    protected $description = 'Fetch Leuterio Realty awardee details for NATCON recipients that are missing them';

    public function handle(AwardeeService $awardees): int
    {
        $event = $this->resolveEvent();
        if (! $event) {
            $this->info('No active NATCON event — nothing to hydrate.');
            return self::SUCCESS;
        }

        $limit  = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $fresh  = (bool) $this->option('fresh');

        $statuses = [Recipient::LR_PENDING, Recipient::LR_ERROR];
        if ($this->option('retry-found')) {
            $statuses[] = Recipient::LR_FOUND;
        }

        $recipients = Recipient::query()
            ->where('natcon_event_id', $event->id)
            ->whereIn('lr_lookup_status', $statuses)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($recipients->isEmpty()) {
            $this->info('Nothing to hydrate.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[dry-run] would look up {$recipients->count()} recipient(s):");
            foreach ($recipients as $r) {
                $this->line("  {$r->email} (currently {$r->lr_lookup_status})");
            }
            return self::SUCCESS;
        }

        // Stay inside LR's 60/min ceiling. Sleeping between calls rather than
        // firing concurrently is deliberate: their limit is per source IP and api2
        // has exactly one, shared with every other LR integration on the box.
        $sleep    = AwardeeService::throttleMicroseconds();
        $found    = 0;
        $missing  = 0;
        $errored  = 0;

        foreach ($recipients as $i => $recipient) {
            if ($i > 0) {
                usleep($sleep);
            }

            try {
                $awardees->hydrate($recipient, $fresh);

                match ($recipient->fresh()->lr_lookup_status) {
                    Recipient::LR_FOUND     => $found++,
                    Recipient::LR_NOT_FOUND => $missing++,
                    default                       => $errored++,
                };
            } catch (\Throwable $e) {
                $errored++;
                Log::warning('NATCON hydrate failed', [
                    'recipient_id' => $recipient->id,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        $this->info("NATCON hydrate — found: {$found}, not on LR list: {$missing}, errors: {$errored}.");

        if ($errored > 0) {
            $this->warn("{$errored} lookup(s) errored and will be retried on the next run.");
        }

        return self::SUCCESS;
    }

    private function resolveEvent(): ?NatconEvent
    {
        $slug = $this->option('event');

        return $slug
            ? NatconEvent::where('slug', $slug)->first()
            : NatconEvent::active();
    }
}
