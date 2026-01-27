<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PropertySubtypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Subtypes grouped by property_type_id
        $subtypes = [
            1 => [ // Condominium
                'Studio',
                '1 Bedroom',
                '2 Bedrooms',
                '3 Bedrooms',
                '4 Bedrooms',
                'Loft',
                'Penthouse',
            ],
            2 => [ // House
                'House and Lot',
                'Apartment',
                'Boarding House',
                'Pension House',
                'Townhouse',
                'Beach House',
                'Retirement House',
            ],
            3 => [ // Land
                'Agricultural Lot',
                'Residential Lot',
                'Commercial Lot',
                'Industrial Lot',
                'Memorial Lot',
                'Beach Lot',
                'Island',
            ],
            4 => [ // Land
                'BPO',
                'Warehouse',
                'Building',
                'Office',
                'Hotel',
                'Space',
            ],
        ];

        foreach ($subtypes as $propertyTypeId => $list) {
            foreach ($list as $subtype) {
                DB::table('property_subtypes')->updateOrInsert(
                    [
                        'name' => $subtype,
                        'property_type_id' => $propertyTypeId,
                    ],
                    [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        $this->command->info('Property subtypes seeded successfully!');
    }
}
