<?php

namespace App\Console\Commands;

use App\Mail\AtsExpiryMailer;
use App\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendAtsExpiryReminders extends Command
{
    protected $signature   = 'ats:expiry-reminders';
    protected $description = 'Email agents when a listing ATS is about to expire (7 days out) or has expired today';

    public function handle(): int
    {
        $today = Carbon::today();

        // 7 days out: still-approved ATS about to lapse → reminder.
        $soon = $this->notifyForDate($today->copy()->addDays(7), 'soon', ['approve']);

        // Expiring today: it lapses at midnight of this date (the model also
        // auto-flips ats_status to "expired" once past) → expired notice.
        $expired = $this->notifyForDate($today->copy(), 'expired', ['approve', 'expired']);

        $this->info("ATS reminders sent — expiring soon: {$soon}, expired: {$expired}.");
        return self::SUCCESS;
    }

    /**
     * Email the agent for every non-trashed listing whose ATS expires on $date
     * and whose ATS status is one of $statuses.
     */
    private function notifyForDate(Carbon $date, string $mode, array $statuses): int
    {
        $sent = 0;

        Listing::query()
            ->whereHas('property', function ($q) use ($date, $statuses) {
                $q->whereDate('ats_expiration_date', $date->toDateString())
                  ->whereIn('ats_status', $statuses);
            })
            ->with(['property', 'agent.user.role'])
            ->chunkById(100, function ($listings) use ($mode, &$sent) {
                foreach ($listings as $listing) {
                    if ($this->sendFor($listing, $mode)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    private function sendFor(Listing $listing, string $mode): bool
    {
        try {
            $agentUser = optional($listing->agent)->user;
            if (! $agentUser || ! $agentUser->email) {
                return false;
            }

            $expRaw     = optional($listing->property)->ats_expiration_date;
            $expiration = $expRaw ? Carbon::parse($expRaw)->format('F j, Y') : null;

            $photos        = $listing->featured_photo; // cast to array on the model
            $featuredPhoto = is_array($photos) && count($photos) > 0 ? $photos[0] : null;

            $roleSegment = optional($agentUser->role)->name === 'admin' ? 'admin' : 'agent';
            $listingUrl  = 'https://filipinohomes.com/'.$roleSegment.'/create-listing?edit='.$listing->id;

            Mail::to($agentUser->email)->send(new AtsExpiryMailer(
                mode: $mode,
                agentName: $agentUser->name ?? 'Agent',
                listingTitle: $listing->name,
                listingCode: $listing->code,
                atsExpiration: $expiration,
                atsRemarks: optional($listing->property)->ats_remarks,
                listingUrl: $listingUrl,
                featuredPhoto: $featuredPhoto,
            ));

            Log::info('ATS expiry email sent', [
                'listing_id' => $listing->id,
                'mode'       => $mode,
                'to'         => $agentUser->email,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::warning('ATS expiry email failed', [
                'listing_id' => $listing->id,
                'mode'       => $mode,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }
}
