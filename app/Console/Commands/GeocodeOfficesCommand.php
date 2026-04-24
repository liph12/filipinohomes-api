<?php

namespace App\Console\Commands;

use App\Models\Office;
use App\Services\Office\GoogleGeocodingService;
use Illuminate\Console\Command;

class GeocodeOfficesCommand extends Command
{
    protected $signature = 'offices:geocode-missing
        {--all : Re-geocode offices even if geo_coordinates already exists}
        {--limit=0 : Maximum number of offices to process}
        {--dry-run : Preview changes without saving them}
        {--sleep=150 : Delay in milliseconds between API requests}';

    protected $description = 'Geocode office addresses and store coordinates in geo_coordinates';

    public function handle(GoogleGeocodingService $geocoder): int
    {
        if (! $geocoder->hasApiKey()) {
            $this->error('Missing GOOGLE_MAPS_API_KEY in filipinohomes-api/.env.');
            $this->line('Add your key there, then rerun: php artisan offices:geocode-missing --dry-run');

            return self::FAILURE;
        }

        $query = Office::query()->orderBy('id');

        if (! $this->option('all')) {
            $query->whereNull('geo_coordinates');
        }

        $limit = max(0, (int) $this->option('limit'));

        if ($limit > 0) {
            $query->limit($limit);
        }

        $offices = $query->get();

        if ($offices->isEmpty()) {
            $this->info('No offices matched the selected criteria.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sleepMs = max(0, (int) $this->option('sleep'));
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        $this->info(sprintf(
            'Processing %d office%s%s.',
            $offices->count(),
            $offices->count() === 1 ? '' : 's',
            $dryRun ? ' in dry-run mode' : ''
        ));

        foreach ($offices as $office) {
            $address = (string) ($office->address ?? '');

            if (! $geocoder->hasUsableAddress($address)) {
                $this->warn("Skipping [{$office->id}] {$office->name}: unusable address.");
                $skipped++;
                continue;
            }

            try {
                $coordinates = $geocoder->geocode($address);

                if ($coordinates === null) {
                    $this->warn("No geocoding result for [{$office->id}] {$office->name}.");
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("Would update [{$office->id}] {$office->name} -> {$coordinates['lat']}, {$coordinates['lng']}");
                } else {
                    $office->update([
                        'geo_coordinates' => $coordinates,
                    ]);
                    $this->info("Updated [{$office->id}] {$office->name}.");
                }

                $updated++;

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            } catch (\Throwable $e) {
                $this->error("Failed [{$office->id}] {$office->name}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->table(
            ['Updated', 'Skipped', 'Failed', 'Mode'],
            [[(string) $updated, (string) $skipped, (string) $failed, $dryRun ? 'dry-run' : 'write']]
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
