<?php

namespace App\Console\Commands;

use App\Services\Mailbox\MailboxService;
use Illuminate\Console\Command;

/**
 * Archive finished periods of website emails to S3 and remove them from the
 * mail server (see MailboxService / Concerns\ArchivesMailbox). Scheduled
 * daily; does nothing unless MAILBOX_ARCHIVE_ENABLED=true. --dry-run never
 * uploads, changes or deletes anything.
 */
class MailboxArchive extends Command
{
    protected $signature = 'mailbox:archive
        {--dry-run : Only report what would be archived (nothing uploaded, changed or deleted)}
        {--sample=20 : Dry run: download this many emails to check they parse and measure compression}
        {--limit= : Archive at most this many emails this run (plus their duplicate copies)}
        {--restore-index : Add rows for archived emails found in S3 but missing from this database}';

    protected $description = 'Archive old website emails in the shared mailbox to S3 and free the mail server.';

    public function handle(MailboxService $mailbox): int
    {
        if (! $mailbox->isConfigured()) {
            $this->info('Mailbox not configured (MAILBOX_PASSWORD) — skipping.');

            return self::SUCCESS;
        }

        if ($this->option('restore-index')) {
            $added = $mailbox->restoreArchiveIndex(fn (string $l) => $this->line($l));
            $this->info("Added {$added} archived emails from S3 to the Mailbox index.");

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        if (! $dry && ! $mailbox->archiveEnabled()) {
            $this->info('Archive is off (MAILBOX_ARCHIVE_ENABLED=false). Use --dry-run to preview.');

            return self::SUCCESS;
        }
        if (! $mailbox->indexReady()) {
            $this->warn('The Mailbox index has not synced yet — run `php artisan mailbox:sync` first.');

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $started = microtime(true);
        $s = $mailbox->archive($dry, $limit, $dry ? max(0, (int) $this->option('sample')) : 0, fn (string $l) => $this->line($l));

        if (isset($s['skipped'])) {
            $this->warn($s['skipped']);

            return self::SUCCESS;
        }

        $mb = fn (int $b) => number_format($b / 1048576, 1).' MB';
        $this->line($dry ? '<comment>DRY RUN — nothing was uploaded, changed or deleted.</comment>' : '');
        if ($s['periods'] === []) {
            $this->info('Nothing is due for archiving.');

            return self::SUCCESS;
        }

        ksort($s['periods']);
        $this->table(
            ['Period', 'Archive from', 'Emails → S3', 'Duplicate copies (removed)', 'Freed on mail server'],
            collect($s['periods'])->map(fn ($p, $k) => [
                $k,
                substr($p['ready_at'], 0, 10),
                $p['emails'],
                $p['copies'],
                $mb($p['bytes']),
            ])->values()->all(),
        );
        $this->line(sprintf('Total: %d emails + %d copies, %s %s from the mail server.', $s['emails'], $s['copies'], $mb($s['bytes']), $dry ? 'would be freed' : 'freed'));

        if ($dry && $s['sample']['emails'] > 0) {
            $ratio = $s['sample']['raw'] > 0 ? $s['sample']['gz'] / $s['sample']['raw'] : 1;
            $this->line(sprintf(
                'Sample of %d emails: %s → %s gzipped (%.0f%%), %d unreadable. Estimated S3 size: %s.',
                $s['sample']['emails'], $mb($s['sample']['raw']), $mb($s['sample']['gz']), $ratio * 100,
                $s['sample']['unreadable'], $mb((int) (($s['bytes'] - $s['copy_bytes']) * $ratio)),
            ));
            $this->line('Example S3 files:');
            foreach ($s['sample']['keys'] as $k) {
                $this->line('  '.$k);
            }
        }
        if (! $dry) {
            $this->line(sprintf('Stored in S3: %s. Errors (kept on the mail server, retried next run): %d.', $mb($s['stored_bytes']), $s['errors']));
        }
        $this->info(sprintf('Done in %.1fs.', microtime(true) - $started));

        return self::SUCCESS;
    }
}
