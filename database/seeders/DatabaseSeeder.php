<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Call your custom seeder
        $this->call(PropertyTypeSeeder::class);
        $this->call(PropertySubtypeSeeder::class);
        $this->call(OldToNewPropertiesSeeder::class);


        // Example: call other seeders if needed
        // $this->call(CategoriesSeeder::class);
        // $this->call(FurnishingsSeeder::class);
    }
}
