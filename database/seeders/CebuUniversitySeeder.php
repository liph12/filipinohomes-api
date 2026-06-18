<?php

namespace Database\Seeders;

use App\Models\Facility;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Curated Cebu universities for the "near {university}" SEO phase (category
 * "school"). Highest student-housing intent after malls — strong rental demand
 * near campuses, many in the condo-dense Lahug/Banilad/IT Park belt. Coords are
 * filled by `facilities:geocode-missing`. Idempotent (updateOrCreate on slug);
 * re-running it leaves the malls untouched. Add/prune freely — the ≥10 gate
 * drops any campus with too little nearby inventory.
 *
 * Multi-campus universities are listed per campus so each geocodes to its own
 * point (a campus's catchment is what matters, not the institution's HQ).
 */
class CebuUniversitySeeder extends Seeder
{
    public function run(): void
    {
        // [name, city] — province Cebu, category "school".
        $universities = [
            // Cebu City
            ['University of San Carlos - Talamban Campus', 'Cebu City'],
            ['University of San Carlos - Main Campus', 'Cebu City'],
            ['University of San Jose-Recoletos', 'Cebu City'],
            ['Cebu Institute of Technology - University', 'Cebu City'],
            ['University of Cebu - Main Campus', 'Cebu City'],
            ['University of Cebu - Banilad Campus', 'Cebu City'],
            ['University of the Philippines Cebu', 'Cebu City'],
            ['Southwestern University PHINMA', 'Cebu City'],
            ['Cebu Normal University', 'Cebu City'],
            ['Cebu Technological University - Main Campus', 'Cebu City'],
            ['University of the Visayas - Main Campus', 'Cebu City'],
            ['Velez College', 'Cebu City'],
            // Mandaue
            ['Cebu Doctors University', 'Mandaue City'],
            ['University of Cebu - Lapu-Lapu and Mandaue', 'Mandaue City'],
        ];

        foreach ($universities as [$name, $city]) {
            Facility::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name'      => $name,
                    'category'  => 'school',
                    'city'      => $city,
                    'province'  => 'Cebu',
                    'is_active' => true,
                ],
            );
        }
    }
}
