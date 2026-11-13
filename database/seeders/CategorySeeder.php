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
            // Pasalo: the buyer pays a cash-out and takes over the balance
            // still owed. See 2026_11_13_000001_add_for_assume_category.
            ['id' => 4, 'name' => 'For Assume'],
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
