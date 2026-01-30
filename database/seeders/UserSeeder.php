<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // DB::table('users')->truncate();

        $users = [
            [
                'id' => 1,
                'name' => 'admin',
                'email' => 'admin@gmail.com',
                'password' => Hash::make('1'), 
                'role_id' => 1,
            ],
            [
                'id' => 2,
                'name' => 'agent',
                'email' => 'agent@gmail.com',
                'password' => Hash::make('1'),
                'role_id' => 2,
            ],
        ];

        foreach ($users as $user) {
            DB::table('users')->updateOrInsert(
                ['id' => $user['id']],
                [
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'password' => $user['password'],
                    'role_id' => $user['role_id'],
                ]
            );
        }
    }
}
