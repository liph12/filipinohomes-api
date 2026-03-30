<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdSectionSeeder extends Seeder
{
    public function run(): void
    {
        $sections = [
            // Homepage
            ['name' => 'Homepage Hero Banner', 'key' => 'home.hero_banner', 'description' => 'After HeroSection', 'max_ads' => 1, 'width' => 1200, 'height' => 400],
            ['name' => 'Homepage Mid Banner', 'key' => 'home.mid_banner', 'description' => 'Between BuySell and WorkedWith', 'max_ads' => 1, 'width' => 1200, 'height' => 250],
            ['name' => 'Homepage Bottom Banner', 'key' => 'home.bottom_banner', 'description' => 'Before PopularProperties', 'max_ads' => 1, 'width' => 1200, 'height' => 250],

            // Listings
            ['name' => 'Listings Top Banner', 'key' => 'listings.top_banner', 'description' => 'Above property grid', 'max_ads' => 1, 'width' => 970, 'height' => 250],
            ['name' => 'Listings Sidebar', 'key' => 'listings.sidebar', 'description' => 'Sidebar ad slots', 'max_ads' => 3, 'width' => 300, 'height' => 250],

            // Listing Detail
            ['name' => 'Listing Detail Sidebar', 'key' => 'listing_detail.sidebar_top', 'description' => 'In sidebar area', 'max_ads' => 1, 'width' => 300, 'height' => 250],
            ['name' => 'Listing Detail Bottom', 'key' => 'listing_detail.content_bottom', 'description' => 'Below property description', 'max_ads' => 1, 'width' => 728, 'height' => 90],

            // Agents
            ['name' => 'Agents Top Banner', 'key' => 'agents.top_banner', 'description' => 'Above agents grid', 'max_ads' => 1, 'width' => 970, 'height' => 250],
            ['name' => 'Agents Sidebar', 'key' => 'agents.sidebar', 'description' => 'Sidebar ad slots', 'max_ads' => 2, 'width' => 300, 'height' => 250],

            // Agent Detail
            ['name' => 'Agent Detail Sidebar', 'key' => 'agent_detail.sidebar', 'description' => 'Sidebar area', 'max_ads' => 1, 'width' => 300, 'height' => 250],
            ['name' => 'Agent Detail Bottom', 'key' => 'agent_detail.content_bottom', 'description' => 'Below agent info', 'max_ads' => 1, 'width' => 728, 'height' => 90],

            // Blogs
            ['name' => 'Blogs Top Banner', 'key' => 'blogs.top_banner', 'description' => 'Above blog list', 'max_ads' => 1, 'width' => 970, 'height' => 250],
            ['name' => 'Blog Sidebar', 'key' => 'blogs.sidebar', 'description' => 'Blog page sidebar', 'max_ads' => 2, 'width' => 300, 'height' => 250],

            // Blog Detail
            ['name' => 'Blog Detail Sidebar', 'key' => 'blog_detail.sidebar', 'description' => 'Blog detail sidebar', 'max_ads' => 2, 'width' => 300, 'height' => 250],
            ['name' => 'Blog Below Content', 'key' => 'blog_detail.content_bottom', 'description' => 'Below blog article', 'max_ads' => 1, 'width' => 728, 'height' => 90],

            // News Detail
            ['name' => 'News Detail Sidebar', 'key' => 'news_detail.sidebar', 'description' => 'News article sidebar', 'max_ads' => 2, 'width' => 300, 'height' => 250],
            ['name' => 'News Below Content', 'key' => 'news_detail.content_bottom', 'description' => 'Below news article', 'max_ads' => 1, 'width' => 728, 'height' => 90],

            // Magazine
            ['name' => 'Magazine Top Banner', 'key' => 'magazine.top_banner', 'description' => 'Above magazine grid', 'max_ads' => 1, 'width' => 970, 'height' => 250],
            ['name' => 'Magazine Bottom Banner', 'key' => 'magazine.content_bottom', 'description' => 'Below magazine list', 'max_ads' => 1, 'width' => 728, 'height' => 90],

            // About Pages
            ['name' => 'About Top Banner', 'key' => 'about.top_banner', 'description' => 'Above about content (all about pages)', 'max_ads' => 1, 'width' => 970, 'height' => 250],
            ['name' => 'About Bottom Banner', 'key' => 'about.content_bottom', 'description' => 'Below about content (all about pages)', 'max_ads' => 1, 'width' => 728, 'height' => 90],

            // Global
            ['name' => 'Global Sidebar', 'key' => 'global.sidebar', 'description' => 'Dashboard sidebar (all roles)', 'max_ads' => 2, 'width' => 300, 'height' => 250],
            ['name' => 'Footer Banner', 'key' => 'global.footer_banner', 'description' => 'Above footer on all public pages', 'max_ads' => 1, 'width' => 970, 'height' => 90],
        ];

        foreach ($sections as $section) {
            DB::table('ad_sections')->updateOrInsert(
                ['key' => $section['key']],
                $section
            );
        }
    }
}
