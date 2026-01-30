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
        $this->call(PropertyTypeSeeder::class);
        $this->call(PropertySubtypeSeeder::class);
        $this->call(CategorySeeder::class);
        $this->call(FurnishingSeeder::class);
        $this->call(UserSeeder::class);
        $this->call(AgentSeeder::class);
        // $this->call(OldToNewPropertiesSeeder::class);

    }
}
