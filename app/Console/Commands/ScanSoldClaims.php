<?php

namespace App\Console\Commands;

use App\Services\Reports\Executive\SoldClaims\SoldClaimMatcher;
use App\Services\Reports\Executive\SoldClaims\SoldClaimScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Feeds the Executive Report's "Said it's sold, still listed" list: reads new
 * agent replies in listing chats and stores the ones that say the listing is
 * already sold / rented / taken. Scheduled hourly; resumes from its own
 * watermark, so a missed hour only makes the next run longer.
 *
 * First deploy: `reports:scan-sold-claims --max=0` once to read the whole
 * history (on api2: `sudo -u www-data php artisan …`). To tune the phrase
 * lists against real replies without writing anything:
 * `reports:scan-sold-claims --dry-run --restart --max=5000`.
 */
class ScanSoldClaims extends Command
{
    protected $signature = 'reports:scan-sold-claims
                            {--max=20000 : Stop after this many agent replies (0 = until caught up — the first backfill).}
                            {--dry-run : Show what would be stored; write nothing and leave the watermark where it is.}
                            {--restart : Read again from the first message (a matcher version bump does this by itself).}';

    protected $description = 'Find agent chat replies saying a listing is already sold / rented / taken (Executive Report).';

    public function handle(SoldClaimScanner $scanner): int
    {
        // Scheduled hourly — on a host that hasn't migrated yet, wait quietly instead of failing every hour.
        if (! Schema::hasTable('listing_sold_claims')) {
            $this->warn('listing_sold_claims does not exist yet — run the migrations first.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $scanner->run(max(0, (int) $this->option('max')), $dryRun, (bool) $this->option('restart'));

        if ($result['version_reset']) {
            $this->warn(sprintf('Matcher version %d is new — reading every reply again from the start.', SoldClaimMatcher::VERSION));
        }

        $this->line(sprintf(
            'Read %s agent repl%s (message ids %d → %d): %d strong, %d medium, %d weak claim%s.',
            number_format($result['scanned']),
            $result['scanned'] === 1 ? 'y' : 'ies',
            $result['from'],
            $result['to'],
            $result['found']['strong'],
            $result['found']['medium'],
            $result['found']['weak'],
            array_sum($result['found']) === 1 ? '' : 's',
        ));

        if ($dryRun) {
            if ($result['samples'] !== []) {
                $this->table(['Message', 'Listing', 'Tier', 'Phrase', 'Reply'], array_map(fn ($s) => [
                    $s['message_id'], $s['listing_id'], $s['tier'], $s['phrase'], Str::limit($s['snippet'], 90),
                ], $result['samples']));
            }
            $this->warn('Dry run — nothing was stored and the watermark did not move.');

            return self::SUCCESS;
        }

        if ($result['removed'] > 0 || $result['dropped'] > 0) {
            $this->line(sprintf('Withdrawn: %d no longer match, %d edited / unsent / deleted.', $result['removed'], $result['dropped']));
        }
        $this->line($result['done']
            ? 'Caught up.'
            : sprintf('Stopped at message %d of %d — the next run continues from there.', $result['to'], $result['upper']));

        // This run's counts are only what was NEW — say what is stored in all, so a
        // caught-up run reading 0 doesn't look like the scan found nothing.
        $stored = DB::table('listing_sold_claims')->selectRaw('tier, COUNT(*) as n')->groupBy('tier')->pluck('n', 'tier');
        $this->line(sprintf(
            'Stored in all: %d strong, %d medium, %d weak claim%s.',
            (int) ($stored['strong'] ?? 0),
            (int) ($stored['medium'] ?? 0),
            (int) ($stored['weak'] ?? 0),
            array_sum($stored->all()) === 1 ? '' : 's',
        ));

        Log::info('Sold-claim scan', array_diff_key($result, ['samples' => true]));

        return self::SUCCESS;
    }
}
