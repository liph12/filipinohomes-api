<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PropertySubtypeSeeder extends Seeder
{
    public function run(): void
    {
        // Subtypes grouped by property_type_id, with fixed IDs
        $subtypes = [
            1 => [ // Condominium
                1 => 'Studio',
                2 => '1 Bedroom',
                3 => '2 Bedrooms',
                4 => '3 Bedrooms',
                5 => '4 Bedrooms',
                6 => 'Loft',
                7 => 'Penthouse',
            ],
            2 => [ // House
                8 => 'House and Lot',
                9 => 'Apartment',
                10 => 'Boarding House',
                11 => 'Pension House',
                12 => 'Townhouse',
                13 => 'Beach House',
                14 => 'Retirement House',
            ],
            3 => [ // Land
                15 => 'Agricultural Lot',
                16 => 'Residential Lot',
                17 => 'Commercial Lot',
                18 => 'Industrial Lot',
                19 => 'Memorial Lot',
                20 => 'Beach Lot',
                21 => 'Island',
            ],
            4 => [ // Commercial
                22 => 'BPO',
                23 => 'Warehouse',
                24 => 'Building',
                25 => 'Office',
                26 => 'Hotel',
                27 => 'Space',
            ],
        ];

        foreach ($subtypes as $propertyTypeId => $list) {
            foreach ($list as $id => $subtypeName) {
                DB::table('property_subtypes')->updateOrInsert(
                    ['id' => $id], // fixed ID
                    [
                        'name' => $subtypeName,
                        'property_type_id' => $propertyTypeId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        // Adjust auto-increment so it starts after the last fixed ID
        DB::statement('ALTER TABLE property_subtypes AUTO_INCREMENT = 407');

        $this->command->info('Property subtypes seeded successfully with fixed IDs!');
    }
}
