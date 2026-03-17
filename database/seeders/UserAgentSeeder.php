<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Agent;
use App\Models\Member;
use Illuminate\Support\Facades\Hash;

class UserAgentSeeder extends Seeder
{
    public function run(): void
    {
        $hashedPassword = Hash::make('1');

        DB::disableQueryLog();

        Member::chunk(500, function ($members) use ($hashedPassword) {
            $emails = $members->pluck('emailad');
            $existing = User::whereIn('email', $emails)->pluck('email')->flip();

            foreach ($members as $m) {
                if (isset($existing[$m->emailad])) {
                    continue;
                }

                $avatar = null;

                if ($m->photo) {
                    $avatar = str_contains($m->photo, 'https://')
                        ? $m->photo
                        : 'https://s3-ap-southeast-1.amazonaws.com/filipinohomes/members/' . $m->id . '/photo/' . $m->photo;
                }

                $mobile = filter_var($m->mobile ?? $m->phone ?? null, FILTER_VALIDATE_EMAIL)
                    ? null
                    : ($m->mobile ?? $m->phone ?? null);

                $user = User::create([
                    'id'                => $m->id,
                    'name'              => implode(' ', array_filter([$m->fn, $m->mn, $m->ln])),
                    'email'             => $m->emailad,
                    'mobile_no'         => $mobile ?? null,
                    'avatar'            => $avatar,
                    'password'          => $hashedPassword,
                    'email_verified_at' => null,
                    'role_id'           => 2,
                ]);

                Agent::create([
                    'id'           => $m->id,
                    'first_name'   => $m->fn,
                    'middle_name'  => $m->mn ?? null,
                    'last_name'    => $m->ln,
                    'mobile_no'    => $mobile ?? null,
                    'whats_app_no' => null,
                    'address'      => $m->address ?? null,
                    'socials'      => $m->facebook ? ['facebook' => $m->facebook] : null,
                    'bio'          => $m->aboutme ?? '',
                    'avatar'       => $avatar,
                    'geo_location' => null,
                    'member_since' => $m->datesign ?? null,
                    'user_id'      => $user->id,
                ]);
            }
        });
    }
}