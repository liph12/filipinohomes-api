<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Member;
use Illuminate\Support\Facades\Hash;

class UserAgentSeeder extends Seeder
{
    private function isAdmin($email)
    {
        $isAdmin = false;
        $admins = [
            'itadmin@filipinohomes.com',
            'mindworth@gmail.com',
            'libresphilip14@gmail.com'
        ];

        foreach($admins as $a)
        {
            if($a === $email)
            {
                $isAdmin = true;
            }
        }

        return $isAdmin;
    }

    public function run(): void
    {
        $hashedPassword = Hash::make('1');

        DB::disableQueryLog();

        Member::chunk(500, function ($members) use ($hashedPassword) {

            $emails = $members->pluck('emailad')->toArray();

            // Get existing emails in users table
            $existingEmails = DB::table('users')
                ->whereIn('email', $emails)
                ->pluck('email')
                ->toArray();

            // Flip to make lookup fast
            $existingEmails = array_flip($existingEmails);

            $userData = [];
            $agentData = [];

            foreach ($members as $m) {

                // Skip if email already exists
                if (isset($existingEmails[$m->emailad])) {
                    continue;
                }

                $avatar = $m->photo
                    ? (str_contains($m->photo, 'https://')
                        ? $m->photo
                        : 'https://s3-ap-southeast-1.amazonaws.com/filipinohomes/members/' . $m->id . '/photo/' . $m->photo)
                    : null;

                $mobile = filter_var($m->mobile ?? $m->phone ?? null, FILTER_VALIDATE_EMAIL)
                    ? null
                    : ($m->mobile ?? $m->phone ?? null);

                // Prepare user insert
                $userData[] = [
                    'id'                => $m->id,
                    'name'              => implode(' ', array_filter([$m->fn, $m->mn, $m->ln])),
                    'email'             => $m->emailad,
                    'mobile_no'         => $mobile ?? null,
                    'avatar'            => $avatar,
                    'password'          => $hashedPassword,
                    'email_verified_at' => null,
                    'role_id'           => $this->isAdmin($m->emailad) ? 1 : 2,
                    'created_at'   => $m->datesign ?? now(),
                    'updated_at'        => now(),
                ];

                // Prepare agent insert
                $agentData[] = [
                    'id'           => $m->id,
                    'first_name'   => $m->fn,
                    'middle_name'  => $m->mn ?? null,
                    'last_name'    => $m->ln,
                    'mobile_no'    => $mobile ?? null,
                    'whats_app_no' => null,
                    'address'      => $m->address ?? null,
                    'socials' => json_encode([
                        'facebook'  => $m->facebook  ?? null,
                        'instagram' => $m->instagram ?? null,
                        'twitter'   => $m->twitter   ?? null,
                        'linkedin'  => $m->linkedin  ?? null,
                    ]),
                    'bio'          => $m->aboutme ?? '',
                    'avatar'       => $avatar,
                    'geo_location' => null,
                    'member_since' => $m->datesign ?? null,
                    'user_id'      => $m->id,
                    'created_at'   => $m->datesign ?? now(),
                    'updated_at'   => now(),
                ];
            }

            // Bulk insert users and agents
            if (!empty($userData)) {
                // DB::table('users')->insertOrIgnore($userData);
            }

            if (!empty($agentData)) {
                // DB::table('agents')->insertOrIgnore($agentData);
            }
        });
    }
}