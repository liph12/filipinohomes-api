<?php

namespace Database\Seeders;

use App\Models\Facility;
use Illuminate\Database\Seeder;

/**
 * Curated Cebu malls for the "near {facility}" SEO pilot. Coordinates are left
 * null here and filled by `php artisan facilities:geocode-missing`. Idempotent
 * (updateOrCreate on slug) so it can be re-run after editing the list.
 *
 * Add/prune freely — gating drops any facility with <10 nearby listings, so an
 * extra entry that turns out thin simply won't generate a page.
 */
class FacilitySeeder extends Seeder
{
    public function run(): void
    {
        // Each entry carries an EXPLICIT, STABLE slug. updateOrCreate keys on the
        // slug (not Str::slug($name)), so a rebrand = change `name`, keep `slug`,
        // append the old name to `aliases` (and the old slug to `former_slugs`)
        // — the row updates in place and the URL never forks. province is Cebu,
        // category is mall for the v1 pilot. Optional: aliases, former_slugs.
        $malls = [
            // Cebu City
            ['name' => 'SM City Cebu', 'slug' => 'sm-city-cebu', 'city' => 'Cebu City'],
            ['name' => 'SM Seaside City Cebu', 'slug' => 'sm-seaside-city-cebu', 'city' => 'Cebu City'],
            ['name' => 'Ayala Center Cebu', 'slug' => 'ayala-center-cebu', 'city' => 'Cebu City'],
            ['name' => 'Robinsons Galleria Cebu', 'slug' => 'robinsons-galleria-cebu', 'city' => 'Cebu City'],
            ['name' => 'Robinsons Cybergate Cebu', 'slug' => 'robinsons-cybergate-cebu', 'city' => 'Cebu City'],
            ['name' => 'Central Bloc Cebu IT Park', 'slug' => 'central-bloc-cebu-it-park', 'city' => 'Cebu City'],
            ['name' => 'Gaisano Country Mall', 'slug' => 'gaisano-country-mall', 'city' => 'Cebu City'],
            ['name' => 'Gaisano Capital SRP', 'slug' => 'gaisano-capital-srp', 'city' => 'Cebu City'],
            ['name' => 'Il Corso Lifemall', 'slug' => 'il-corso-lifemall', 'city' => 'Cebu City'],
            ['name' => 'Banilad Town Centre', 'slug' => 'banilad-town-centre', 'city' => 'Cebu City'],
            ['name' => 'Elizabeth Mall', 'slug' => 'elizabeth-mall', 'city' => 'Cebu City'],
            ['name' => 'Gaisano Metro Colon', 'slug' => 'gaisano-metro-colon', 'city' => 'Cebu City'],
            ['name' => 'JY Square Mall', 'slug' => 'jy-square-mall', 'city' => 'Cebu City'],
            ['name' => 'Crossroads Banilad', 'slug' => 'crossroads-banilad', 'city' => 'Cebu City'],
            ['name' => 'NUSTAR Resort and Casino', 'slug' => 'nustar-resort-and-casino', 'city' => 'Cebu City'],
            // Mandaue
            ['name' => 'Parkmall', 'slug' => 'parkmall', 'city' => 'Mandaue City'],
            // Rebranded 2024: J Centre Mall → SM J Mall (officially SM City J Mall).
            ['name' => 'SM J Mall', 'slug' => 'sm-j-mall', 'city' => 'Mandaue City',
                'aliases' => ['J Centre Mall', 'SM City J Mall'], 'former_slugs' => ['j-centre-mall']],
            ['name' => 'Pacific Mall Mandaue', 'slug' => 'pacific-mall-mandaue', 'city' => 'Mandaue City'],
            ['name' => 'City Time Square', 'slug' => 'city-time-square', 'city' => 'Mandaue City'],
            ['name' => 'One Pavilion Mall', 'slug' => 'one-pavilion-mall', 'city' => 'Mandaue City'],
            // Lapu-Lapu / Mactan
            ['name' => 'Gaisano Grand Mall Mactan', 'slug' => 'gaisano-grand-mall-mactan', 'city' => 'Lapu-Lapu City'],
            ['name' => 'Island Central Mactan', 'slug' => 'island-central-mactan', 'city' => 'Lapu-Lapu City'],
            ['name' => 'Marina Mall', 'slug' => 'marina-mall', 'city' => 'Lapu-Lapu City'],
            // Talisay
            ['name' => 'Gaisano Fiesta Mall Talisay', 'slug' => 'gaisano-fiesta-mall-talisay', 'city' => 'Talisay City'],
        ];

        foreach ($malls as $m) {
            // NOTE: lat/lng are intentionally NOT written here — they are filled by
            // `facilities:geocode-missing` and must be PRESERVED across re-seeds
            // (a rebrand keeps the same physical coordinates).
            Facility::updateOrCreate(
                ['slug' => $m['slug']],
                [
                    'name'         => $m['name'],
                    'category'     => 'mall',
                    'city'         => $m['city'],
                    'province'     => 'Cebu',
                    'is_active'    => true,
                    'aliases'      => $m['aliases'] ?? null,
                    'former_slugs' => $m['former_slugs'] ?? null,
                ],
            );
        }
    }
}
