<?php

namespace Tests\Feature;

use App\Http\Controllers\SitemapController;
use App\Services\Listing\ListingFeedSwitch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthTables;
use Tests\Concerns\CreatesListingFeedTables;
use Tests\TestCase;

/**
 * `listings:price-guard-impact` compares the sitemap's price pages with the guard off and on. The feed itself is built
 * (and tested) elsewhere; here a stand-in feed that answers differently per guard state checks the comparison, the page
 * paths, the CSV, and that the report forces the guard in memory whatever the settings say.
 */
class PriceGuardImpactTest extends TestCase
{
    use CreatesAuthTables;
    use CreatesListingFeedTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthTables();
        DB::table('roles')->insert([['id' => 1, 'name' => 'admin'], ['id' => 2, 'name' => 'agent']]);
        $this->createListingFeedTables();

        $this->app->bind(SitemapController::class, fn () => new class extends SitemapController
        {
            public function queryCounts(): JsonResponse
            {
                $row = fn (string $modifier, string $category, string $type, string $city, string $province, int $total) => compact('modifier', 'category', 'type', 'city', 'province', 'total');

                return response()->json(ListingFeedSwitch::priceGuard() ? [
                    $row('under-3m', 'For Sale', 'House', 'Cebu City', 'Cebu', 24),                    // shrinks: 30 -> 24
                    $row('affordable', 'For Rent', 'Condominium', 'Makati City', 'Metro Manila', 11),  // shrinks to near the floor
                    $row('under-5m', 'For Sale', 'House', 'Polomolok', 'South Cotabato', 26),          // appears
                ] : [
                    $row('under-1m', 'For Sale', 'Land', 'Las Piñas City', 'Metro Manila', 51),        // leaves
                    $row('under-3m', 'For Sale', 'House', 'Cebu City', 'Cebu', 30),
                    $row('affordable', 'For Rent', 'Condominium', 'Makati City', 'Metro Manila', 14),
                    $row('affordable', 'Foreclosed', 'House', 'Cebu City', 'Cebu', 12),               // the site has no page for this
                ]);
            }
        });
    }

    public function test_it_lists_what_leaves_appears_and_shrinks_with_the_urls_the_site_uses(): void
    {
        $this->artisan('listings:price-guard-impact')
            ->assertSuccessful()
            ->expectsOutputToContain('3 now, 3 once the guard is on')
            ->expectsOutputToContain('leave (noindex, out of the sitemap)')
            ->expectsOutputToContain('/for-sale/land/under-1m/in-las-pinas-city-metro-manila')   // diacritic stripped, as the site does
            ->expectsOutputToContain('/for-sale/house/under-5m/in-polomolok-south-cotabato')
            ->expectsOutputToContain('/for-sale/house/under-3m/in-cebu-city-cebu')
            ->expectsOutputToContain('next to go (at most 12 left)');
    }

    public function test_the_csv_has_every_changed_page_and_nothing_else(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'impact');

        $this->artisan('listings:price-guard-impact', ['--csv' => $file])->assertSuccessful()->expectsOutputToContain('Wrote 4 changed pages');

        $rows = array_map('str_getcsv', file($file, FILE_IGNORE_NEW_LINES));
        unlink($file);
        $this->assertSame(['change', 'modifier', 'category', 'type', 'city', 'province', 'before', 'after', 'path'], $rows[0]);
        $byChange = collect(array_slice($rows, 1))->groupBy(0)->map->count()->all();
        $this->assertSame(['leave' => 1, 'appear' => 1, 'shrink' => 2], $byChange);

        $leave = collect($rows)->firstWhere(0, 'leave');
        $this->assertSame(['leave', 'under-1m', 'For Sale', 'Land', 'Las Piñas City', 'Metro Manila', '51', '0', '/for-sale/land/under-1m/in-las-pinas-city-metro-manila'], $leave);
        $appear = collect($rows)->firstWhere(0, 'appear');
        $this->assertSame(['appear', 'under-5m', 'For Sale', 'House', 'Polomolok', 'South Cotabato', '0', '26', '/for-sale/house/under-5m/in-polomolok-south-cotabato'], $appear);
    }

    public function test_an_unwritable_csv_path_fails_without_losing_the_report(): void
    {
        $this->artisan('listings:price-guard-impact', ['--csv' => '/this/directory/does/not/exist/impact.csv'])
            ->assertFailed()
            ->expectsOutputToContain('3 now, 3 once the guard is on');
    }

    public function test_it_compares_both_states_whatever_the_settings_say_and_changes_nothing(): void
    {
        foreach (['on', 'off'] as $state) {
            ListingFeedSwitch::set(ListingFeedSwitch::GUARD, $state);

            $this->artisan('listings:price-guard-impact')->assertSuccessful()
                ->expectsOutputToContain("Guard in the settings now: {$state}")
                ->expectsOutputToContain('3 now, 3 once the guard is on');

            $this->assertSame($state === 'on', ListingFeedSwitch::priceGuard());
            $this->assertSame($state, DB::table('settings')->where('key', ListingFeedSwitch::GUARD)->value('value'));
        }
    }

    public function test_pretending_the_guard_restores_the_previous_state_even_when_the_callback_throws(): void
    {
        ListingFeedSwitch::set(ListingFeedSwitch::GUARD, 'off');

        try {
            ListingFeedSwitch::pretendingGuard(true, function () {
                $this->assertTrue(ListingFeedSwitch::priceGuard());
                ListingFeedSwitch::pretendingGuard(false, fn () => $this->assertFalse(ListingFeedSwitch::priceGuard()));
                $this->assertTrue(ListingFeedSwitch::priceGuard()); // the inner one gave it back

                throw new \RuntimeException('boom');
            });
            $this->fail('the exception should have come through');
        } catch (\RuntimeException) {
        }

        $this->assertFalse(ListingFeedSwitch::priceGuard());
    }
}
