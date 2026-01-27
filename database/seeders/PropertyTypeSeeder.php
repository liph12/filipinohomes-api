<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PropertyTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            'Condominium',
            'House',
            'Land',
            'Commercial',
        ];

        foreach ($types as $type) {
            DB::table('property_types')->updateOrInsert(
                ['name' => $type], // prevent duplicates
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $this->command->info('Property types seeded successfully!');
    }
}
