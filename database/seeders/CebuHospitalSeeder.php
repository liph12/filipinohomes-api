<?php

namespace Database\Seeders;

use App\Models\Facility;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Curated Cebu hospitals for the "near {hospital}" SEO phase (category
 * "hospital"). Solid #2 intent after malls/universities — medical staff and
 * families value proximity to a major hospital. Coords filled by
 * `facilities:geocode-missing`. Idempotent (updateOrCreate on slug); re-running
 * leaves malls + schools untouched. The ≥10 gate drops any with thin inventory.
 *
 * Only major, well-known hospitals (real "near X" search intent) — clinics and
 * minor facilities are intentionally excluded.
 */
class CebuHospitalSeeder extends Seeder
{
    public function run(): void
    {
        // [name, city] — province Cebu, category "hospital".
        $hospitals = [
            // Cebu City
            ['Chong Hua Hospital', 'Cebu City'],
            ['Perpetual Succour Hospital', 'Cebu City'],
            ['Cebu Doctors University Hospital', 'Cebu City'],
            ['Vicente Sotto Memorial Medical Center', 'Cebu City'],
            ['Cebu City Medical Center', 'Cebu City'],
            ['Adventist Hospital Cebu', 'Cebu City'],
            ['Cebu Velez General Hospital', 'Cebu City'],
            ['Visayas Community Medical Center', 'Cebu City'],
            // Mandaue
            ['Chong Hua Hospital Mandaue', 'Mandaue City'],
            ['UC Medical Center', 'Mandaue City'],
            ['Sacred Heart Hospital Mandaue', 'Mandaue City'],
            // Lapu-Lapu / Mactan
            ['Mactan Doctors Hospital', 'Lapu-Lapu City'],
        ];

        foreach ($hospitals as [$name, $city]) {
            Facility::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name'      => $name,
                    'category'  => 'hospital',
                    'city'      => $city,
                    'province'  => 'Cebu',
                    'is_active' => true,
                ],
            );
        }
    }
}
