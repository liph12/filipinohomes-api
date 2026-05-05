<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Listing;

class ExpireFeaturedListings extends Command
{
    protected $signature   = 'listings:expire-featured';
    protected $description = 'Un-feature listings whose featured_until date has passed';

    public function handle(): int
    {
        $count = Listing::where('is_featured', true)
            ->whereNotNull('featured_until')
            ->where('featured_until', '<', now())
            ->update(['is_featured' => false]);

        $this->info("Expired {$count} featured listing(s).");
        return 0;
    }
}
