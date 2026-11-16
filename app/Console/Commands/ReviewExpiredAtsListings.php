<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\Agent\AgentCachePurger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * A Verified / Fully Verified listing whose ATS has expired goes back to
 * "Pending review": the audit vouched for an ATS that no longer holds.
 *
 * Expired = ats_status 'expired', or the expiration date has come (the same
 * rule Property uses to flip ats_status — that flip is lazy, on load/save, so
 * the date is checked here directly). The listing stays public; only its
 * verified status/badge is withdrawn until an admin re-audits it.
 *
 * Scheduled daily (routes/console.php). --dry-run lists what would change.
 */
class ReviewExpiredAtsListings extends Command
{
    protected $signature = 'listings:review-expired-ats
        {--dry-run : Only list the listings that would go to pending review}';

    protected $description = 'Send Verified / Fully Verified listings with an expired ATS back to pending review.';

    public function handle(AgentCachePurger $purger): int
    {
        $dry = (bool) $this->option('dry-run');
        $today = Carbon::today()->toDateString();
        $moved = 0;
        $agents = [];

        Listing::query()
            ->whereIn('verification_status', ['verified', 'fully_verified'])
            ->whereHas('property', function ($q) use ($today) {
                $q->where('ats_status', 'expired')
                    ->orWhere(fn ($q) => $q->whereNotNull('ats_expiration_date')->whereDate('ats_expiration_date', '<=', $today));
            })
            ->with('property:id,ats_status,ats_expiration_date')
            ->chunkById(200, function ($listings) use ($dry, &$moved, &$agents) {
                foreach ($listings as $listing) {
                    $from = $listing->verification_status;
                    $expiredOn = optional($listing->property)->ats_expiration_date;
                    $expiredOn = $expiredOn ? Carbon::parse($expiredOn)->toDateString() : null;

                    if ($dry) {
                        $this->line("#{$listing->id} {$from} → pending_review (ATS expired {$expiredOn})");
                        $moved++;

                        continue;
                    }

                    try {
                        // Quietly, like the flagged → pending_review resubmission: no
                        // 'updated' audit noise; the custom audit event below records it.
                        // re_submitted_at is left alone: that means the agent resubmitted.
                        $listing->updateQuietly(['verification_status' => 'pending_review']);

                        $listing->auditEvent = 'ats_expired';
                        $listing->isCustomEvent = true;
                        $listing->auditCategoryOverride = 'listings_audit';
                        $listing->auditSource = 'scheduler';
                        $listing->auditDescription = "ATS expired → {$from} listing back to pending_review";
                        $listing->auditCustomOld = ['verification_status' => $from];
                        $listing->auditCustomNew = ['verification_status' => 'pending_review', 'ats_expiration_date' => $expiredOn];
                        Event::dispatch(new AuditCustom($listing));

                        $agents[(int) $listing->agent_id] = true;
                        $moved++;
                    } catch (\Throwable $e) {
                        Log::warning('ATS-expired review: could not update listing', ['listing_id' => $listing->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        // updateQuietly skips Listing's 'updated' hook, which purges the agent cache.
        foreach (array_keys($agents) as $agentId) {
            $purger->purgeAgent($agentId);
        }

        $this->info($dry
            ? "Dry run: {$moved} listing(s) would go to pending review."
            : "{$moved} listing(s) with an expired ATS moved to pending review.");

        return self::SUCCESS;
    }
}
