<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class FurnishingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // DB::table('furnishings')->truncate();

         $furnishings = [
            ['id' => 1, 'name' => 'Fully Furnished'],
            ['id' => 2, 'name' => 'Semi Furnished'],
            ['id' => 3, 'name' => 'Unfurnished'],
            ['id' => 4, 'name' => 'Finish'],
        ];

        foreach ($furnishings as $furnishing) {
            DB::table('furnishings')->updateOrInsert(
                ['id' => $furnishing['id']],
                ['name' => $furnishing['name']]
            );
        }
       $this->command->info('Furnishings seeded successfully with fixed IDs!');
    }
}
