<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdSectionSeeder extends Seeder
{
    public function run(): void
    {
        // Only the live placement slots are seeded. Grouped in the ads admin as:
        //   Homepage banner   -> home.hero_banner
        //   Global Sidebar    -> listings.sidebar, project_detail.sidebar (600x1350)
        //   Global Content Bottom -> the six *.content_bottom slots (360x360)
        $sections = [
            // Homepage
            ['name' => 'Homepage Hero Banner', 'key' => 'home.hero_banner', 'description' => 'After HeroSection', 'max_ads' => 1, 'width' => 1200, 'height' => 400],

            // Global Sidebar (listings + project detail)
            ['name' => 'Listings Sidebar', 'key' => 'listings.sidebar', 'description' => 'Listings page sidebar', 'max_ads' => 3, 'width' => 600, 'height' => 1350],
            ['name' => 'Project Detail Sidebar', 'key' => 'project_detail.sidebar', 'description' => 'Project detail sidebar', 'max_ads' => 3, 'width' => 600, 'height' => 1350],

            // Global Content Bottom (one per page)
            ['name' => 'Listing Detail Bottom', 'key' => 'listing_detail.content_bottom', 'description' => 'Below property description', 'max_ads' => 1, 'width' => 360, 'height' => 360],
            ['name' => 'Agent Detail Bottom', 'key' => 'agent_detail.content_bottom', 'description' => 'Below agent info', 'max_ads' => 1, 'width' => 360, 'height' => 360],
            ['name' => 'Blogs Below Content', 'key' => 'blogs.content_bottom', 'description' => 'Below blogs list', 'max_ads' => 1, 'width' => 360, 'height' => 360],
            ['name' => 'Blog Detail Below Content', 'key' => 'blog_detail.content_bottom', 'description' => 'Below blog article', 'max_ads' => 1, 'width' => 360, 'height' => 360],
            ['name' => 'News Below Content', 'key' => 'news.content_bottom', 'description' => 'Below news list', 'max_ads' => 1, 'width' => 360, 'height' => 360],
            ['name' => 'News Detail Below Content', 'key' => 'news_detail.content_bottom', 'description' => 'Below news article', 'max_ads' => 1, 'width' => 360, 'height' => 360],
        ];

        foreach ($sections as $section) {
            DB::table('ad_sections')->updateOrInsert(
                ['key' => $section['key']],
                $section
            );
        }
    }
}
