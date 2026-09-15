<?php

namespace Tests\Feature;

use App\Models\GalleryAlbum;
use App\Models\GalleryPhoto;
use App\Natcon\Models\NatconEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GET /api/natcon/gallery/years — which conventions the public gallery's year
 * switcher may offer.
 *
 * This endpoint is the whole answer to "can a visitor still reach the 2025
 * photos?". Before it, the public gallery served the ACTIVE convention only: a
 * finished year's albums stayed online and permanently reachable by direct
 * link, but nothing on the site pointed at them.
 *
 * The two rules worth a test are the ones that are easy to get backwards — a
 * year qualifies on LIVE PHOTOS rather than on albums, and the active year is
 * listed regardless.
 *
 * Builds its own minimal tables — the full migration suite is MySQL-only.
 */
class NatconGalleryYearsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('natcon_events', function (Blueprint $table) {
            $table->id();
            $table->integer('year');
            $table->string('name')->nullable();
            $table->string('short_name')->nullable();
            $table->date('starts_on')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('gallery_albums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->nullable();
            $table->foreignId('parent_id')->nullable();
            $table->string('slug');
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('gallery_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->nullable();
            $table->foreignId('album_id')->nullable();
            $table->string('image_url');
            $table->string('status')->default('active');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    private function event(int $year, bool $active = false): NatconEvent
    {
        return NatconEvent::create([
            'year' => $year,
            'name' => "National Real Estate Convention {$year}",
            'short_name' => "NATCON {$year}",
            'starts_on' => "{$year}-10-18",
            'is_active' => $active,
        ]);
    }

    private function photo(NatconEvent $event, string $status = 'active'): GalleryPhoto
    {
        return GalleryPhoto::create([
            'natcon_event_id' => $event->id,
            'image_url' => 'https://example.test/photo.jpg',
            'status' => $status,
        ]);
    }

    private function years(): array
    {
        return $this->getJson('/api/natcon/gallery/years')->assertOk()->json('data');
    }

    public function test_it_lists_a_finished_convention_that_has_photos(): void
    {
        $this->photo($this->event(2025));
        $this->event(2026, active: true);

        $years = collect($this->years())->keyBy('year');

        $this->assertTrue($years->has(2025), '2025 has photos and must be reachable.');
        $this->assertSame(1, $years[2025]['photo_count']);
        $this->assertFalse($years[2025]['is_active']);
        $this->assertSame('NATCON 2025', $years[2025]['short_name']);
    }

    public function test_it_always_lists_the_active_convention_even_with_no_photos(): void
    {
        $this->event(2026, active: true);

        $years = collect($this->years())->keyBy('year');

        // The current convention is a tab from the day it opens. Its empty
        // state ("The collection is being curated") is what a visitor should
        // meet — not the year simply not existing.
        $this->assertTrue($years->has(2026));
        $this->assertSame(0, $years[2026]['photo_count']);
        $this->assertTrue($years[2026]['is_active']);
    }

    public function test_a_past_year_with_albums_but_no_photos_is_not_offered(): void
    {
        $empty = $this->event(2024);
        GalleryAlbum::create([
            'natcon_event_id' => $empty->id,
            'slug' => 'day-1',
            'name' => 'Day 1',
        ]);
        $this->event(2026, active: true);

        // ⚠️ The rule is LIVE PHOTOS, not albums. "Day 1" and "Day 2" exist in
        //    the admin before a single photo lands, so counting albums would
        //    offer a tab that opens on an empty room.
        $this->assertFalse(collect($this->years())->contains('year', 2024));
    }

    public function test_a_year_whose_photos_are_all_hidden_is_not_offered(): void
    {
        $this->photo($this->event(2023), status: 'hidden');
        $this->event(2026, active: true);

        $this->assertFalse(collect($this->years())->contains('year', 2023));
    }

    public function test_it_orders_newest_first(): void
    {
        $this->photo($this->event(2024));
        $this->photo($this->event(2025));
        $this->event(2026, active: true);

        $this->assertSame([2026, 2025, 2024], array_column($this->years(), 'year'));
    }

    public function test_it_is_public(): void
    {
        $this->event(2026, active: true);

        // Token-less like every other public gallery read — SSR is the consumer.
        $this->getJson('/api/natcon/gallery/years')->assertOk();
    }
}
