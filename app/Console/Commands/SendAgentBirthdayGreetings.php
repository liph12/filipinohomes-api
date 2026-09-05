<?php

namespace App\Console\Commands;

use App\Mail\AgentBirthdayGreetingMailer;
use App\Services\AuditMailService;
use App\Services\Birthday\AgentBirthdayGreetingService;
use App\Services\Birthday\BirthdayPosterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Daily 07:00 (Asia/Manila): email every active agent whose birthday is
 * today a personal greeting with their composited poster (photo + name).
 * Distinct from reports:send-birthdays, which is the admin digest.
 *
 * A per-agent/day cache marker stops a re-run from greeting anyone twice.
 * {email} is test mode: renders a REAL agent (today's first celebrant, else
 * the next upcoming birthday, or --agent=ID) and sends only to that address,
 * never to the agent, and never sets the marker.
 *
 * ─── Send modes (config/birthdays.php) ──────────────────────────────────────
 *   off       — resolves celebrants, applies every gate, reports what it WOULD
 *               send, sends nothing. Ships this way; flipping it is deliberate.
 *   whitelist — everything redirected to birthdays.test_recipients, with the
 *               real recipient named in the subject prefix.
 *   live      — real greetings.
 *
 * {email} ignores the mode: it is the "show me one" escape hatch.
 */
class SendAgentBirthdayGreetings extends Command
{
    protected $signature = 'birthdays:send-greetings
        {email? : Send one sample greeting to this address (test mode)}
        {--agent= : In test mode, use this agents.id instead of the auto-picked real agent}
        {--date= : Treat this Y-m-d as today}
        {--dry-run : Resolve and report, send nothing}';

    protected $description = "Email today's birthday agents their personal greeting + poster (or one sample to an address).";

    public function handle(AgentBirthdayGreetingService $service, BirthdayPosterService $poster, AuditMailService $audit): int
    {
        $mode = (string) config('birthdays.send_mode', 'off');

        if (! in_array($mode, ['off', 'whitelist', 'live'], true)) {
            $this->error("Unknown BIRTHDAY_SEND_MODE '{$mode}'. Expected off, whitelist or live.");

            return self::FAILURE;
        }

        $today = (string) ($this->option('date') ?: now('Asia/Manila')->toDateString());
        $celebrants = $service->celebrants($today);

        if ($only = $this->argument('email')) {
            $sample = $service->sampleCelebrant($today, $this->option('agent') ? (int) $this->option('agent') : null);
            if (! $sample) {
                $this->error('No active agent with a birthdate found to use as a sample.');

                return self::FAILURE;
            }
            $p = $poster->renderToS3($sample['full_name'], $sample['avatar'], 'birthday-greetings/test');
            Mail::to($only)->send(new AgentBirthdayGreetingMailer($sample['first_name'], $sample['full_name'], $p['url'] ?? null));
            $this->info("Sample greeting for {$sample['full_name']} (agent #{$sample['agent_id']}, real email {$sample['email']}) sent to {$only}.".($p ? " Poster: {$p['url']}" : ' (poster failed — see log)'));

            return self::SUCCESS;
        }

        // `off` collapses into a dry run: every query, gate and poster lookup
        // still runs, so this exercises the real path — only Mail::send is
        // skipped.
        $dryRun = (bool) $this->option('dry-run') || $mode === 'off';

        if ($dryRun) {
            $this->warn($mode === 'off'
                ? 'BIRTHDAY_SEND_MODE=off — nothing will be sent.'
                : 'Dry run — nothing will be sent.');
            foreach ($celebrants as $c) {
                $this->line("  {$c['full_name']} <{$c['email']}>");
            }
            $this->info("Birthday greetings for {$today}: ".count($celebrants).' celebrant(s), 0 sent (dry run).');
            Log::info('Agent birthday greetings dry run', [
                'date' => $today,
                'mode' => $mode,
                'would_send' => count($celebrants),
            ]);

            return self::SUCCESS;
        }

        $whitelist = (array) config('birthdays.test_recipients');
        if ($mode === 'whitelist' && $whitelist === []) {
            $this->error('BIRTHDAY_SEND_MODE=whitelist but BIRTHDAY_TEST_RECIPIENTS is empty.');

            return self::FAILURE;
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($celebrants as $c) {
            $marker = "birthday-greeting:{$today}:{$c['agent_id']}";
            if (! Cache::add($marker, 1, now()->addDays(2))) {
                $skipped++;

                continue;
            }
            try {
                $p = $poster->forAgent($c['agent_id'], $c['full_name'], $c['avatar'], $today);
                $mailable = new AgentBirthdayGreetingMailer(
                    $c['first_name'],
                    $c['full_name'],
                    $p['url'] ?? null,
                    $mode === 'whitelist' ? "[TEST -> {$c['email']}]" : null,
                );
                Mail::to($mode === 'whitelist' ? $whitelist : $c['email'])->send($mailable);
                $sent++;
            } catch (\Throwable $e) {
                Cache::forget($marker);
                $failed++;
                // Without this the activity log shows every success and
                // silently omits every bounce — half the audit trail. Note
                // class_basename, NOT ::class: recordFailure stores this raw as
                // audits.source, while the success path derives the same column
                // from the X-FH-Mailer header, which is already class_basename.
                // The FQCN would file sends and failures under two different
                // filter buckets.
                $audit->recordFailure(
                    $e,
                    class_basename(AgentBirthdayGreetingMailer::class),
                    [$c['email']],
                    "Happy Birthday, {$c['first_name']}!",
                    ['auditable_type' => \App\Models\Agent::class, 'auditable_id' => $c['agent_id']],
                );
                Log::warning('Agent birthday greeting failed', ['agent_id' => $c['agent_id'], 'to' => $c['email'], 'error' => $e->getMessage()]);
            }
        }

        Log::info('Agent birthday greetings run finished', compact('today', 'mode', 'sent', 'skipped', 'failed'));
        $this->info("Birthday greetings for {$today}: ".count($celebrants)." celebrant(s), sent {$sent}, skipped {$skipped}, failed {$failed}.");

        return $failed > 0 && $sent === 0 ? self::FAILURE : self::SUCCESS;
    }
}
