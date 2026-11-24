<?php

namespace Tests\Feature;

use App\Http\Controllers\ListingController;
use App\Models\Role;
use App\Models\User;
use App\Services\Listing\ListingByStatusService;
use App\Services\Listing\ListingByTypeService as ByTypeService;
use App\Services\Listing\ListingCreatedService;
use App\Services\Listing\ListingDealsService;
use App\Services\Listing\ListingDemandService;
use App\Services\Listing\ListingPriceInsightsService;
use App\Services\Listing\ListingQualityService;
use App\Services\Listing\ListingSummaryService;
use App\Services\Reports\Executive\ReportQueries;
use App\Services\Reports\Executive\ReportRange;
use App\Support\IslandMap;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/**
 * The Listing Insights dashboard endpoints: summary, created, quality, deals,
 * demand and the dated prices-by-type.
 *
 * The rules worth a test are the ones that are invisible when they break: a
 * day is a Manila day (a listing added at 23:30 and one at 00:30 an hour later
 * belong to different days even though they share a UTC date), deals are dated
 * by when they closed and agree with the Executive Report, demand is dated by
 * the event and not by when the listing was added, an old caller's keys all
 * survive, and a team leader is never served an admin's cached answer.
 *
 * "Today" is Nov 10, 2026, 2 PM Manila.
 */
class ListingInsightsDashboardTest extends TestCase
{
    use CreatesExecutiveReportTables;

    private int $a1;

    private int $u1;

    private int $a2;

    private int $a3;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-10 14:00', 'Asia/Manila'));

        $this->createExecutiveReportTables();

        // The columns the dashboard reads that the Executive Report's trimmed schema leaves out.
        Schema::table('properties', function (Blueprint $table) {
            $table->text('photos')->nullable();
            $table->text('description')->nullable();
            // has_ats_files and ats_status (default "approve") are in the shared schema now.
        });
        Schema::table('listings', function (Blueprint $table) {
            $table->unsignedBigInteger('clicks')->default(0);
        });
        Schema::create('listing_view_days', function (Blueprint $table) {
            $table->date('date');
            $table->unsignedBigInteger('listing_id');
            $table->unsignedInteger('views')->default(0);
            $table->primary(['date', 'listing_id']);
        });

