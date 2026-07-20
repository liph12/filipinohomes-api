<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remaps existing ad placements onto the new per-page sections so ads keep
 * showing where they did after the shared slots were split:
 *
 *  - `global.sidebar` (previously rendered on blog/blogs/news/news-detail/agent/
 *    listing-detail) -> the six matching *.content_bottom sections. The original
 *    global.sidebar placement is then removed.
 *  - `listings.sidebar` (previously rendered on BOTH listings and project detail)
 *    -> a project_detail.sidebar placement is ADDED; the listings.sidebar one is
 *    kept for the listings page.
 *
 * Runs on the ad_placements table only — no schema change. Uses updateOrInsert
 * on the (ad_id, ad_section_id) unique key so re-runs are safe. Not reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sectionIdByKey = DB::table('ad_sections')->pluck('id', 'key');

        $globalSidebarId = $sectionIdByKey['global.sidebar'] ?? null;
        $listingsSidebarId = $sectionIdByKey['listings.sidebar'] ?? null;

        $contentBottomKeys = [
            'blog_detail.content_bottom',
            'blogs.content_bottom',
            'news_detail.content_bottom',
            'news.content_bottom',
            'agent_detail.content_bottom',
            'listing_detail.content_bottom',
        ];
        $contentBottomIds = collect($contentBottomKeys)
            ->map(fn ($k) => $sectionIdByKey[$k] ?? null)
            ->filter()
            ->values()
            ->all();

        $now = now();

        // global.sidebar -> the six content-bottom sections, then drop the original.
        if ($globalSidebarId && $contentBottomIds) {
            $globalPlacements = DB::table('ad_placements')
                ->where('ad_section_id', $globalSidebarId)
                ->get();

            foreach ($globalPlacements as $placement) {
                foreach ($contentBottomIds as $sectionId) {
                    DB::table('ad_placements')->updateOrInsert(
                        ['ad_id' => $placement->ad_id, 'ad_section_id' => $sectionId],
                        [
                            'priority' => $placement->priority,
                            'weight' => $placement->weight,
                            'is_fixed' => $placement->is_fixed,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );
                }
            }

            DB::table('ad_placements')->where('ad_section_id', $globalSidebarId)->delete();
        }

        // listings.sidebar -> also add project_detail.sidebar (keep listings.sidebar).
        $projectSidebarId = $sectionIdByKey['project_detail.sidebar'] ?? null;
        if ($listingsSidebarId && $projectSidebarId) {
            $listingsPlacements = DB::table('ad_placements')
                ->where('ad_section_id', $listingsSidebarId)
                ->get();

            foreach ($listingsPlacements as $placement) {
                DB::table('ad_placements')->updateOrInsert(
                    ['ad_id' => $placement->ad_id, 'ad_section_id' => $projectSidebarId],
                    [
                        'priority' => $placement->priority,
                        'weight' => $placement->weight,
                        'is_fixed' => $placement->is_fixed,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }

        // Retire the sections that are no longer used anywhere (the shared
        // global.sidebar, legacy banners, and never-rendered slots), leaving
        // only the nine live placements. Listed explicitly — never a
        // "delete everything except" — so nothing unintended is removed. FK is
        // cascadeOnDelete, so any stray placements on these go with them.
        $retiredKeys = [
            'home.mid_banner',
            'home.bottom_banner',
            'listings.top_banner',
            'listing_detail.sidebar_top',
            'agents.top_banner',
            'agents.sidebar',
            'agent_detail.sidebar',
            'blogs.top_banner',
            'blogs.sidebar',
            'blog_detail.sidebar',
            'news_detail.sidebar',
            'magazine.top_banner',
            'magazine.content_bottom',
            'about.top_banner',
            'about.content_bottom',
            'global.sidebar',
            'global.footer_banner',
        ];

        DB::table('ad_sections')->whereIn('key', $retiredKeys)->delete();
    }

    public function down(): void
    {
        // Data remap — not reversible. Placements would have to be re-created by
        // hand or via a fresh seed; intentionally a no-op.
    }
};
