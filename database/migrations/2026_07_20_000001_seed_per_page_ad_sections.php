<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per-page ad sections. Creates the sections introduced when the shared
 * `global.sidebar` slot was split into one section per page, and sets the
 * dimensions for the two placement groups:
 *   - Global Content Bottom (6 sections) -> 360x360 square
 *   - Global Sidebar        (2 sections) -> 600x1350 skyscraper
 *
 * Idempotent so production picks it up on deploy without re-running seeders.
 * (The placement remap lives in the following migration.)
 */
return new class extends Migration
{
    private array $newSections = [
        ['name' => 'Project Detail Sidebar', 'key' => 'project_detail.sidebar', 'description' => 'Project detail sidebar', 'max_ads' => 3, 'width' => 600, 'height' => 1350],
        ['name' => 'Blogs Below Content', 'key' => 'blogs.content_bottom', 'description' => 'Below blogs list', 'max_ads' => 1, 'width' => 360, 'height' => 360],
        ['name' => 'News Below Content', 'key' => 'news.content_bottom', 'description' => 'Below news list', 'max_ads' => 1, 'width' => 360, 'height' => 360],
    ];

    private array $contentBottomKeys = [
        'blog_detail.content_bottom',
        'blogs.content_bottom',
        'news_detail.content_bottom',
        'news.content_bottom',
        'agent_detail.content_bottom',
        'listing_detail.content_bottom',
    ];

    private array $sidebarKeys = [
        'listings.sidebar',
        'project_detail.sidebar',
    ];

    public function up(): void
    {
        foreach ($this->newSections as $section) {
            DB::table('ad_sections')->updateOrInsert(['key' => $section['key']], $section);
        }

        // Global Content Bottom -> 360x360 square.
        DB::table('ad_sections')
            ->whereIn('key', $this->contentBottomKeys)
            ->update(['width' => 360, 'height' => 360]);

        // Global Sidebar -> 600x1350 skyscraper.
        DB::table('ad_sections')
            ->whereIn('key', $this->sidebarKeys)
            ->update(['width' => 600, 'height' => 1350]);
    }

    public function down(): void
    {
        DB::table('ad_sections')
            ->whereIn('key', array_column($this->newSections, 'key'))
            ->delete();

        // Restore the reused sections' prior dimensions.
        DB::table('ad_sections')
            ->whereIn('key', ['blog_detail.content_bottom', 'news_detail.content_bottom', 'agent_detail.content_bottom', 'listing_detail.content_bottom'])
            ->update(['width' => 728, 'height' => 90]);

        DB::table('ad_sections')
            ->whereIn('key', ['listings.sidebar'])
            ->update(['width' => 300, 'height' => 250]);
    }
};
