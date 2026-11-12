<?php

namespace App\Console\Commands;

use App\Services\Inquiry\InquiryAiReplyService;
use Illuminate\Console\Command;

/**
 * Inquiry Filipinohomes Assistant pass (every 5 minutes): the "agent will confirm" reply
 * right after approval, and the apology + admin numbers when the agent still
 * hasn't replied. Does nothing unless switched on in System Settings. See
 * App\Services\Inquiry\InquiryAiReplyService.
 */
class InquiryAiReply extends Command
{
    protected $signature = 'inquiries:ai-reply
        {--dry-run : List what would be sent without sending anything}
        {--limit=25 : Max messages this pass}';

    protected $description = 'Reply to approved listing inquiries the agent has not answered yet (Inquiry Filipinohomes Assistant).';

    public function handle(InquiryAiReplyService $service): int
    {
        $out = $service->run((bool) $this->option('dry-run'), max(1, (int) $this->option('limit')));

        if ($out['reason']) {
            $this->info("Inquiry Filipinohomes Assistant: nothing to do ({$out['reason']}).");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: '.count($out['would']).' message(s) would be sent.');
            foreach ($out['would'] as $w) {
                $this->line("  conversation #{$w['conversation_id']} — {$w['stage']}");
            }

            return self::SUCCESS;
        }

        $this->info("Sent {$out['acks']} first reply(ies) and {$out['escalations']} admin-number message(s)."
            .($out['failed'] ? " {$out['failed']} failed — see the log." : ''));

        return self::SUCCESS;
    }
}
