<?php

namespace App\Console\Commands;

use App\Mail\AdminActivityReportMailer;
use App\Models\User;
use App\Services\Reports\AdminActivityReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends today's boss activity digest. Scheduled nightly at 23:59 Manila
 * (routes/console.php); the optional {email} argument sends a one-off to a
 * single address instead — the manual test path.
 *
 * Recipients (no argument) = EVERY admin (role_id 1) with an email — the
 * System Users mute (users.admin_emails_muted) is deliberately NOT honored
 * here: that mute exists to stop inquiry-notification spam, and this is one
 * digest at midnight. The report is built ONCE per run; only the greeting
 * name differs per recipient. A failed send is logged and skipped, never
 * aborting the rest of the fan-out.
 */
class SendActivityReport extends Command
{
    protected $signature = 'reports:send-activity {email? : Send only to this address (test mode)}';

    protected $description = "Email today's site activity report to all admins (or one address).";

    public function handle(AdminActivityReportService $reports): int
    {
        // "Today" in the business timezone — at the 23:59 Manila run this is
        // the day that is just ending, regardless of the server clock (UTC).
        $today = now('Asia/Manila')->toDateString();
        $label = now('Asia/Manila')->format('M j, Y');
        $report = $reports->build($today, $today);

        $recipients = ($only = $this->argument('email'))
            ? collect([(object) ['email' => $only, 'name' => null]])
            : User::where('role_id', 1)
                ->whereNotNull('email')
                ->get(['id', 'name', 'email']);

        $sent = 0;
        $failed = 0;
        foreach ($recipients as $user) {
            try {
                Mail::to($user->email)->send(new AdminActivityReportMailer(
                    report: $report,
                    periodLabel: $label,
                    recipientName: trim((string) $user->name) ?: 'Boss',
                ));
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Activity report send failed', ['to' => $user->email, 'error' => $e->getMessage()]);
            }
        }

        Log::info('Activity report run finished', ['date' => $today, 'sent' => $sent, 'failed' => $failed]);
        $this->info("Activity report for {$label}: sent {$sent}, failed {$failed}.");

        return $failed > 0 && $sent === 0 ? self::FAILURE : self::SUCCESS;
    }
}
