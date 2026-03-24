<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['id' => 1, 'name' => 'admin'],
            ['id' => 2, 'name' => 'agent'],
            ['id' => 3, 'name' => 'client'],
            ['id' => 4, 'name' => 'editor'],
        ];

        DB::table('roles')->upsert($roles, ['id'], ['name']);
    }
}
