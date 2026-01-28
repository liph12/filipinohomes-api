<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PropertyTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            1 => 'Condominium',
            2 => 'House',
            3 => 'Land',
            4 => 'Commercial',
        ];

        foreach ($types as $id => $name) {
            DB::table('property_types')->updateOrInsert(
                ['id' => $id], // fixed ID
                [
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Set next auto-increment to avoid collisions
        DB::statement('ALTER TABLE property_types AUTO_INCREMENT = 5');

        $this->command->info('Property types seeded successfully with fixed IDs!');
    }
}
