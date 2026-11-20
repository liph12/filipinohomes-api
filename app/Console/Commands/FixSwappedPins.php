<?php

namespace App\Console\Commands;

use App\Support\PhilippinePin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Puts back map pins saved with latitude and longitude the wrong way round
 * (lat 124.0 / lng 10.29 is really Cebu).
 *
 * Where they came from: ~106 projects were imported with the two swapped
 * (added_by "IT Admin", before 2026-04-15), and the listing form copies a
 * project's pin onto a listing when the project is picked, so every listing
 * created or edited with one of them got the swapped pin too.
 *
 * Only pins that land in the Philippines once swapped are touched
 * (PhilippinePin::swapped); 0,0 and other bad pins are left alone. Writes go
 * straight to the two columns: no updated_at, observers or audits, since no
 * one edited these listings.
 *
 *   php artisan pins:fix-swapped           # preview: lists what would change
 *   php artisan pins:fix-swapped --apply   # change them
 */
class FixSwappedPins extends Command
{
    protected $signature = 'pins:fix-swapped
        {--apply : Change the pins (without it, only lists them)}';

    protected $description = 'Swap back project and listing pins saved with lat/lng the wrong way round.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $projects = DB::table('projects')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->orderBy('id')
            ->get(['id', 'name', 'latitude', 'longitude'])
            ->filter(fn ($p) => PhilippinePin::swapped($p->latitude, $p->longitude))
            ->values();

        $properties = DB::table('properties')
            ->whereNotNull('geo_coordinates')
            ->orderBy('id')
            ->get(['id', 'project_id', 'geo_coordinates'])
            ->map(function ($p) {
                $p->geo = json_decode((string) $p->geo_coordinates, true);

                return $p;
            })
            ->filter(fn ($p) => is_array($p->geo) && PhilippinePin::swapped($p->geo['lat'] ?? null, $p->geo['lng'] ?? null))
            ->values();

        $listingCount = $properties->isEmpty() ? 0 : DB::table('listings')
            ->whereIn('property_id', $properties->pluck('id'))
            ->whereNull('deleted_at')
            ->count();

        $this->info("Projects with swapped pins: {$projects->count()}");
        $this->table(
            ['id', 'name', 'saved lat', 'saved lng', 'fixed lat', 'fixed lng'],
            $projects->map(fn ($p) => [$p->id, mb_strimwidth((string) $p->name, 0, 40, '…'), $p->latitude, $p->longitude, $p->longitude, $p->latitude])->all(),
        );

        $this->info("Listing pins (properties) swapped: {$properties->count()}, used by {$listingCount} listings");
        $this->table(
            ['property id', 'project id', 'saved lat', 'saved lng'],
            $properties->map(fn ($p) => [$p->id, $p->project_id ?? '-', $p->geo['lat'], $p->geo['lng']])->all(),
        );

        if (! $apply) {
            $this->warn('Preview only, nothing changed. Run again with --apply to fix these.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($projects, $properties) {
            foreach ($projects as $p) {
                DB::table('projects')->where('id', $p->id)->update([
                    'latitude' => $p->longitude,
                    'longitude' => $p->latitude,
                ]);
            }
            foreach ($properties as $p) {
                // Keep anything else stored beside lat/lng.
                $geo = ['lat' => $p->geo['lng'], 'lng' => $p->geo['lat']] + $p->geo;
                DB::table('properties')->where('id', $p->id)->update(['geo_coordinates' => json_encode($geo)]);
            }
        });

        $this->info("Fixed {$projects->count()} projects and {$properties->count()} listing pins.");

        return self::SUCCESS;
    }
}