        [$this->a1, $this->u1] = $this->makeAgent();
        [$this->a2] = $this->makeAgent();
        [$this->a3] = $this->makeAgent();
        $this->makeTeam('Alpha', [$this->a1, $this->a2], leader: $this->a1);
        $this->makeTeam('Bravo', [$this->a3]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function user(string $role, int $id): User
    {
        $user = new User;
        $user->id = $id;
        $user->setRelation('role', (new Role)->forceFill(['name' => $role]));

        return $user;
    }

    /** Call a controller action the way the route would, minus the HTTP stack. */
    private function hit(string $action, string $service, array $query = [], string $role = 'admin', int $userId = 1, mixed ...$extra): JsonResponse
    {
        $request = Request::create('/api/listings/insights/x', 'GET', $query);
        $request->setUserResolver(fn () => $this->user($role, $userId));

        return app(ListingController::class)->{$action}($request, app($service), ...$extra);
    }

    private function summary(array $query = [], string $role = 'admin', int $userId = 1): array
    {
        return $this->hit('insightsSummary', ListingSummaryService::class, $query, $role, $userId)->getData(true);
    }

    private function created(array $query = []): array
    {
        return $this->hit('insightsCreated', ListingCreatedService::class, $query)->getData(true);
    }

    private function quality(array $query = []): array
    {
        return $this->hit('insightsQuality', ListingQualityService::class, $query)->getData(true);
    }

    private function deals(array $query = [], string $role = 'admin', int $userId = 1): array
    {
        return $this->hit('insightsDeals', ListingDealsService::class, $query, $role, $userId)->getData(true);
    }

    private function demand(array $query = []): array
    {
        return $this->hit('insightsDemand', ListingDemandService::class, $query)->getData(true);
    }

    private function prices(array $query = []): array
    {
        return $this->hit('insightsPricesByType', ListingPriceInsightsService::class, $query + ['view' => 'summary'])->getData(true);
    }

    /**
     * A listing, plus what the dashboard reads beyond the shared builder:
     * photos (a count), desc (a length), ats (a status, with files), audit
     * (a verification status), clicks.
     */
    private function listing(int $agent, array $o = []): int
    {
        $id = $this->makeListing($agent, $o);
        $propertyId = DB::table('listings')->where('id', $id)->value('property_id');

        $properties = [];
        if (isset($o['photos'])) {
            $properties['photos'] = json_encode(array_fill(0, $o['photos'], 'https://example.test/p.jpg'));
        }
        if (isset($o['desc'])) {
            $properties['description'] = str_repeat('a', $o['desc']);
        }
        if (isset($o['ats'])) {
            $properties['has_ats_files'] = 1;
            $properties['ats_status'] = $o['ats'];
        }
        if ($properties !== []) {
            DB::table('properties')->where('id', $propertyId)->update($properties);
        }

        $listings = array_filter([
            'verification_status' => $o['audit'] ?? null,
            'clicks' => $o['clicks'] ?? null,
        ], fn ($v) => $v !== null);
        if ($listings !== []) {
            DB::table('listings')->where('id', $id)->update($listings);
        }

        return $id;
    }

    /** Two listings an hour apart across a Manila midnight that share one UTC date. */
    private function midnightPair(): array
    {
        return [
            $this->listing($this->a1, ['added' => '2026-10-31 23:30']),
            $this->listing($this->a1, ['added' => '2026-11-01 00:30', 'category' => 2]),
        ];
    }

    private function viewDay(int $listingId, string $day, int $views): void
    {
        DB::table('listing_view_days')->insert(['date' => $day, 'listing_id' => $listingId, 'views' => $views]);
    }

    private function save(int $listingId, string $at): void
    {
        DB::table('favorites')->insert([
            'user_id' => $this->makeUser(self::CLIENT), 'listing_id' => $listingId,
            'created_at' => $this->utc($at), 'updated_at' => $this->utc($at),
        ]);
    }

    // ── The window: Manila days ─────────────────────────────────────────────

    public function test_summary_counts_the_manila_day_not_the_utc_day(): void
    {
        $this->midnightPair();

        $this->assertSame(1, $this->summary(['date_start' => '2026-11-01', 'date_end' => '2026-11-01'])['total']);
        $this->assertSame(1, $this->summary(['date_start' => '2026-10-31', 'date_end' => '2026-10-31'])['total']);
        $this->assertSame(2, $this->summary(['date_start' => '2026-10-31', 'date_end' => '2026-11-01'])['total']);
    }

    public function test_created_buckets_by_manila_day_and_month(): void
    {
        $this->midnightPair();

        $days = $this->created(['granularity' => 'day']);
        $this->assertSame(['2026-10-31', '2026-11-01'], $days['dates']);
        $this->assertSame([1, 1], $days['total_series']);

        $months = $this->created(['granularity' => 'month']);
        $this->assertSame(['2026-10-01', '2026-11-01'], $months['dates']);
        $this->assertSame([1, 1], $months['total_series']);
        $this->assertSame([1, 0], $months['categories'][0]['data'], 'For Sale: the Oct 31 listing');
        $this->assertSame([0, 1], $months['categories'][1]['data'], 'For Rent: the Nov 1 listing');
    }

    public function test_fill_spans_a_window_even_when_nothing_happened_in_it(): void
    {
        // A quiet week is a row of zeros, not an empty chart — on both time series.
        $window = ['date_start' => '2026-11-01', 'date_end' => '2026-11-07'];

        $created = $this->created($window + ['granularity' => 'day', 'fill' => '1']);
        $this->assertCount(7, $created['dates']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $created['total_series']);

        $deals = $this->deals($window)['series'];
        $this->assertCount(7, $deals['dates']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $deals['sold']);

        // With no first day and no data there is nothing to span.
        $this->assertSame([], $this->created(['granularity' => 'day', 'fill' => '1'])['dates']);
        $this->assertSame([], $this->created(['date_end' => '2026-11-07', 'granularity' => 'day', 'fill' => '1'])['dates']);
        $this->assertSame([], $this->deals()['series']['dates']);
    }

    public function test_created_weeks_start_on_sunday_and_fill_adds_the_empty_buckets(): void
    {
        $this->midnightPair();

        // Oct 31 2026 is a Saturday; Nov 1 a Sunday.
        $weeks = $this->created(['granularity' => 'week']);
        $this->assertSame(['2026-10-25', '2026-11-01'], $weeks['dates']);

        $filled = $this->created(['granularity' => 'day', 'fill' => '1', 'date_start' => '2026-10-30', 'date_end' => '2026-11-02']);
        $this->assertSame(['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02'], $filled['dates']);
        $this->assertSame([0, 1, 1, 0], $filled['total_series']);

        $auto = $this->created(['granularity' => 'auto', 'date_start' => '2026-10-30', 'date_end' => '2026-11-02']);
        $this->assertSame('day', $auto['meta']['granularity'], 'a short window reads by day');
        $this->assertSame('month', $this->created(['granularity' => 'auto'])['meta']['granularity'], 'all time reads by month');
    }

    public function test_a_one_sided_range_is_accepted_and_an_open_start_has_no_previous(): void
    {
        $this->midnightPair();

        $from = $this->summary(['date_start' => '2026-11-01']);
        $this->assertSame('2026-11-01', $from['range']['start']);
        $this->assertSame('2026-11-10', $from['range']['end'], 'no end means up to today');
        $this->assertSame(1, $from['total']);
        $this->assertNotNull($from['previous']);

        $upTo = $this->summary(['date_end' => '2026-10-31']);
        $this->assertNull($upTo['range']['start'], 'no start means from the beginning');
        $this->assertNull($upTo['range']['previous']);
        $this->assertNull($upTo['previous'], 'nothing precedes the beginning');
        $this->assertSame(1, $upTo['total']);
    }

    public function test_a_malformed_date_is_a_validation_failure_and_an_inverted_range_is_zeros(): void
    {
        $this->midnightPair();

        $inverted = $this->summary(['date_start' => '2026-11-09', 'date_end' => '2026-11-01']);
        $this->assertSame(0, $inverted['total']);
        $this->assertNull($inverted['range']['days']);
        $this->assertNull($inverted['previous']);

        $this->expectException(ValidationException::class);
        $this->summary(['date_start' => '11/05/2026']);
    }

    public function test_a_future_end_is_clamped_and_shares_the_cache_entry(): void
    {
        $this->midnightPair();

        $first = $this->hit('insightsSummary', ListingSummaryService::class, ['date_start' => '2026-11-01', 'date_end' => '2026-12-31']);
        $second = $this->hit('insightsSummary', ListingSummaryService::class, ['date_start' => '2026-11-01', 'date_end' => '2026-11-10']);

        $this->assertSame('miss', $first->headers->get('X-Insights-Cache'));
        $this->assertSame('hit', $second->headers->get('X-Insights-Cache'), 'Dec 31 asked on Nov 10 IS Nov 10');
        $this->assertSame('2026-11-10', $first->getData(true)['range']['end']);
    }

    public function test_no_dates_is_all_time_with_a_null_range(): void
    {
        $this->midnightPair();

        $all = $this->summary();
        $this->assertNull($all['range']);
        $this->assertNull($all['previous']);
        $this->assertSame(2, $all['total']);
    }

    // ── Summary ─────────────────────────────────────────────────────────────

    public function test_summary_keeps_every_legacy_key(): void
    {
        $this->midnightPair();

        $summary = $this->summary();

        foreach (['total', 'provinces', 'cities', 'for_sale', 'for_rent', 'foreclosure', 'sold', 'rented', 'leased', 'types', 'islands', 'month'] as $key) {
            $this->assertArrayHasKey($key, $summary, "an older bundle still reads `{$key}`");
        }
        $this->assertSame(['house', 'condominium', 'land', 'commercial'], array_keys($summary['types']));
        $this->assertSame(['luzon', 'visayas', 'mindanao'], array_keys($summary['islands']));
        $this->assertSame(['total', 'for_sale', 'for_rent', 'foreclosure'], array_keys($summary['month']));
        $this->assertSame('admin', $summary['scope']);
        $this->assertArrayHasKey('generated_at', $summary);
    }

    public function test_this_month_is_the_manila_month(): void
    {
        $this->listing($this->a1, ['added' => '2026-10-31 23:30']); // October in Manila, still Oct 31 in UTC
        $this->listing($this->a1, ['added' => '2026-11-01 00:30']); // November in Manila, Oct 31 16:30 in UTC

        $this->assertSame(1, $this->summary()['month']['total']);
    }

    public function test_previous_is_the_period_before_it(): void
    {
        $this->listing($this->a1, ['added' => '2026-10-01 10:00']);
        $this->listing($this->a1, ['added' => '2026-10-05 10:00', 'category' => 2]);
        $this->listing($this->a1, ['added' => '2026-11-05 10:00']);
        $this->listing($this->a1, ['added' => '2026-10-20 10:00']); // after the matching span

        $summary = $this->summary(['date_start' => '2026-11-01', 'date_end' => '2026-11-10']);

        // The month from its 1st compares with the same span of the month before.
        $this->assertSame(['start' => '2026-10-01', 'end' => '2026-10-10'], $summary['range']['previous']);
        $this->assertSame(10, $summary['range']['days']);
        $this->assertSame(['total' => 2, 'for_sale' => 1, 'for_rent' => 1, 'foreclosure' => 0], $summary['previous']);
        $this->assertSame(1, $summary['total']);
    }

    public function test_audit_and_visibility_each_sum_to_total(): void
    {
        $this->listing($this->a1, ['audit' => 'fully_verified']);
        $this->listing($this->a1, ['audit' => 'verified']);
        $this->listing($this->a1, ['audit' => 'pending_review']);
        $this->listing($this->a1, ['audit' => 'flagged', 'visibility' => 'private']);
        $this->listing($this->a1, ['visibility' => 'private']);
        $this->listing($this->a1);

        $summary = $this->summary();

        $this->assertSame(
            ['fully_verified' => 1, 'verified' => 1, 'pending_review' => 1, 'flagged' => 1, 'unaudited' => 2],
            $summary['audit'],
        );
        $this->assertSame(6, array_sum($summary['audit']));
        $this->assertSame(['public' => 4, 'private' => 2], $summary['visibility']);
    }

    public function test_distinct_places_and_islands_survive_the_single_pass(): void
    {
        $this->listing($this->a1, ['city' => 10]);
        $this->listing($this->a1, ['city' => 10]);
        $this->listing($this->a1, ['city' => 11]);
        $this->listing($this->a1, ['city' => 20]);

        $summary = $this->summary();

        $this->assertSame(2, $summary['provinces'], 'Cebu and Davao del Sur');
        $this->assertSame(3, $summary['cities'], 'Cebu City, Mandaue City and Davao City');

        $expected = ['luzon' => 0, 'visayas' => 0, 'mindanao' => 0];
        foreach (['Cebu' => 3, 'Davao del Sur' => 1] as $province => $n) {
            $expected[IslandMap::islandOf($province)] += $n;
        }
        $this->assertSame($expected, $summary['islands']);
    }

    public function test_a_place_filter_narrows_every_number(): void
    {
        $this->listing($this->a1, ['city' => 10]);
        $this->listing($this->a1, ['city' => 20]);
        $this->listing($this->a1, ['city' => 20]);

        $this->assertSame(2, $this->summary(['province_id' => 2])['total']);
        $this->assertSame(1, $this->summary(['city_id' => 10])['total']);
        $this->assertSame(3, $this->summary(['province_id' => 'all'])['total'], 'the older tabs send province_id=all for "no filter"');
    }

    // ── Quality ─────────────────────────────────────────────────────────────

    public function test_ats_counts_only_properties_with_files(): void
    {
        $this->listing($this->a1, ['ats' => 'approve']);
        $this->listing($this->a1, ['ats' => 'pending']);
        $this->listing($this->a1, ['ats' => 'expired']);
        $this->listing($this->a1, ['ats' => 'rejected']);
        $this->listing($this->a1);
        $this->listing($this->a1);

        $quality = $this->quality();

        // The two with no files still read "approve" in the column — that is its default, not a review.
        $this->assertSame(['approve' => 1, 'pending' => 1, 'expired' => 1, 'rejected' => 1, 'none' => 2], $quality['ats']);
        $this->assertSame($quality['total'], array_sum($quality['ats']));
    }

    public function test_completeness_thresholds_on_active_listings(): void
    {
        $cebu = '{"lat": 10.3157, "lng": 123.8854}';
        $full = ['photos' => 5, 'desc' => 120, 'pin' => $cebu, 'floor' => 10];

        $this->listing($this->a1, $full);                                                  // meets every rule
        $this->listing($this->a1, ['photos' => 4] + $full);                                // one photo short
        $this->listing($this->a1, ['desc' => 119] + $full);                                // one character short
        $this->listing($this->a1, ['pin' => '{"lat": 124.0, "lng": 10.29}'] + $full);     // lat/lng swapped
        $this->listing($this->a1, ['pin' => '{"lat": 0, "lng": 0}'] + $full);              // a zero pin
        $this->listing($this->a1, ['pin' => null] + $full);                                // no pin at all
        $this->listing($this->a1, ['floor' => 9] + $full);                                 // under 10 sqm
        $this->listing($this->a1, ['subtype' => 31, 'floor' => 0, 'lot' => 200] + $full);  // land: the lot is enough
        $this->listing($this->a1, ['status' => 'sold'] + $full);                           // not active: outside the base

        $completeness = $this->quality()['completeness'];

        $this->assertSame(8, $completeness['base'], 'the sold listing is not counted');
        $this->assertSame(7, $completeness['photos']);
        $this->assertSame(7, $completeness['description']);
        $this->assertSame(5, $completeness['map_pin']);
        $this->assertSame(7, $completeness['area']);
        $this->assertSame(2, $completeness['complete'], 'the full one and the land with only a lot');
    }

    public function test_quality_reports_the_rules_it_applied(): void
    {
        $this->assertSame(
            ['photos' => 5, 'description_chars' => 120, 'area_sqm' => 10],
            $this->quality()['thresholds'],
        );
    }

    // ── Deals ───────────────────────────────────────────────────────────────

    public function test_deals_use_the_deal_date_not_the_created_date(): void
    {
        $this->listing($this->a1, ['added' => '2026-10-01 10:00', 'status' => 'sold', 'deal' => '2026-11-06']);   // added earlier, closed in the range
        $this->listing($this->a1, ['added' => '2026-11-03 10:00', 'status' => 'rented', 'deal' => '2026-11-08', 'category' => 2]);
        $this->listing($this->a1, ['added' => '2026-11-04 10:00', 'status' => 'sold', 'deal' => '2026-10-20']);   // added in the range, closed before it
        $this->listing($this->a1, ['added' => '2026-11-05 10:00']);                                                // still active

        $deals = $this->deals(['date_start' => '2026-11-01', 'date_end' => '2026-11-10']);

        $this->assertSame(['sold' => 1, 'rented' => 1, 'leased' => 0, 'total' => 2, 'undated' => 0], $deals['totals']);
        $this->assertSame('2026-03-26', $deals['since']);
    }

    public function test_deals_match_the_executive_report_for_the_same_range(): void
    {
        $this->listing($this->a1, ['status' => 'sold', 'deal' => '2026-11-06']);
        $this->listing($this->a2, ['status' => 'sold', 'deal' => '2026-11-10']);
        $this->listing($this->a2, ['status' => 'rented', 'deal' => '2026-11-08', 'category' => 2]);
        $this->listing($this->a3, ['status' => 'leased', 'deal' => '2026-11-01', 'category' => 2]);
        $this->listing($this->a3, ['status' => 'sold', 'deal' => '2026-10-31']); // a day outside

        $range = ReportRange::resolve('2026-11-01', '2026-11-10');
        $report = app(ReportQueries::class);
        $totals = $this->deals(['date_start' => '2026-11-01', 'date_end' => '2026-11-10'])['totals'];

        foreach (['sold', 'rented', 'leased'] as $status) {
            $this->assertSame($report->deals($range, $status)->count('listings.id'), $totals[$status], "{$status} must agree with the report");
        }
        $this->assertSame(4, $totals['total']);
    }

    public function test_all_time_includes_undated_deals_and_the_series_skips_them(): void
    {
        $this->listing($this->a1, ['status' => 'sold', 'deal' => '2026-11-06']);
        $this->listing($this->a1, ['status' => 'sold']); // recorded before FH kept a deal date

        $deals = $this->deals();

        $this->assertSame(2, $deals['totals']['sold']);
        $this->assertSame(1, $deals['totals']['undated']);
        $this->assertTrue($deals['partial'], 'all time cannot be complete');
        $this->assertSame(1, array_sum($deals['series']['sold']), 'the series only holds dated deals');

        $window = $this->deals(['date_start' => '2026-11-01', 'date_end' => '2026-11-10']);
        $this->assertSame(1, $window['totals']['sold'], 'a window can only ever hold dated deals');
        $this->assertFalse($window['partial']);
    }

    public function test_a_window_reaching_back_before_deal_dates_existed_is_partial(): void
    {
        $this->assertTrue($this->deals(['date_start' => '2026-03-01', 'date_end' => '2026-04-30'])['partial']);
        $this->assertTrue($this->deals(['date_end' => '2026-04-30'])['partial']);
    }

    public function test_the_series_is_dense_and_sums_to_the_dated_totals(): void
    {
        $this->listing($this->a1, ['status' => 'sold', 'deal' => '2026-11-06']);
        $this->listing($this->a1, ['status' => 'sold', 'deal' => '2026-11-06']);
        $this->listing($this->a1, ['status' => 'rented', 'deal' => '2026-11-08', 'category' => 2]);

        $series = $this->deals(['date_start' => '2026-11-01', 'date_end' => '2026-11-10'])['series'];

        $this->assertSame('day', $series['granularity']);
        $this->assertCount(10, $series['dates'], 'every day of the window, deals or not');
        $this->assertSame('2026-11-01', $series['dates'][0]);
        $this->assertSame('2026-11-10', $series['dates'][9]);
        $this->assertSame(2, array_sum($series['sold']));
        $this->assertSame(1, array_sum($series['rented']));
        $this->assertSame(2, $series['sold'][array_search('2026-11-06', $series['dates'], true)]);
    }

    public function test_deals_previous_period_is_dropped_when_it_predates_deal_dates(): void
    {
        $this->listing($this->a1, ['status' => 'sold', 'deal' => '2026-04-10']);

        // May compares with April, which is inside the recorded period.
        $may = $this->deals(['date_start' => '2026-05-01', 'date_end' => '2026-05-31']);
        $this->assertSame(['sold' => 1, 'rented' => 0, 'leased' => 0, 'total' => 1], $may['previous']);

        // April compares with March, which began before deal dates did (Mar 26): not a fair comparison.
        $april = $this->deals(['date_start' => '2026-04-01', 'date_end' => '2026-04-30']);
        $this->assertNull($april['previous']);
    }

    public function test_top_locations_are_provinces_then_cities_under_a_province(): void
    {
        $this->listing($this->a1, ['city' => 10, 'status' => 'sold', 'deal' => '2026-11-06']);
        $this->listing($this->a1, ['city' => 10, 'status' => 'sold', 'deal' => '2026-11-07']);
        $this->listing($this->a1, ['city' => 11, 'status' => 'rented', 'deal' => '2026-11-07', 'category' => 2]);
        $this->listing($this->a1, ['city' => 20, 'status' => 'sold', 'deal' => '2026-11-07']);

        $window = ['date_start' => '2026-11-01', 'date_end' => '2026-11-10'];

        $provinces = $this->deals($window)['top_locations'];
        $this->assertSame(['Cebu', 'Davao del Sur'], array_column($provinces, 'name'));
        $this->assertSame([3, 1], array_column($provinces, 'total'));
        $this->assertSame('province', $provinces[0]['level']);

        $cities = $this->deals($window + ['province_id' => 1])['top_locations'];
        $this->assertSame(['Cebu City', 'Mandaue City'], array_column($cities, 'name'));
        $this->assertSame('city', $cities[0]['level']);

        $this->assertSame([], $this->deals($window + ['city_id' => 10])['top_locations'], 'nothing to rank inside one city');
    }

    public function test_the_status_breakdown_on_deal_basis_agrees_with_the_headline(): void
    {
        $this->listing($this->a1, ['added' => '2026-10-01 10:00', 'status' => 'sold', 'deal' => '2026-11-06']); // closed in, added before
        $this->listing($this->a1, ['added' => '2026-10-02 10:00', 'status' => 'sold', 'deal' => '2026-11-08']); // closed in, added before
        $this->listing($this->a1, ['added' => '2026-11-05 10:00', 'status' => 'sold', 'deal' => '2026-11-07']); // both in
        $this->listing($this->a1, ['added' => '2026-11-04 10:00', 'status' => 'sold', 'deal' => '2026-10-20']); // added in, closed before

        $service = app(ListingByStatusService::class);

        $byDeal = $service->statusBreakdown('priority', null, '2026-11-01', '2026-11-10', null, null, 'province', null, null, null, 'deal');
        $this->assertSame(3, $byDeal['meta']['total_listings'], 'closed in the window');
        $this->assertSame(
            $this->deals(['date_start' => '2026-11-01', 'date_end' => '2026-11-10'])['totals']['total'],
            $byDeal['meta']['total_listings'],
            'the drill-down agrees with the Deals headline',
        );

        // The default basis is unchanged — and the SAME instance, reused, carries nothing over.
        $byCreated = $service->statusBreakdown('priority', null, '2026-11-01', '2026-11-10');
        $this->assertSame(2, $byCreated['meta']['total_listings'], 'added in the window');
    }

    // ── Demand ──────────────────────────────────────────────────────────────

    public function test_inquiries_are_client_chats_in_the_manila_range(): void
    {
        $listing = $this->listing($this->a1);
        $client = $this->makeUser(self::CLIENT);

        $this->makeInquiry($listing, $client, '2026-11-05 10:00');
        $this->makeInquiry($listing, $this->makeUser(self::CLIENT), '2026-10-31 23:30'); // the day before the window, in Manila
        $this->makeInquiry($listing, $this->makeUser(self::AGENT), '2026-11-06 10:00');  // an agent's chat is no lead
        $deleted = $this->makeInquiry($listing, $this->makeUser(self::CLIENT), '2026-11-07 10:00');
        DB::table('chats')->where('id', $deleted)->update(['deleted_at' => $this->utc('2026-11-08 10:00')]);

        $window = ['date_start' => '2026-11-01', 'date_end' => '2026-11-10'];
        $inquiries = $this->demand($window)['inquiries'];

        $this->assertSame(1, $inquiries['total'], 'Oct 31 23:30 Manila is the day before the window');
        $this->assertSame(1, $inquiries['listings']);
        $this->assertSame(0, $inquiries['previous'], 'Oct 31 is not in the matching span, Oct 1–10, either');
    }

    public function test_inquiries_equal_the_executive_reports(): void
    {
        $a = $this->listing($this->a1);
        $b = $this->listing($this->a2);
        foreach ([[$a, '2026-11-02 09:00'], [$a, '2026-11-03 09:00'], [$b, '2026-11-09 09:00'], [$b, '2026-10-30 09:00']] as [$listing, $at]) {
            $this->makeInquiry($listing, $this->makeUser(self::CLIENT), $at);
        }

        $range = ReportRange::resolve('2026-11-01', '2026-11-10');

        $this->assertSame(
            app(ReportQueries::class)->inquiries($range)->count('chats.id'),
            $this->demand(['date_start' => '2026-11-01', 'date_end' => '2026-11-10'])['inquiries']['total'],
        );
    }

    public function test_demand_dates_the_event_not_the_day_the_listing_was_added(): void
    {
        $old = $this->listing($this->a1, ['added' => '2026-06-01 10:00']);
        $this->makeInquiry($old, $this->makeUser(self::CLIENT), '2026-11-05 10:00');
        $this->save($old, '2026-11-06 10:00');
        $this->viewDay($old, '2026-11-07', 4);

        $demand = $this->demand(['date_start' => '2026-11-01', 'date_end' => '2026-11-10']);

        $this->assertSame(1, $demand['inquiries']['total']);
        $this->assertSame(1, $demand['saves']['total']);
        $this->assertSame(4, $demand['views']['total']);
    }

    public function test_views_use_view_days_in_a_range_and_the_counter_for_all_time(): void
    {
        $a = $this->listing($this->a1, ['clicks' => 50]);
        $b = $this->listing($this->a2, ['clicks' => 30]);
        $this->viewDay($a, '2026-11-02', 3);
        $this->viewDay($a, '2026-11-09', 2);
        $this->viewDay($b, '2026-11-05', 7);
        $this->viewDay($b, '2026-10-30', 100); // outside

        $ranged = $this->demand(['date_start' => '2026-11-01', 'date_end' => '2026-11-10'])['views'];
        $this->assertSame(12, $ranged['total']);
        $this->assertSame('daily', $ranged['source']);
        $this->assertSame('2026-10-22', $ranged['since']);
        $this->assertFalse($ranged['partial']);

        $all = $this->demand()['views'];
        $this->assertSame(80, $all['total'], 'no daily history to add up for all time: the running counters');
        $this->assertSame('lifetime', $all['source']);
        $this->assertTrue($all['partial']);
        $this->assertNull($all['previous']);
    }

    public function test_views_comparison_is_dropped_when_it_reaches_before_view_tracking(): void
    {
        $listing = $this->listing($this->a1);
        $this->viewDay($listing, '2026-11-03', 5);

        // Nov 1–10 compares with Oct 1–10, which began before view days existed.
        $views = $this->demand(['date_start' => '2026-11-01', 'date_end' => '2026-11-10'])['views'];

        $this->assertNull($views['previous']);
    }

    public function test_saves_count_favorites_created_in_range(): void
    {
        $listing = $this->listing($this->a1);
        $this->save($listing, '2026-11-02 10:00');
        $this->save($listing, '2026-11-09 10:00');
        $this->save($listing, '2026-10-05 10:00'); // the previous span

        $saves = $this->demand(['date_start' => '2026-11-01', 'date_end' => '2026-11-10'])['saves'];

        $this->assertSame(2, $saves['total']);
        $this->assertSame(1, $saves['previous']);
        $this->assertSame(3, $this->demand()['saves']['total']);
    }

    public function test_no_inquiry_is_live_listings_without_an_inquiry_in_the_period(): void
    {
        $asked = $this->listing($this->a1, ['added' => '2026-10-01 10:00']);
        $this->listing($this->a1, ['added' => '2026-10-02 10:00']);                          // live, nobody asked
        $this->listing($this->a1, ['added' => '2026-10-03 10:00', 'visibility' => 'private']); // not live
        $this->listing($this->a1, ['added' => '2026-10-04 10:00', 'status' => 'sold']);     // not live
        $this->listing($this->a1, ['added' => '2026-11-20 10:00']);                          // added after the window ended
        $this->makeInquiry($asked, $this->makeUser(self::CLIENT), '2026-11-05 10:00');
        $this->makeInquiry($asked, $this->makeUser(self::CLIENT), '2026-09-05 10:00');

        $none = $this->demand(['date_start' => '2026-11-01', 'date_end' => '2026-11-10'])['no_inquiry'];

        $this->assertSame(['base' => 2, 'count' => 1], $none);
    }

    public function test_top_orders_by_inquiries_then_views(): void
    {
        $quiet = $this->listing($this->a1, ['name' => 'Quiet']);
        $busy = $this->listing($this->a1, ['name' => 'Busy']);
        $viewed = $this->listing($this->a1, ['name' => 'Viewed']);
        $this->listing($this->a1, ['name' => 'Untouched']);
        $this->makeInquiry($busy, $this->makeUser(self::CLIENT), '2026-11-02 10:00');
        $this->makeInquiry($busy, $this->makeUser(self::CLIENT), '2026-11-03 10:00');
        $this->makeInquiry($quiet, $this->makeUser(self::CLIENT), '2026-11-04 10:00');
        $this->viewDay($viewed, '2026-11-05', 50);
        $this->viewDay($quiet, '2026-11-05', 1);

        $top = $this->demand(['date_start' => '2026-11-01', 'date_end' => '2026-11-10'])['top'];

        $this->assertSame(['Busy', 'Quiet', 'Viewed'], array_column($top, 'name'));
        $this->assertSame([2, 1, 0], array_column($top, 'inquiries'));
        $this->assertSame([0, 1, 50], array_column($top, 'views'));
    }

    // ── Prices ──────────────────────────────────────────────────────────────

    public function test_prices_summary_returns_quartiles_per_type_for_sale_and_rent(): void
    {
        foreach ([1, 2, 3, 4, 5] as $million) {
            $this->listing($this->a1, ['subtype' => 21, 'price' => $million * 1_000_000, 'floor' => 100]); // houses, ₱/sqm 10k…50k
        }
        $this->listing($this->a1, ['subtype' => 11, 'price' => 4_000_000, 'floor' => 40]);                  // one condo
        $this->listing($this->a1, ['subtype' => 21, 'price' => 20_000, 'floor' => 50, 'category' => 2]);    // a rental

        $prices = $this->prices();

        $house = $prices['sale']['types'][0];
        $this->assertSame('House', $house['type']);
        $this->assertSame(5, $house['n']);
        $this->assertSame(3_000_000, $house['median']);
        $this->assertSame(2_000_000, $house['p25']);
        $this->assertSame(4_000_000, $house['p75']);
        $this->assertSame(30_000, $house['median_ppsqm']);
        $this->assertSame(5, $house['ppsqm_n']);

        $this->assertSame(['House', 'Condominium', 'Land', 'Commercial'], array_column($prices['sale']['types'], 'type'));
        $land = $prices['sale']['types'][2];
        $this->assertSame(0, $land['n']);
        $this->assertNull($land['median'], 'a type with nothing priced still has a row, with no figures');

        $this->assertSame(6, $prices['sale']['n']);
        $this->assertSame(1, $prices['rent']['n']);
        $this->assertSame(20_000, $prices['rent']['median']);
    }

    public function test_prices_summary_honours_dates_and_place(): void
    {
        $this->listing($this->a1, ['subtype' => 21, 'city' => 10, 'price' => 1_000_000, 'added' => '2026-11-05 10:00']);
        $this->listing($this->a1, ['subtype' => 21, 'city' => 20, 'price' => 9_000_000, 'added' => '2026-11-05 10:00']);
        $this->listing($this->a1, ['subtype' => 21, 'city' => 10, 'price' => 5_000_000, 'added' => '2026-09-05 10:00']);

        $this->assertSame(3, $this->prices()['sale']['n']);
        $this->assertSame(2, $this->prices(['date_start' => '2026-11-01', 'date_end' => '2026-11-10'])['sale']['n']);
        $this->assertSame(1, $this->prices(['date_start' => '2026-11-01', 'date_end' => '2026-11-10', 'province_id' => 2])['sale']['n']);
        $this->assertSame(9_000_000, $this->prices(['province_id' => 2])['sale']['median']);
    }

    public function test_prices_by_type_without_a_view_still_answers_the_admin_dashboards_way(): void
    {
        // The main dashboard's "Prices by Type" reads {category, types[…]} and sends no dates. Its own
        // query joins tables (furnishings) and uses JSON_LENGTH that this SQLite schema can't run, so
        // what is pinned here is the branch: no `view=summary` means byType(), never typeSummary().
        $legacy = ['category' => 'For Sale', 'types' => []];
        $this->mock(ListingPriceInsightsService::class, function ($mock) use ($legacy) {
            $mock->shouldReceive('byType')->once()->with(['category' => 'For Sale'], null)->andReturn($legacy);
            $mock->shouldNotReceive('typeSummary');
        });

        $response = $this->hit('insightsPricesByType', ListingPriceInsightsService::class, ['category' => 'For Sale']);

        $this->assertSame($legacy, $response->getData(true));
        $this->assertNull($response->headers->get('X-Insights-Cache'), 'the legacy answer is not served through the new cache');
    }

    // ── Who may ask, and what they are served ───────────────────────────────

    public function test_a_team_leader_sees_only_their_team_on_every_endpoint(): void
    {
        $this->listing($this->a1, ['status' => 'sold', 'deal' => '2026-11-06']);
        $this->listing($this->a2, ['status' => 'sold', 'deal' => '2026-11-07']);
        $this->listing($this->a3, ['status' => 'sold', 'deal' => '2026-11-08']);

        $leader = $this->summary([], 'agent', $this->u1);
        $this->assertSame(2, $leader['total'], 'Alpha has two of the three');
        $this->assertSame('team', $leader['scope']);

        $this->assertSame(3, $this->summary()['total']);
        $this->assertSame(2, $this->deals([], 'agent', $this->u1)['totals']['total']);
        $this->assertSame(3, $this->deals()['totals']['total']);
    }

    public function test_a_user_who_leads_nobody_is_refused(): void
    {
        [, $plainAgent] = $this->makeAgent();

        $this->expectException(HttpException::class);
        $this->summary([], 'agent', $plainAgent);
    }

    public function test_a_second_call_inside_the_ttl_does_not_touch_the_listings(): void
    {
        $this->listing($this->a1);

        $first = $this->hit('insightsSummary', ListingSummaryService::class);
        $this->listing($this->a1); // arrives after the first answer was cached
        $second = $this->hit('insightsSummary', ListingSummaryService::class);

        $this->assertSame('miss', $first->headers->get('X-Insights-Cache'));
        $this->assertSame('hit', $second->headers->get('X-Insights-Cache'));
        $this->assertSame(1, $second->getData(true)['total'], 'the cached answer, not a recount');
    }

    public function test_the_cache_key_separates_admins_from_team_leaders(): void
    {
        $this->listing($this->a1);
        $this->listing($this->a3);

        $admin = $this->summary();
        $leader = $this->summary([], 'agent', $this->u1);

        $this->assertSame(2, $admin['total']);
        $this->assertSame(1, $leader['total'], 'a leader must not be served the admin payload');
        $this->assertSame(2, $this->summary()['total'], 'and the admin entry is still the admin entry');
    }

    public function test_junk_params_do_not_mint_cache_entries_of_their_own(): void
    {
        $this->listing($this->a1);
        $cache = fn (array $q) => $this->hit('insightsCreated', ListingCreatedService::class, $q)->headers->get('X-Insights-Cache');

        $this->assertSame('miss', $cache(['granularity' => 'day']));
        // Any other granularity already read as "day"; it must also share day's entry.
        $this->assertSame('hit', $cache(['granularity' => 'foo']));
        $this->assertSame('hit', $cache(['granularity' => 'bar']));
        // `date_basis` is read by none of the cached endpoints, so it must not split an entry either.
        $this->assertSame('hit', $cache(['granularity' => 'day', 'date_basis' => 'deal']));
        // A real, different granularity IS a different answer.
        $this->assertSame('miss', $cache(['granularity' => 'month']));
    }

    public function test_each_range_and_place_gets_its_own_entry(): void
    {
        $this->listing($this->a1, ['city' => 10, 'added' => '2026-11-05 10:00']);
        $this->listing($this->a1, ['city' => 20, 'added' => '2026-09-05 10:00']);

        $this->assertSame(2, $this->summary()['total']);
        $this->assertSame(1, $this->summary(['date_start' => '2026-11-01', 'date_end' => '2026-11-10'])['total']);
        $this->assertSame(1, $this->summary(['province_id' => 2])['total']);
        $this->assertSame(2, $this->summary()['total'], 'the all-time entry was not overwritten');
    }

    public function test_a_reused_service_never_carries_a_range_or_basis_into_the_next_call(): void
    {
        $this->listing($this->a1, ['added' => '2026-11-05 10:00']);
        $this->listing($this->a1, ['added' => '2026-09-05 10:00']);

        $service = app(ListingSummaryService::class);

        $this->assertSame(1, $service->summary(['date_start' => '2026-11-01', 'date_end' => '2026-11-10'])['total']);
        $this->assertSame(2, $service->summary([])['total'], 'no window now means no window');

        $deals = app(ListingDealsService::class);
        $this->listing($this->a1, ['status' => 'sold', 'deal' => '2026-11-06', 'added' => '2026-09-06 10:00']);
        $this->assertSame(1, $deals->deals(['date_start' => '2026-11-01', 'date_end' => '2026-11-10'])['totals']['total']);
        $this->assertSame(1, $deals->deals([])['totals']['total']);
    }

    public function test_the_older_by_type_tab_answers_with_and_without_dates(): void
    {
        // The older tab calls by-type with no dates, or Y-m-d dates; both must keep answering,
        // and the window now means Manila days.
        $this->listing($this->a1, ['added' => '2026-11-05 10:00']);
        $this->listing($this->a1, ['added' => '2026-10-31 23:30']);

        $total = fn (array $query) => $this->hit('insightsByType', ByTypeService::class, $query)->getData(true)['meta']['total_listings'];

        $this->assertSame(2, $total([]));
        $this->assertSame(1, $total(['date_start' => '2026-11-01', 'date_end' => '2026-11-10']));
        $this->assertSame(1, $total(['date_start' => '2026-10-31', 'date_end' => '2026-10-31']));
    }
}
