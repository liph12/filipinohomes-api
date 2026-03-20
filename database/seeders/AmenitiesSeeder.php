<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AmenitiesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $amenities = [
            ['id' => 1, 'name' => 'Alarm System', 'slug' => 'alarm-system'],
            ['id' => 2, 'name' => 'Intercom', 'slug' => 'intercom'],
            ['id' => 3, 'name' => 'Ensuite', 'slug' => 'ensuite'],
            ['id' => 4, 'name' => 'Built-in Wardrobes', 'slug' => 'built-in-wardrobes'],
            ['id' => 5, 'name' => 'Fitness Gym', 'slug' => 'fitness-gym'],
            ['id' => 6, 'name' => 'Floorboards', 'slug' => 'floorboards'],
            ['id' => 7, 'name' => 'Internet', 'slug' => 'internet'],
            ['id' => 8, 'name' => 'TV Access', 'slug' => 'tv-access'],
            ['id' => 9, 'name' => 'Open Fireplace', 'slug' => 'open-fireplace'],
            ['id' => 10, 'name' => 'Hydronic Heating', 'slug' => 'hydronic-heating'],
            ['id' => 11, 'name' => 'Air Conditioning', 'slug' => 'air-conditioning'],
            ['id' => 12, 'name' => 'Gas Heating', 'slug' => 'gas-heating'],
            ['id' => 13, 'name' => 'Wifi', 'slug' => 'wifi'],
            ['id' => 14, 'name' => 'Study Room', 'slug' => 'study-room'],
            ['id' => 15, 'name' => 'Lounge', 'slug' => 'lounge'],
            ['id' => 16, 'name' => 'Entertainment Room', 'slug' => 'entertainment-room'],
            ['id' => 17, 'name' => 'Indoor Spa', 'slug' => 'indoor-spa'],
            ['id' => 18, 'name' => 'Function Room', 'slug' => 'function-room'],
            ['id' => 19, 'name' => 'Sauna (Indoor)', 'slug' => 'sauna-indoor'],
            ['id' => 20, 'name' => 'Social Hall', 'slug' => 'social-hall'],
            ['id' => 21, 'name' => 'Smoke Detector', 'slug' => 'smoke-detector'],
            ['id' => 22, 'name' => 'Laundry Area', 'slug' => 'laundry-area'],
            ['id' => 23, 'name' => 'Bar', 'slug' => 'bar'],
            ['id' => 24, 'name' => 'Shower Room', 'slug' => 'shower-room'],
            ['id' => 25, 'name' => 'Utility Room', 'slug' => 'utility-room'],
            ['id' => 26, 'name' => 'Powder Room', 'slug' => 'powder-room'],
            ['id' => 27, 'name' => 'Elevator', 'slug' => 'elevator'],
            ['id' => 28, 'name' => 'Game Room', 'slug' => 'game-room'],
            ['id' => 29, 'name' => 'Lobby', 'slug' => 'lobby'],
            ['id' => 30, 'name' => 'Fire Exit', 'slug' => 'fire-exit'],
            ['id' => 31, 'name' => 'Meeting Room', 'slug' => 'meeting-room'],
            ['id' => 32, 'name' => 'Drying Area (Indoor)', 'slug' => 'drying-area-indoor'],
            ['id' => 33, 'name' => 'Fire Alarm', 'slug' => 'fire-alarm'],
            ['id' => 34, 'name' => 'Jacuzzi (Indoor)', 'slug' => 'jacuzzi-indoor'],
            ['id' => 35, 'name' => 'CCTV', 'slug' => 'cctv'],
            ['id' => 36, 'name' => 'Fire Sprinkler System', 'slug' => 'fire-sprinkler-system'],
            ['id' => 37, 'name' => 'Billiards Table', 'slug' => 'billiards-table'],
            ['id' => 38, 'name' => 'Swimming Pool', 'slug' => 'swimming-pool'],
            ['id' => 39, 'name' => 'Tennis Court', 'slug' => 'tennis-court'],
            ['id' => 40, 'name' => 'Balcony', 'slug' => 'balcony'],
            ['id' => 41, 'name' => 'Deck', 'slug' => 'deck'],
            ['id' => 42, 'name' => 'Fully Fenced', 'slug' => 'fully-fenced'],
            ['id' => 43, 'name' => 'Parks', 'slug' => 'parks'],
            ['id' => 44, 'name' => 'Jogging Path', 'slug' => 'jogging-path'],
            ['id' => 45, 'name' => 'Sport Facilities', 'slug' => 'sport-facilities'],
            ['id' => 46, 'name' => 'Basketball Court', 'slug' => 'basketball-court'],
            ['id' => 47, 'name' => 'Garden', 'slug' => 'garden'],
            ['id' => 48, 'name' => 'Badminton Court', 'slug' => 'badminton-court'],
            ['id' => 49, 'name' => 'Pool Bar', 'slug' => 'pool-bar'],
            ['id' => 50, 'name' => 'Outdoor Spa', 'slug' => 'outdoor-spa'],
            ['id' => 51, 'name' => 'Club House', 'slug' => 'club-house'],
            ['id' => 52, 'name' => 'Multi-purpose Lawn', 'slug' => 'multi-purpose-lawn'],
            ['id' => 53, 'name' => 'Playground', 'slug' => 'playground'],
            ['id' => 54, 'name' => 'Gazebo', 'slug' => 'gazebo'],
            ['id' => 55, 'name' => 'Jacuzzi (Outdoor)', 'slug' => 'jacuzzi-outdoor'],
            ['id' => 56, 'name' => '24-Hour Security', 'slug' => '24-hour-security'],
            ['id' => 57, 'name' => 'Open Space', 'slug' => 'open-space'],
            ['id' => 58, 'name' => 'Function Area', 'slug' => 'function-area'],
            ['id' => 59, 'name' => 'Helipad', 'slug' => 'helipad'],
            ['id' => 60, 'name' => 'Drying Area (Outdoor)', 'slug' => 'drying-area-outdoor'],
            ['id' => 61, 'name' => 'Sauna (Outdoor)', 'slug' => 'sauna-outdoor'],
            ['id' => 62, 'name' => 'Study Area', 'slug' => 'study-area'],
            ['id' => 63, 'name' => 'Shops', 'slug' => 'shops'],
            ['id' => 64, 'name' => 'Sky Garden', 'slug' => 'sky-garden'],
            ['id' => 65, 'name' => 'Pond', 'slug' => 'pond'],
            ['id' => 66, 'name' => 'Golf Area', 'slug' => 'golf-area'],
            ['id' => 67, 'name' => 'Courtyard', 'slug' => 'courtyard'],
        ];

        DB::table('amenities')->insert($amenities);
    }
}