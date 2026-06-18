<?php

namespace Database\Seeders;

use App\Models\Facility;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

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
        // [name, city] — province is Cebu, category is mall for the v1 pilot.
        $malls = [
            // Cebu City
            ['SM City Cebu', 'Cebu City'],
            ['SM Seaside City Cebu', 'Cebu City'],
            ['Ayala Center Cebu', 'Cebu City'],
            ['Robinsons Galleria Cebu', 'Cebu City'],
            ['Robinsons Cybergate Cebu', 'Cebu City'],
            ['Central Bloc Cebu IT Park', 'Cebu City'],
            ['Gaisano Country Mall', 'Cebu City'],
            ['Gaisano Capital SRP', 'Cebu City'],
            ['Il Corso Lifemall', 'Cebu City'],
            ['Banilad Town Centre', 'Cebu City'],
            ['Elizabeth Mall', 'Cebu City'],
            ['Gaisano Metro Colon', 'Cebu City'],
            ['JY Square Mall', 'Cebu City'],
            ['Crossroads Banilad', 'Cebu City'],
            ['NUSTAR Resort and Casino', 'Cebu City'],
            // Mandaue
            ['Parkmall', 'Mandaue City'],
            ['J Centre Mall', 'Mandaue City'],
            ['Pacific Mall Mandaue', 'Mandaue City'],
            ['City Time Square', 'Mandaue City'],
            ['One Pavilion Mall', 'Mandaue City'],
            // Lapu-Lapu / Mactan
            ['Gaisano Grand Mall Mactan', 'Lapu-Lapu City'],
            ['Island Central Mactan', 'Lapu-Lapu City'],
            ['Marina Mall', 'Lapu-Lapu City'],
            // Talisay
            ['Gaisano Fiesta Mall Talisay', 'Talisay City'],
        ];

        foreach ($malls as [$name, $city]) {
            Facility::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name'      => $name,
                    'category'  => 'mall',
                    'city'      => $city,
                    'province'  => 'Cebu',
                    'is_active' => true,
                ],
            );
        }
    }
}
