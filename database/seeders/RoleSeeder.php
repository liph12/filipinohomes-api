<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'id' => 1,
                'name' => 'admin',
            ],
            [
                'id' => 2,
                'name' => 'agent',
            ],
            [
                'id' => 3,
                'name' => 'client',
            ],
        ];

        // Insert roles into the roles table
        DB::table('roles')->insert($roles);
    }
}
