<?php

namespace App\Console\Commands;

use App\Support\FhOneKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Makes a key for FH One and prints it ONCE, with the entry that goes into
 * FHONE_API_KEYS on this server. The key is not stored anywhere here.
 */
class MakeFhOneKey extends Command
{
    protected $signature = 'fhone:make-key
        {name=fh-one : Lower-case name that shows in the logs, e.g. fh-one-2026-11}
        {--days=90 : How many days from today the key works (last day included)}';

    protected $description = 'Make an API key for FH One and print it once, with the FHONE_API_KEYS entry to add on this server';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $days = (int) $this->option('days');

        if (preg_match(FhOneKeys::NAME_PATTERN, $name) !== 1) {
            $this->components->error('The name may only use lower-case letters, digits, dots, dashes and underscores (40 characters at most).');

            return self::FAILURE;
        }

        if ($days < 1 || $days > 366) {
            $this->components->error('--days must be between 1 and 366. Keys are meant to be replaced.');

            return self::FAILURE;
        }

        $lastDay = Carbon::now('Asia/Manila')->addDays($days)->format('Y-m-d');
        $secret = FhOneKeys::generate();

        $this->components->info("Key \"{$name}\" works through {$lastDay} (Manila time).");
        $this->newLine();
        $this->line('1. Put this in FH One\'s settings as FILIPINOHOMES_API_TOKEN. It is shown only now:');
        $this->newLine();
        $this->line('   '.$secret);
        $this->newLine();
        $this->line('2. Add this entry to FHONE_API_KEYS on the FH server (comma separated if there are others):');
        $this->newLine();
        $this->line('   '.FhOneKeys::entry($name, $secret, $lastDay));
        $this->newLine();
        $this->line('Then run `php artisan config:clear` if the config is cached. To replace a key without a gap, add the new entry first, switch FH One, then remove the old one.');

        return self::SUCCESS;
    }
}
