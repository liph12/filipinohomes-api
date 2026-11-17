<?php

namespace App\Console\Commands;

use App\Support\FhOneKeys;
use Illuminate\Console\Command;

/**
 * Which FH One keys this server knows, and when each stops working. Never
 * shows a key or its hash.
 */
class ListFhOneKeys extends Command
{
    protected $signature = 'fhone:keys';

    protected $description = 'List the FH One API keys this server accepts and when they expire';

    public function handle(): int
    {
        $keys = FhOneKeys::configured();

        if ($keys === []) {
            $this->components->warn('No usable key. FH One is locked out: set FHONE_API_KEYS (see `php artisan fhone:make-key`).');

            return self::SUCCESS;
        }

        $this->table(['Name', 'Last valid day', 'Days left', 'Status'], array_map(function (array $key) {
            $left = FhOneKeys::daysLeft($key['expires']);

            return [
                $key['name'],
                $key['expires'],
                $left,
                $left < 0 ? 'EXPIRED' : ($left <= 14 ? 'expires soon' : 'ok'),
            ];
        }, $keys));

        return self::SUCCESS;
    }
}
