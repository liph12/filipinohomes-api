<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // DB::table('categories')->truncate();

        $statuses = [
            ['id' => 1, 'name' => 'For Sale'],
            ['id' => 2, 'name' => 'For Rent'],
            ['id' => 3, 'name' => 'Foreclosure'],
        ];

        foreach ($statuses as $status) {
            DB::table('categories')->updateOrInsert(
                ['id' => $status['id']],
                ['name' => $status['name']]
            );
        }
        
        $this->command->info('Categories seeded successfully with fixed IDs!');
    }
}
