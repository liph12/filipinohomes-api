<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BlogCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('blog_categories')->insert([
            ['id' => 9,  'wp_term_id' => 1,   'name' => 'Uncategorized',       'slug' => 'uncategorized', 'created_at' => '2026-01-07 02:13:55', 'updated_at' => '2026-01-07 02:13:55'],
            ['id' => 10, 'wp_term_id' => 2,   'name' => 'Blog',                'slug' => 'blog',          'created_at' => '2026-01-07 02:13:55', 'updated_at' => '2026-01-07 02:13:55'],
            ['id' => 11, 'wp_term_id' => 3,   'name' => 'Business',            'slug' => 'business',      'created_at' => '2026-01-07 02:13:54', 'updated_at' => '2026-01-07 02:13:54'],
            ['id' => 12, 'wp_term_id' => 10,  'name' => 'Real Estate 101',     'slug' => 'real-estate-101','created_at' => '2026-01-07 02:13:55','updated_at' => '2026-01-07 02:13:55'],
            ['id' => 13, 'wp_term_id' => 11,  'name' => 'Tips and Guides',     'slug' => 'tips-guides',   'created_at' => '2026-01-07 02:13:54', 'updated_at' => '2026-01-07 02:13:54'],
            ['id' => 14, 'wp_term_id' => 12,  'name' => 'Events',              'slug' => 'events',        'created_at' => '2026-01-07 02:13:54', 'updated_at' => '2026-01-07 02:13:54'],
            ['id' => 15, 'wp_term_id' => 13,  'name' => 'Property Investing',  'slug' => 'property-investing', 'created_at' => '2026-01-07 02:13:55','updated_at' => '2026-01-07 02:13:55'],
            ['id' => 16, 'wp_term_id' => 14,  'name' => 'Real Estate',         'slug' => 'real-estate',   'created_at' => '2026-01-07 02:13:55','updated_at' => '2026-01-07 02:13:55'],
            ['id' => 17, 'wp_term_id' => 16,  'name' => 'News',                'slug' => 'news',          'created_at' => '2026-01-07 02:13:55','updated_at' => '2026-01-07 02:13:55'],
            ['id' => 18, 'wp_term_id' => 38,  'name' => 'Tourism',             'slug' => 'tourism',       'created_at' => '2026-01-07 02:13:55','updated_at' => '2026-01-07 02:13:55'],
            ['id' => 19, 'wp_term_id' => 46,  'name' => 'Home Loans',          'slug' => 'home-loans',    'created_at' => '2026-01-07 02:13:54','updated_at' => '2026-01-07 02:13:54'],
            ['id' => 20, 'wp_term_id' => 83,  'name' => 'Leisure',             'slug' => 'leisure',       'created_at' => '2026-01-07 02:13:51','updated_at' => '2026-01-07 02:13:51'],
            ['id' => 21, 'wp_term_id' => 90,  'name' => 'Transportation',      'slug' => 'transportation','created_at' => '2026-01-07 02:13:51','updated_at' => '2026-01-07 02:13:51'],
            ['id' => 22, 'wp_term_id' => 122, 'name' => 'Accommodations',      'slug' => 'accommodations','created_at' => '2026-01-07 02:13:52','updated_at' => '2026-01-07 02:13:52'],
            ['id' => 23, 'wp_term_id' => 141, 'name' => 'Hotels',              'slug' => 'hotels',        'created_at' => '2026-01-07 02:13:51','updated_at' => '2026-01-07 02:13:51'],
            ['id' => 24, 'wp_term_id' => 148, 'name' => 'Festivals',           'slug' => 'festivals',     'created_at' => '2026-01-07 02:13:51','updated_at' => '2026-01-07 02:13:51'],
            ['id' => 25, 'wp_term_id' => 165, 'name' => 'Food',                'slug' => 'food',          'created_at' => '2026-01-07 02:13:51','updated_at' => '2026-01-07 02:13:51'],
            ['id' => 26, 'wp_term_id' => 181, 'name' => 'Bed and Breakfast',   'slug' => 'bed-and-breakfast','created_at' => '2026-01-07 02:13:51','updated_at' => '2026-01-07 02:13:51'],
            ['id' => 27, 'wp_term_id' => 329, 'name' => 'Condominium',         'slug' => 'condominium',   'created_at' => '2026-01-07 02:13:54','updated_at' => '2026-01-07 02:13:54'],
            ['id' => 28, 'wp_term_id' => 357, 'name' => 'Home Renovation',     'slug' => 'home-renovation','created_at' => '2026-01-07 02:13:54','updated_at' => '2026-01-07 02:13:54'],
            ['id' => 29, 'wp_term_id' => 360, 'name' => 'Interior Designs',    'slug' => 'interior-designs','created_at' => '2026-01-07 02:13:54','updated_at' => '2026-01-07 02:13:54'],
            ['id' => 30, 'wp_term_id' => 430, 'name' => 'Profile',             'slug' => 'profile',       'created_at' => '2026-01-07 02:13:53','updated_at' => '2026-01-07 02:13:53'],
            ['id' => 31, 'wp_term_id' => 502, 'name' => 'Home Improvement',    'slug' => 'home-improvement','created_at' => '2026-01-07 02:13:54','updated_at' => '2026-01-07 02:13:54'],
            ['id' => 32, 'wp_term_id' => 606, 'name' => 'Home Office',         'slug' => 'home-office',  'created_at' => '2026-01-07 02:13:51','updated_at' => '2026-01-07 02:13:51'],
            ['id' => 33, 'wp_term_id' => 630, 'name' => 'Home',                'slug' => 'home',          'created_at' => '2026-01-07 02:13:51','updated_at' => '2026-01-07 02:13:51'],
            ['id' => 34, 'wp_term_id' => 635, 'name' => 'Rent Manager Program','slug' => 'rent-manager-program','created_at' => '2026-01-07 02:13:51','updated_at' => '2026-01-07 02:13:51'],
            ['id' => 35, 'wp_term_id' => 646, 'name' => 'Financial Literacy', 'slug' => 'financial-literacy','created_at' => '2026-01-07 02:13:54','updated_at' => '2026-01-07 02:13:54'],
            ['id' => 36, 'wp_term_id' => 706, 'name' => 'Featured',           'slug' => 'featured',      'created_at' => '2026-01-07 02:13:51','updated_at' => '2026-01-07 02:13:51'],
        ]);
    }
}