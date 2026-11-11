<?php

namespace App\Console\Commands;

use App\Services\Mailbox\MailboxService;
use Illuminate\Console\Command;

/**
 * Copies the shared mailbox's website emails (headers + read/star state) into
 * `mailbox_messages`, so /admin/mailbox reads the database instead of logging
 * in to the mail server on every click. Scheduled every minute
 * (routes/console.php); one mail-server login per run.
 */
class MailboxSync extends Command
{
    protected $signature = 'mailbox:sync
        {--folder=* : Only these folder keys (inbox, sent, spam, trash, drafts, archive)}
        {--link-all : One-time catch-up: link every older inquiry email to its inquiry now}';

    protected $description = 'Sync the admin Mailbox index (website emails in info@) from the mail server.';

    public function handle(MailboxService $mailbox): int
    {
        if (! $mailbox->isConfigured()) {
            $this->info('Mailbox not configured (MAILBOX_PASSWORD) — skipping.');

            return self::SUCCESS;
        }

        if ($this->option('link-all')) {
            $linked = $mailbox->linkAllInquiryEmails(fn ($done, $left) => $this->line("linked {$done} so far, {$left} were left before this batch"));
            $this->info("Done: linked {$linked} older inquiry emails.");

            return self::SUCCESS;
        }

        $started = microtime(true);
        $folders = $this->option('folder') ?: null;
        $stats = $mailbox->sync($folders);

        foreach ($stats as $folder => $s) {
            $this->line(match (true) {
                ! is_array($s) => "{$folder}: {$s}",
                ! empty($s['skipped']) => sprintf('%-8s unchanged', $folder),
                default => sprintf('%-8s +%d new  -%d removed  ~%d read/star changes', $folder, $s['added'], $s['removed'], $s['updated']),
            });
        }
        $this->info(sprintf('Mailbox synced in %.1fs.', microtime(true) - $started));

        return self::SUCCESS;
    }
}
