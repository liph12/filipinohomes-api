<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Convenience aggregator for the "near {facility}" Cebu pilot — runs the malls,
 * universities, and hospitals seeders in one call so deploys do a single
 * `php artisan db:seed --class=CebuFacilitiesSeeder`. All three are idempotent
 * (updateOrCreate on slug), so re-running is safe.
 *
 * NOTE: this is NOT wired into DatabaseSeeder — never run the bare
 * `php artisan db:seed` on prod (that triggers the full property/data seeders).
 * Always target this class explicitly.
 */
class CebuFacilitiesSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FacilitySeeder::class,        // malls
            CebuUniversitySeeder::class,  // universities (category: school)
            CebuHospitalSeeder::class,    // hospitals
        ]);
    }
}
