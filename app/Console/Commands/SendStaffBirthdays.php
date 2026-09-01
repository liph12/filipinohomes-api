<?php

namespace App\Console\Commands;

use App\Mail\StaffBirthdaysMailer;
use App\Models\User;
use App\Services\Reports\StaffBirthdaysService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Daily staff-birthdays email — same recipient policy as reports:send-activity:
 * EVERY admin with an email, the System Users mute deliberately not honored
 * (it exists for inquiry spam; this is one email a day). {email} sends a
 * one-off test instead.
 */
class SendStaffBirthdays extends Command
{
    protected $signature = 'reports:send-birthdays {email? : Send only to this address (test mode)}';

    protected $description = "Email today's + upcoming staff birthdays to all admins (or one address).";

    public function handle(StaffBirthdaysService $birthdays): int
    {
        $today = now('Asia/Manila')->toDateString();
        $label = now('Asia/Manila')->format('M j, Y');
        $data = $birthdays->build($today);

        $recipients = ($only = $this->argument('email'))
            ? collect([(object) ['email' => $only, 'name' => null]])
            : User::where('role_id', 1)->whereNotNull('email')->get(['id', 'name', 'email']);

        $sent = 0;
        $failed = 0;
        foreach ($recipients as $user) {
            try {
                Mail::to($user->email)->send(new StaffBirthdaysMailer(
                    birthdays: $data,
                    dateLabel: $label,
                    recipientName: trim((string) $user->name) ?: 'Boss',
                ));
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Staff birthdays send failed', ['to' => $user->email, 'error' => $e->getMessage()]);
            }
        }

        Log::info('Staff birthdays run finished', ['date' => $today, 'sent' => $sent, 'failed' => $failed]);
        $this->info("Staff birthdays for {$label}: sent {$sent}, failed {$failed}.");

        return $failed > 0 && $sent === 0 ? self::FAILURE : self::SUCCESS;
    }
}
