<?php

namespace App\Console\Commands;

use App\Models\Facility;
use App\Services\Office\GoogleGeocodingService;
use Illuminate\Console\Command;

/**
 * Fills `facilities.lat/lng` by geocoding "{name}, {city}, {province}". Mirrors
 * GeocodeOfficesCommand. One-time + whenever new facilities are added; ~$0.005
 * per facility, so trivially cheap for a curated registry.
 */
class GeocodeFacilitiesCommand extends Command
{
    protected $signature = 'facilities:geocode-missing
        {--all : Re-geocode facilities even if lat/lng already exist}
        {--limit=0 : Maximum number of facilities to process}
        {--dry-run : Preview changes without saving them}
        {--sleep=150 : Delay in milliseconds between API requests}';

    protected $description = 'Geocode curated facilities and store their coordinates.';

    public function handle(GoogleGeocodingService $geocoder): int
    {
        if (! $geocoder->hasApiKey()) {
            $this->error('Missing GOOGLE_MAPS_API_KEY in filipinohomes-api/.env.');

            return self::FAILURE;
        }

        $query = Facility::query()->orderBy('id');

        if (! $this->option('all')) {
            $query->where(function ($q) {
                $q->whereNull('lat')->orWhereNull('lng');
            });
        }

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $query->limit($limit);
        }

        $facilities = $query->get();

        if ($facilities->isEmpty()) {
            $this->info('No facilities matched the selected criteria.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sleepMs = max(0, (int) $this->option('sleep'));
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        $this->info(sprintf(
            'Processing %d facilit%s%s.',
            $facilities->count(),
            $facilities->count() === 1 ? 'y' : 'ies',
            $dryRun ? ' in dry-run mode' : ''
        ));

        foreach ($facilities as $facility) {
            $address = trim(implode(', ', array_filter([
                $facility->name,
                $facility->city,
                $facility->province,
            ])));

            if (! $geocoder->hasUsableAddress($address)) {
                $this->warn("Skipping [{$facility->id}] {$facility->name}: unusable address.");
                $skipped++;
                continue;
            }

            try {
                $coordinates = $geocoder->geocode($address);

                if ($coordinates === null) {
                    $this->warn("No geocoding result for [{$facility->id}] {$facility->name}.");
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("Would update [{$facility->id}] {$facility->name} -> {$coordinates['lat']}, {$coordinates['lng']}");
                } else {
                    $facility->update([
                        'lat' => $coordinates['lat'],
                        'lng' => $coordinates['lng'],
                    ]);
                    $this->info("Updated [{$facility->id}] {$facility->name}.");
                }

                $updated++;

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            } catch (\Throwable $e) {
                $this->error("Failed [{$facility->id}] {$facility->name}: {$e->getMessage()}");
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
