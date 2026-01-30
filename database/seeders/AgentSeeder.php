<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AgentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $agents = [
            [
                'id' => 1,
                'user_id' => 2, 
                'first_name' => 'Agent',
                'last_name' => 'User',
                'mobile_no' => '09171234567',
            ],
        ];

        foreach ($agents as $agent) {
            DB::table('agents')->updateOrInsert(
                ['id' => $agent['id']],
                [
                    'user_id' => $agent['user_id'],
                    'first_name' => $agent['first_name'],
                    'last_name' => $agent['last_name'],
                    'mobile_no' => $agent['mobile_no'],
                ]
            );
        }
    }
}
