<?php

namespace App\Console\Commands;

use App\Mail\WebsiteAnalyticsReportMailer;
use App\Services\Reports\WebsiteAnalyticsReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the daily Website Analytics (GA4) email. Scheduled daily at the
 * admin-configured Manila time (routes/console.php reads sendTime()); the
 * optional {email} argument sends a one-off to a single address instead —
 * the dashboard's "Send test to me" path — bypassing the enabled flag but
 * not the GA-configured check.
 *
 * Real sends go TO info@filipinohomes.com with every recipient on BCC (the
 * standing admin-email rule: recipient lists never ride in TO/CC).
 */
class SendWebsiteAnalyticsReport extends Command
{
    protected $signature = 'reports:send-website-analytics {email? : Send only to this address (test mode)}';

    protected $description = "Email yesterday's Google Analytics website report to the configured recipients (or one address).";

    public function handle(WebsiteAnalyticsReportService $reports): int
    {
        if (! $reports->configured()) {
            // Not an error state for the scheduler: GA is simply not wired
            // yet (missing GA4_PROPERTY_ID / GA_SA_KEY_BASE64).
            $this->warn('Google Analytics is not configured — skipping.');

            return self::SUCCESS;
        }

        $testEmail = $this->argument('email');
        $settings = $reports->settings();

        if (! $testEmail) {
            if (! $settings['enabled']) {
                $this->info('Website analytics report is disabled — skipping.');

                return self::SUCCESS;
            }
            if (empty($settings['recipients'])) {
                $this->info('No recipients configured — skipping.');

                return self::SUCCESS;
            }
        }

        $report = $reports->build();

        try {
            if ($testEmail) {
                Mail::to($testEmail)->send(new WebsiteAnalyticsReportMailer($report));
                $this->info("Test report sent to {$testEmail}.");
            } else {
                Mail::to(env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com'))
                    ->bcc($settings['recipients'])
                    ->send(new WebsiteAnalyticsReportMailer($report));
                $this->info('Report sent to '.count($settings['recipients']).' recipient(s) via BCC.');
            }
        } catch (\Throwable $e) {
            Log::warning('Website analytics report send failed', ['error' => $e->getMessage()]);
            $this->error('Send failed: '.$e->getMessage());

            return self::FAILURE;
        }

        Log::info('Website analytics report run finished', [
            'date' => $report['date_label'],
            'test' => (bool) $testEmail,
            'recipients' => $testEmail ? 1 : count($settings['recipients']),
        ]);

        return self::SUCCESS;
    }
}
