<?php

namespace App\Console\Commands;

use App\Mail\AgentBirthdayGreetingMailer;
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
 */
class SendAgentBirthdayGreetings extends Command
{
    protected $signature = 'birthdays:send-greetings
        {email? : Send one sample greeting to this address (test mode)}
        {--agent= : In test mode, use this agents.id instead of the auto-picked real agent}';

    protected $description = "Email today's birthday agents their personal greeting + poster (or one sample to an address).";

    public function handle(AgentBirthdayGreetingService $service, BirthdayPosterService $poster): int
    {
        $today = now('Asia/Manila')->toDateString();
        $celebrants = $service->celebrants($today);

        if ($only = $this->argument('email')) {
            $sample = $service->sampleCelebrant($today, $this->option('agent') ? (int) $this->option('agent') : null);
            if (! $sample) {
                $this->error('No active agent with a birthdate found to use as a sample.');

                return self::FAILURE;
            }
            $p = $poster->renderToS3($sample['full_name'], $sample['avatar'], 'birthday-greetings/test');
            Mail::to($only)->send(new AgentBirthdayGreetingMailer($sample['first_name'], $sample['full_name'], $p['url'] ?? null, $p['jpeg'] ?? null));
            $this->info("Sample greeting for {$sample['full_name']} (agent #{$sample['agent_id']}, real email {$sample['email']}) sent to {$only}.".($p ? " Poster: {$p['url']}" : ' (poster failed — see log)'));

            return self::SUCCESS;
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
                Mail::to($c['email'])->send(new AgentBirthdayGreetingMailer($c['first_name'], $c['full_name'], $p['url'] ?? null, $p['jpeg'] ?? null));
                $sent++;
            } catch (\Throwable $e) {
                Cache::forget($marker);
                $failed++;
                Log::warning('Agent birthday greeting failed', ['agent_id' => $c['agent_id'], 'to' => $c['email'], 'error' => $e->getMessage()]);
            }
        }

        Log::info('Agent birthday greetings run finished', compact('today', 'sent', 'skipped', 'failed'));
        $this->info("Birthday greetings for {$today}: ".count($celebrants)." celebrant(s), sent {$sent}, skipped {$skipped}, failed {$failed}.");

        return $failed > 0 && $sent === 0 ? self::FAILURE : self::SUCCESS;
    }
}
