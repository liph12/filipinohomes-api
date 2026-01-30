<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PropertyTypeSeeder extends Seeder
{
    public function run(): void
    {
        // DB::table('property_types')->truncate();

        $types = [
            1 => 'Condominium',
            2 => 'House',
            3 => 'Land',
            4 => 'Commercial',
        ];

        foreach ($types as $id => $name) {
            DB::table('property_types')->updateOrInsert(
                ['id' => $id], 
                [
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $this->command->info('Property types seeded successfully with fixed IDs!');
    }
}
