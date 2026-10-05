<?php

namespace App\Console\Commands;

use App\Services\Inquiry\InquiryAutoApprovalService;
use App\Services\Inquiry\InquiryModerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled drain for pending listing inquiries — approves them unattended
 * during the admin-configured window (weekdays/weekends/all/custom days,
 * optionally restricted hours), reusing the exact same mutation + side
 * effects (InquiryModerationService::accept) a human moderator's Accept
 * button runs: status flip, agent attach, acceptance email/push, audit row.
 *
 * Disabled / outside the window / nothing eligible all exit SUCCESS with an
 * info() line — never FAILURE — matching the house style (see
 * SendWebsiteAnalyticsReport) of never alarming the scheduler over a no-op.
 * Day/hour gating is evaluated HERE, every run, rather than in the schedule
 * registration — a settings change then takes effect on the next five-minute
 * tick with no deploy, and routes/console.php never has to touch the
 * database while artisan boots.
 *
 * Audit: console-context model auditing is off by default
 * (config('audit.console') === false), so the LogsActivity-driven audit row
 * Conversation::update() normally writes would be silently skipped here.
 * This command flips that flag on — as the FIRST thing handle() does, before
 * any Conversation model is touched (see the comment at the top of handle())
 * — for the duration of the run (process-local — never persisted) so the
 * mutation is still recorded under the 'inquiries' category, filterable by
 * its own 'inquiry_auto_approve' source — distinct from a human's
 * 'inquiry_accept' and the existing submit-time 'inquiry_auto_accept' bypass
 * for trusted senders. Visible in the admin System Logs → All Logs screen.
 */
class AutoApproveInquiries extends Command
{
    protected $signature = 'inquiries:auto-approve
        {--dry-run : Report what would happen without writing anything}
        {--limit= : Override max_per_run for this invocation}';

    protected $description = 'Approve pending listing inquiries automatically during the admin-configured window.';

    public function handle(InquiryAutoApprovalService $approval, InquiryModerationService $moderation): int
    {
        // See the class doc — console model-auditing is off by default.
        // This MUST run before any Conversation model is touched (including
        // the eligibility query below): owen-it's auditing package decides
        // whether to register its audit observer for a model class exactly
        // once, the first time that class boots in this process
        // (vendor/owen-it/laravel-auditing/src/Auditable.php — bootAuditable
        // reads audit.console once and only then registers the observer).
        // Flipping the config AFTER Conversation has already booted is too
        // late and silently produces zero audit rows — confirmed by hand
        // against the local dev DB before this ordering fix.
        config(['audit.console' => true]);

        $settings = $approval->settings();

        if (! $settings['enabled']) {
            $this->info('Automatic inquiry approval is disabled — skipping.');

            return self::SUCCESS;
        }

        $now = Carbon::now(InquiryAutoApprovalService::TIMEZONE);
        if (! $approval->isWindowOpen($settings, $now)) {
            $this->info("Outside the configured window ({$approval->summary($settings)}) — skipping.");

            return self::SUCCESS;
        }

        $limitOption = $this->option('limit');
        $limit = $limitOption !== null ? max(1, (int) $limitOption) : null;
        $conversations = $approval->eligible($settings, $limit);

        if ($conversations->isEmpty()) {
            $this->info('No eligible pending inquiries right now.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$conversations->count()} inquiry(ies) would be approved.");
            foreach ($conversations as $conversation) {
                $this->line("  #{$conversation->id} (chat {$conversation->chat_id}, agent {$conversation->agent_user_id})");
            }

            return self::SUCCESS;
        }

        $approved = 0;
        $failed = 0;

        foreach ($conversations as $conversation) {
            try {
                $moderation->accept(
                    $conversation,
                    null,
                    'inquiry_auto_approve',
                    'Automatic approver',
                );
                $approved++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Scheduled inquiry auto-approve failed for one conversation', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $approval->recordRun($approved, $failed);

        $this->info(
            "Auto-approved {$approved} inquiry(ies)."
            . ($failed ? " {$failed} failed — see the log." : ''),
        );

        return self::SUCCESS;
    }
}
