<?php

namespace Tests\Feature;

use App\Http\Requests\FhOne\MetricRequest;
use App\Services\FhOne\BreakdownService;
use App\Services\Reports\Executive\DrillService;
use App\Services\Reports\Executive\ExecutiveReportService;
use App\Services\Reports\Executive\MetricRegistry;
use App\Services\Reports\Executive\ReportRange;
use App\Support\FhOneKeys;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/**
 * FH One's API (/api/fh-one): who is let in, and that every number it hands
 * out is the AI Report's own, with exactly the records behind it.
 *
 * It runs on the Executive Report's fixture (seedExecutiveScenario()): today
 * is frozen at Nov 10, 2026, 2 PM Manila and the range is Nov 1–10.
 */
class FhOneApiTest extends TestCase
{
    use CreatesExecutiveReportTables;

    private const KEY = 'fhone_test_key_0123456789abcdefghijklmnopqrstuvwxyz';

    private const OTHER_KEY = 'fhone_other_key_0123456789abcdefghijklmnopqrstuvwxy';

    private const DATES = ['date_start' => '2026-11-01', 'date_end' => '2026-11-10'];

    private Carbon $now;

    /** @var array<string, int> */
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->now = Carbon::parse('2026-11-10 14:00', 'Asia/Manila');
        Carbon::setTestNow($this->now);
        $this->createExecutiveReportTables();
        $this->id = $this->seedExecutiveScenario();

        // The limit has its own tests; the rest make far more than 120 calls a minute.
        config(['fhone.keys' => FhOneKeys::entry('test-key', self::KEY, '2026-12-31'), 'fhone.rate_limit' => 100000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A call with the key (or with none, or another one). A path starting with "/" is outside /fh-one. */
    private function hit(string $path, array $query = [], ?string $key = self::KEY, array $headers = []): TestResponse
    {
        if ($key !== null) {
            $headers += ['Authorization' => 'Bearer '.$key];
        }

        $url = str_starts_with($path, '/') ? '/api'.$path : '/api/fh-one/'.$path;

        return $this->getJson($url.($query ? '?'.http_build_query($query) : ''), $headers);
    }

    private function api(string $path, array $query = [], ?string $key = self::KEY): TestResponse
    {
        return $this->hit('v1/'.$path, $query, $key);
    }

    private function dated(array $query = []): array
    {
        return self::DATES + $query;
    }

    private function range(): ReportRange
    {
        return ReportRange::resolve('2026-11-01', '2026-11-10');
    }

    // ── Who is let in ───────────────────────────────────────────────────────

    public function test_every_endpoint_needs_the_key(): void
    {
        foreach ([
            '/filipinohomes/listings', 'v1/health', 'v1/metrics', 'v1/metrics/deals.sold', 'v1/metrics/deals.sold/breakdown?by=team',
            'v1/metrics/deals.sold/records', 'v1/lookup/teams', 'v1/projects', 'v1/projects/1', 'v1/metrics/batch?ids=deals.sold',
        ] as $path) {
            $this->hit($path, [], null)
                ->assertStatus(401)
                ->assertExactJson(['error' => 'Unauthorized'])
                ->assertHeader('WWW-Authenticate', 'Bearer');
        }
    }

    public function test_a_wrong_expired_or_oddly_sent_key_is_refused_the_same_way(): void
    {
        config(['app.fh_service_token' => 'service-secret', 'app.build_token' => 'build-secret']);
        $expired = FhOneKeys::entry('old-key', self::OTHER_KEY, '2026-11-09'); // yesterday, Manila
        config(['fhone.keys' => FhOneKeys::entry('test-key', self::KEY, '2026-12-31').','.$expired]);

        $refused = [
            'a made-up key' => $this->hit('v1/health', [], 'fhone_not_a_real_key'),
            'an expired key' => $this->hit('v1/health', [], self::OTHER_KEY),
            'the key in the query string' => $this->hit('v1/health', ['token' => self::KEY], null),
            'the key without Bearer' => $this->hit('v1/health', [], null, ['Authorization' => self::KEY]),
            'a Basic header' => $this->hit('v1/health', [], null, ['Authorization' => 'Basic '.base64_encode('a:b')]),
            'the key in another header' => $this->hit('v1/health', [], null, ['X-FH-One-Key' => self::KEY]),
            // The tokens that lift the general API limit open nothing here.
            'the service token' => $this->hit('v1/health', [], null, ['X-FH-Service-Token' => 'service-secret']),
            'the build token' => $this->hit('v1/health', [], null, ['X-Build-Token' => 'build-secret']),
        ];

        foreach ($refused as $what => $response) {
            $response->assertStatus(401)->assertExactJson(['error' => 'Unauthorized']);
        }
    }

    public function test_the_last_valid_day_is_included_and_ends_at_midnight_manila(): void
    {
        config(['fhone.keys' => FhOneKeys::entry('test-key', self::KEY, '2026-11-10')]);

        $this->api('health')->assertOk()->assertJsonPath('key.days_left', 0);

        Carbon::setTestNow(Carbon::parse('2026-11-11 00:01', 'Asia/Manila'));

        $this->api('health')->assertStatus(401);
    }

    public function test_a_broken_entry_fails_closed_and_does_not_spoil_the_good_ones(): void
    {
        $good = FhOneKeys::entry('test-key', self::KEY, '2026-12-31');

        foreach ([
            'oops', 'test-key:abc:2026-12-31', 'Bad Name:'.hash('sha256', self::KEY).':2026-12-31',
            'test-key:'.hash('sha256', self::KEY).':2026-02-31', 'test-key:'.hash('sha256', self::KEY).':soon',
            'test-key:'.hash('sha256', self::KEY),
        ] as $broken) {
            config(['fhone.keys' => $broken]);
            $this->api('health')->assertStatus(401);
        }

        config(['fhone.keys' => "oops, {$good} ,"]);
        $this->api('health')->assertOk();

        config(['fhone.keys' => '']);
        $this->api('health')->assertStatus(401);
    }

    public function test_two_keys_can_overlap_while_one_is_replaced(): void
    {
        config(['fhone.keys' => FhOneKeys::entry('test-key', self::KEY, '2026-12-31').','.FhOneKeys::entry('next-key', self::OTHER_KEY, '2027-02-28')]);

        $this->api('health', [], self::KEY)->assertOk()->assertJsonPath('key.name', 'test-key');
        $this->api('health', [], self::OTHER_KEY)->assertOk()->assertJsonPath('key.name', 'next-key');
    }

    public function test_the_limit_is_per_key_and_nothing_lifts_it(): void
    {
        config([
            'fhone.rate_limit' => 3,
            'fhone.keys' => FhOneKeys::entry('test-key', self::KEY, '2026-12-31').','.FhOneKeys::entry('next-key', self::OTHER_KEY, '2027-02-28'),
            'app.fh_service_token' => 'service-secret',
            'app.build_token' => 'build-secret',
        ]);

        foreach (range(1, 3) as $ignored) {
            $this->api('health')->assertOk();
        }

        $this->api('health')->assertStatus(429);
        $this->hit('v1/health', [], self::KEY, ['X-FH-Service-Token' => 'service-secret', 'X-Build-Token' => 'build-secret'])->assertStatus(429);
        $this->api('health', [], self::OTHER_KEY)->assertOk(); // its own bucket
    }

    public function test_calls_without_a_key_do_not_use_up_a_keys_allowance(): void
    {
        config(['fhone.rate_limit' => 3]);

        // The key is checked before anything is counted, so refused calls cost the good key nothing.
        foreach (range(1, 5) as $ignored) {
            $this->hit('v1/health', [], null)->assertStatus(401);
        }

        foreach (range(1, 3) as $ignored) {
            $this->api('health')->assertOk();
        }

        $this->api('health')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_guessing_keys_is_cut_off_even_for_the_right_key(): void
    {
        foreach (range(1, 20) as $ignored) {
            $this->api('health', [], 'fhone_guess')->assertStatus(401);
        }

        $this->api('health')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_the_log_has_who_asked_and_never_the_key_or_odd_headers(): void
    {
        Log::spy();

        $this->hit('v1/health', [], self::KEY, ['X-FH-One-User' => '7', 'X-FH-One-Role' => 'admin', 'X-Request-Id' => 'req-123'])
            ->assertHeader('X-Request-Id', 'req-123');

        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $message === 'fhone.request'
            && $context['key'] === 'test-key' && $context['user'] === '7' && $context['role'] === 'admin'
            && $context['request_id'] === 'req-123' && $context['status'] === 200 && $context['path'] === 'api/fh-one/v1/health');

        // Anything that isn't a plain token is dropped, not logged.
        $odd = $this->hit('v1/health', [], self::KEY, ['X-FH-One-User' => "7'; DROP TABLE", 'X-FH-One-Role' => 'Ad min', 'X-Request-Id' => "x\ny"]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $odd->headers->get('X-Request-Id'));
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'fhone.request'
            && $context['user'] === null && $context['role'] === null);

        // A refused call is logged with the reason, and the secret it carried stays out.
        $this->hit('v1/health', [], 'fhone_secret_guess')->assertStatus(401);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'fhone.rejected'
            && $context['reason'] === 'unknown_or_missing_key' && ! str_contains(json_encode($context), 'fhone_secret_guess'));
    }

    // ── Health ──────────────────────────────────────────────────────────────

    public function test_health_proves_the_key_and_says_how_long_it_is_good_for(): void
    {
        $this->api('health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'filipino-homes')
            ->assertJsonPath('database', 'ok')
            ->assertJsonPath('key.name', 'test-key')
            ->assertJsonPath('key.last_valid_day', '2026-12-31')
            ->assertJsonPath('key.days_left', 51)
            ->assertJsonPath('time', '2026-11-10T14:00:00+08:00');
    }

    // ── Catalog ─────────────────────────────────────────────────────────────

    public function test_the_catalog_describes_every_metric(): void
    {
        $catalog = $this->api('metrics')->assertOk()->json();
        $metrics = collect($catalog['metrics'])->keyBy('id');

        $this->assertCount(count(MetricRegistry::keys()), $catalog['metrics']);
        $this->assertSame('v1', $catalog['version']);

        foreach ($metrics as $id => $metric) {
            foreach (['label', 'definition', 'unit', 'kind', 'measured', 'better', 'entity', 'family', 'trust', 'filters', 'breakdowns'] as $field) {
                $this->assertArrayHasKey($field, $metric, "{$id} lacks {$field}");
            }
            $this->assertContains($metric['trust'], ['fh_signal', 'fh_record']);
            $this->assertContains($metric['measured'], ['range', 'now']);
        }

        // Marked-sold numbers are a stand-in, and say when FH began recording them.
        $this->assertSame('fh_signal', $metrics['deals.sold']['trust']);
        $this->assertSame('fh_signal', $metrics['claims.sold_unmarked']['trust']);
        $this->assertSame('fh_record', $metrics['listings.added']['trust']);
        $this->assertSame('2026-03-26', $metrics['deals.sold']['data_since']);
        $this->assertNull($metrics['listings.added']['data_since']);
        $this->assertSame('range', $metrics['deals.sold']['measured']);
        $this->assertSame('now', $metrics['inquiries.hanging.total']['measured']);

        // Filters differ by family; project_ids is for listings only.
        $this->assertContains('project_ids', $metrics['deals.sold']['filters']);
        $this->assertSame(['team_id', 'office_region', 'agent_id'], $metrics['leads.all']['filters']);
        $this->assertNotContains('project_ids', $metrics['leads.all']['filters']);

        // Only counts can be split, and each family by what its rows carry.
        $this->assertSame(array_keys(BreakdownService::DIMENSIONS), $metrics['deals.sold']['breakdowns']);
        $this->assertSame(['team', 'agent'], $metrics['inquiries.hanging.total']['breakdowns']);
        $this->assertSame(['team', 'office_region', 'agent'], $metrics['leads.waiting']['breakdowns']);
        $this->assertSame(['team', 'office_region'], $metrics['agents.active']['breakdowns']);
        $this->assertSame([], $metrics['listings.ppsqm_sale']['breakdowns']);
        $this->assertSame([], $metrics['usage.total_users']['breakdowns']);

        // The filter and split reference FH One builds its tools from.
        $this->assertSame('teams', $catalog['filters']['team_id']['lookup']);
        $this->assertSame('team_id', $catalog['breakdowns']['team']['filter']);
        $this->assertContains('for_sale', $catalog['filters']['category']['values']);
    }

    public function test_every_filter_the_catalog_lists_is_one_the_api_accepts(): void
    {
        $accepted = array_keys((new MetricRequest)->rules());

        foreach (MetricRegistry::keys() as $key) {
            $this->assertSame([], array_values(array_diff(MetricRegistry::filters($key), $accepted)), "{$key} lists a filter the API would reject");
        }
    }

    // ── Values ──────────────────────────────────────────────────────────────

    public function test_every_metric_is_the_ai_reports_number_and_its_records_count_the_same(): void
    {
        $context = app(ExecutiveReportService::class)->context($this->range(), $this->now);

        foreach (MetricRegistry::keys() as $key) {
            $stat = $context->stat($key);
            $response = $this->api("metrics/{$key}", $this->dated())->assertOk();

            $this->assertEquals($stat['value'], $response->json('value'), "{$key}: the API and the AI Report disagree");

            $records = $this->api("metrics/{$key}/records", $this->dated(['per_page' => 1]))->assertOk();
            $expected = $stat['kind'] === 'median' ? $stat['sample'] : $stat['value'];
            $this->assertSame((int) $expected, $records->json('meta.total'), "{$key}: the records don't count what the number says");
            $this->assertSame($key, $records->json('metric'));
        }
    }

    public function test_all_listings_counts_every_listing_that_is_not_deleted_live_or_not(): void
    {
        $live = $this->api('metrics/listings.live')->assertOk()->json('value');
        $all = $this->api('metrics/listings.total')->assertOk()
            ->assertJsonPath('label', 'All listings')
            ->assertJsonPath('measured', 'now')
            ->assertJsonPath('trust', 'fh_record')
            ->json('value');

        // The admin's Total Listings: every listing with a category and a property that is not deleted.
        $this->assertSame(DB::table('listings')->whereNull('deleted_at')->count(), $all);
        // Live is the part of it that is public and still available, so All is never fewer.
        $this->assertGreaterThan($live, $all);

        // A deleted listing is in neither.
        DB::table('listings')->where('id', DB::table('listings')->min('id'))->update(['deleted_at' => $this->now]);
        Cache::flush();

        $this->assertSame($all - 1, $this->api('metrics/listings.total')->json('value'));

        // Its records are the listings it counts.
        $this->assertSame($all - 1, $this->api('metrics/listings.total/records', ['per_page' => 1])->json('meta.total'));
    }

    // ── Many at once ────────────────────────────────────────────────────────

    /** The queries run while `$work` does. */
    /** A project with one listing of its own — a copy of a Mantra listing. */
    private function projectWithListing(string $name, array $project = []): int
    {
        $projectId = (int) DB::table('projects')->insertGetId(['name' => $name, 'slug' => Str::slug($name)] + $project);

        $listing = (array) DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->where('properties.project_id', $this->id['mantra'])
            ->select('listings.*')->first();
        $property = (array) DB::table('properties')->find($listing['property_id']);

        unset($property['id'], $listing['id']);
        $listing['property_id'] = DB::table('properties')->insertGetId(['project_id' => $projectId] + $property);
        foreach (['slug', 'code'] as $unique) {
            if (array_key_exists($unique, $listing) && $listing[$unique] !== null) {
                $listing[$unique] .= '-'.$projectId;
            }
        }
        DB::table('listings')->insert($listing);

        return $projectId;
    }

    private function queriesDuring(callable $work): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $work();

        return $count;
    }

    public function test_a_batch_gives_every_metric_exactly_as_a_call_for_each_does(): void
    {
        $ids = MetricRegistry::keys();

        $batch = $this->api('metrics/batch', $this->dated(['ids' => implode(',', $ids), 'compare_ids' => 'listings.added,deals.sold']))->assertOk();

        $results = $batch->json('results');

        $this->assertSame([], $batch->json('errors'));
        $this->assertSame($ids, array_keys($results), 'the results come in the order they were asked for');

        // What each number is when it is worked out on its own, from nothing kept.
        Cache::flush();

        foreach ($ids as $id) {
            $compare = in_array($id, ['listings.added', 'deals.sold'], true) ? ['compare' => 'previous'] : [];

            $this->assertEquals(
                $this->api("metrics/{$id}", $this->dated($compare))->assertOk()->json(),
                $results[$id],
                "{$id}: the batch and a call of its own disagree",
            );
        }

        // Only the two that asked for it carry the previous period.
        $this->assertNotNull($results['listings.added']['compare']);
        $this->assertNull($results['leads.all']['compare']);
    }

    public function test_a_batch_works_out_its_numbers_together_where_a_call_each_would_start_over_each_time(): void
    {
        $ids = ['agents.headcount', 'agents.posting', 'agents.active', 'agents.idle', 'agents.dormant_30d', 'agents.never_used', 'agents.dormant_used', 'agents.gone_quiet', 'agents.with_leads', 'agents.unanswered', 'leads.all', 'leads.reached', 'leads.answered', 'leads.waiting'];

        Cache::flush();
        $together = $this->queriesDuring(fn () => $this->api('metrics/batch', $this->dated(['ids' => implode(',', $ids)]))->assertOk());

        Cache::flush();
        $apart = $this->queriesDuring(function () use ($ids): void {
            foreach ($ids as $id) {
                $this->api("metrics/{$id}", $this->dated())->assertOk();
            }
        });

        $this->assertLessThan($apart, $together, "a batch ran {$together} queries and a call each {$apart}: the batch is not sharing");
    }

    public function test_what_a_batch_works_out_is_kept_for_single_calls_and_the_other_way_round(): void
    {
        $this->api('metrics/batch', $this->dated(['ids' => 'listings.added,deals.sold']))->assertOk();

        // A call for one of them now finds it kept: no query at all.
        $this->assertSame(0, $this->queriesDuring(fn () => $this->api('metrics/listings.added', $this->dated())->assertOk()));

        // And a number a single call kept is not worked out again by a batch that includes it.
        Cache::flush();
        $this->api('metrics/deals.total', $this->dated())->assertOk();

        $this->assertSame(0, $this->queriesDuring(fn () => $this->api('metrics/batch', $this->dated(['ids' => 'deals.total']))->assertOk()));
    }

    public function test_a_batch_says_what_it_cannot_answer_and_still_answers_the_rest(): void
    {
        $team = $this->id['team_a'] ?? 1;
        $batch = $this->api('metrics/batch', $this->dated(['ids' => 'deals.sold,no.such.metric,usage.total_users', 'team_id' => $team]))->assertOk();
        $errors = $batch->json('errors');
        $results = $batch->json('results');

        // A metric FH has no such filter for is told so, in the field it is about, and so is one it has not heard of.
        $this->assertSame('unknown_metric', $errors['no.such.metric']['error']);
        $this->assertSame('invalid_filter', $errors['usage.total_users']['error']);
        $this->assertSame('filters', $errors['usage.total_users']['field']);
        $this->assertSame(['deals.sold'], array_keys($results));
        $this->assertSame($this->api('metrics/deals.sold', $this->dated(['team_id' => $team]))->json('value'), $results['deals.sold']['value']);
    }

    public function test_a_batch_refuses_what_it_cannot_make_sense_of(): void
    {
        $many = implode(',', array_map(fn (int $n): string => "m.n{$n}", range(1, 61)));

        foreach ([
            'no ids' => [[], 'ids'],
            'an empty list' => [['ids' => ''], 'ids'],
            'too many' => [['ids' => $many], 'ids'],
            'capitals and spaces' => [['ids' => 'Deals.Sold, listings.added'], 'ids'],
            'a comparison with nothing to compare' => [['ids' => 'deals.sold', 'compare_ids' => 'listings.added'], 'compare_ids'],
            'a parameter nobody knows' => [['ids' => 'deals.sold', 'colour' => 'red'], 'query'],
            'a filter that is not one' => [['ids' => 'deals.sold', 'team_id' => 'abc'], 'team_id'],
        ] as $label => [$query, $field]) {
            $this->api('metrics/batch', $this->dated($query))->assertStatus(422)->assertJsonValidationErrors($field, responseKey: 'errors');
            $this->assertTrue(true, $label);
        }
    }

    public function test_the_catalog_says_the_batch_is_there_and_how_many_it_takes(): void
    {
        $this->assertSame(['batch' => ['max_ids' => 60]], $this->api('metrics')->assertOk()->json('capabilities'));
    }

    public function test_a_number_is_worked_out_once_at_a_time_and_the_hold_is_let_go(): void
    {
        $this->api('metrics/listings.live', $this->dated())->assertOk();

        $range = $this->range();
        $name = 'fhone:v1:value:'.sha1(json_encode(['listings.live', $range->key(), [], false])).':build';

        // Nobody is left holding it: another build of the same number starts at once.
        $lock = Cache::lock($name, 5);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_a_value_carries_its_definition_trust_dates_and_when_it_was_computed(): void
    {
        $this->api('metrics/deals.sold', $this->dated())
            ->assertOk()
            ->assertJsonPath('metric', 'deals.sold')
            ->assertJsonPath('label', 'Sold')
            ->assertJsonPath('value', 1)
            ->assertJsonPath('trust', 'fh_signal')
            ->assertJsonPath('measured', 'range')
            ->assertJsonPath('partial', false)
            ->assertJsonPath('data_since', '2026-03-26')
            ->assertJsonPath('range.start', '2026-11-01')
            ->assertJsonPath('range.end', '2026-11-10')
            ->assertJsonPath('range.tz', 'Asia/Manila')
            ->assertJsonPath('compare', null)
            ->assertJsonPath('generated_at', '2026-11-10T14:00:00+08:00');

        // Dates before FH recorded deal dates are flagged: the number runs low.
        $this->api('metrics/deals.sold', ['date_start' => '2026-01-01', 'date_end' => '2026-11-10'])
            ->assertOk()->assertJsonPath('partial', true);
    }

    public function test_the_previous_period_can_come_with_the_value(): void
    {
        // Nov 1–10: 9 listings added. Oct 1–10: one (L2).
        $this->api('metrics/listings.added', $this->dated(['compare' => 'previous']))
            ->assertOk()
            ->assertJsonPath('value', 9)
            ->assertJsonPath('compare.range.start', '2026-10-01')
            ->assertJsonPath('compare.range.end', '2026-10-10')
            ->assertJsonPath('compare.value', 1)
            ->assertJsonPath('compare.change_pct', 800);

        // A number measured at this moment has no previous period.
        $this->api('metrics/inquiries.hanging.total', $this->dated(['compare' => 'previous']))
            ->assertOk()->assertJsonPath('compare', null)->assertJsonPath('measured', 'now');
    }

    public function test_filters_narrow_a_number_and_say_in_words_what_they_are(): void
    {
        $alpha = $this->id['alpha'];
        $mantra = $this->id['mantra'];

        $this->api('metrics/listings.added', $this->dated(['team_id' => $alpha]))
            ->assertOk()->assertJsonPath('value', 5)->assertJsonPath('filters.team_id', $alpha)->assertJsonPath('filter_labels.team_id', 'Alpha');
        $this->api('metrics/listings.added', $this->dated(['team_id' => 0]))
            ->assertOk()->assertJsonPath('value', 2)->assertJsonPath('filter_labels.team_id', 'No team');
        $this->api('metrics/listings.added', $this->dated(['office_region' => 'davao']))->assertOk()->assertJsonPath('value', 2);
        $this->api('metrics/listings.added', $this->dated(['category' => 'for_rent']))->assertOk()->assertJsonPath('value', 1);

        // Several projects at once: the union of each one alone.
        $this->api('metrics/listings.added', $this->dated(['project_id' => $mantra]))->assertOk()->assertJsonPath('value', 1);
        $this->api('metrics/listings.added', $this->dated(['project_ids' => "{$mantra},9999"]))
            ->assertOk()->assertJsonPath('value', 1)->assertJsonPath('filters.project_ids', [$mantra, 9999])->assertJsonPath('filter_labels.project_ids', '2 projects');
        $this->api('metrics/listings.added', $this->dated(['project_ids' => (string) $mantra]))
            ->assertOk()->assertJsonPath('filter_labels.project_ids', 'Mantra');
        $this->api('metrics/listings.added', $this->dated(['project_ids' => '9998,9999']))->assertOk()->assertJsonPath('value', 0);

        // ...for the inquiry lifecycle as well.
        $this->api('metrics/inquiries.reached_agent', $this->dated(['project_ids' => (string) $mantra]))->assertOk();
    }

    public function test_a_filter_the_metric_does_not_take_is_refused_not_dropped(): void
    {
        foreach ([
            'usage.total_users' => ['team_id' => 1],
            'leads.all' => ['project_id' => 1],
            'leads.all' => ['project_ids' => '1,2'],
            'listings.added' => ['role' => 'agent'],
            'teams.total' => ['office_region' => 'cebu'],
        ] as $key => $query) {
            $this->api("metrics/{$key}", $this->dated($query))->assertStatus(422)->assertJsonValidationErrors('filters');
        }
    }

    public function test_an_unknown_or_invalid_parameter_is_refused_not_ignored(): void
    {
        foreach ([
            ['team' => 1],                                   // a misspelled filter
            ['date_start' => '2026-13-45'],
            ['date_start' => '2024-12-31'],                  // before the report starts
            ['date_start' => '2026-11-10', 'date_end' => '2026-11-01'],
            ['date_start' => '2025-01-01', 'date_end' => '2026-11-10'], // over 366 days
            ['compare' => 'weird'],
            ['team_id' => 'abc'],
            ['project_ids' => '1,,2'],
            ['category' => 'for_free'],
        ] as $query) {
            $this->api('metrics/listings.added', $query)->assertStatus(422);
        }

        $this->api('metrics/not.a.metric')
            ->assertStatus(404)->assertJsonPath('error', 'unknown_metric');
    }

    public function test_dates_default_to_the_last_thirty_days_and_a_future_end_is_clamped(): void
    {
        $this->api('metrics/listings.added')
            ->assertOk()->assertJsonPath('range.start', '2026-10-12')->assertJsonPath('range.end', '2026-11-10')->assertJsonPath('range.days', 30);

        $this->api('metrics/listings.added', ['date_start' => '2026-11-01', 'date_end' => '2026-12-31'])
            ->assertOk()->assertJsonPath('range.end', '2026-11-10')->assertJsonPath('range.clamped', true);
    }

    public function test_a_median_says_how_many_records_it_was_taken_over(): void
    {
        $response = $this->api('metrics/listings.ppsqm_sale', $this->dated())->assertOk();
        $records = $this->api('metrics/listings.ppsqm_sale/records', $this->dated())->assertOk();

        $this->assertGreaterThan(0, $response->json('sample'));
        $this->assertSame($response->json('sample'), $records->json('meta.total'));
        $this->assertSame('php_per_sqm', $response->json('unit'));
    }

    public function test_a_number_is_reused_for_a_few_minutes_then_recomputed(): void
    {
        $first = $this->api('metrics/listings.added', $this->dated())->assertOk();
        $this->assertSame(9, $first->json('value'));

        $this->makeListing($this->id['a1'], ['added' => '2026-11-10 09:00']);

        Carbon::setTestNow($this->now->copy()->addMinutes(2));
        $again = $this->api('metrics/listings.added', $this->dated())->assertOk();
        $this->assertSame(9, $again->json('value'));
        $this->assertSame($first->json('generated_at'), $again->json('generated_at'));

        Carbon::setTestNow($this->now->copy()->addMinutes(6));
        $fresh = $this->api('metrics/listings.added', $this->dated())->assertOk();
        $this->assertSame(10, $fresh->json('value'));
        $this->assertNotSame($first->json('generated_at'), $fresh->json('generated_at'));
    }

    // ── Breakdowns ──────────────────────────────────────────────────────────

    public function test_deals_by_team(): void
    {
        $response = $this->api('metrics/deals.total/breakdown', $this->dated(['by' => 'team']))->assertOk();

        $response->assertJsonPath('by', 'team')
            ->assertJsonPath('dimension.filter', 'team_id')
            ->assertJsonPath('rows.0.label', 'Alpha')
            ->assertJsonPath('rows.0.value', 1)
            ->assertJsonPath('rows.0.agents', 1)
            ->assertJsonPath('rows.0.filters.team_id', $this->id['alpha'])
            ->assertJsonPath('rows.1.label', 'Bravo')
            ->assertJsonPath('none', null)
            ->assertJsonPath('others', null)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('cells_total', 2)
            ->assertJsonPath('reconciled', true)
            ->assertJsonPath('trust', 'fh_signal');
    }

    public function test_listings_by_team_show_the_top_the_no_team_row_and_the_rest(): void
    {
        $this->api('metrics/listings.added/breakdown', $this->dated(['by' => 'team']))
            ->assertOk()
            ->assertJsonPath('rows.0.label', 'Alpha')->assertJsonPath('rows.0.value', 5)->assertJsonPath('rows.0.agents', 2)
            ->assertJsonPath('rows.1.label', 'Bravo')->assertJsonPath('rows.1.value', 2)
            ->assertJsonPath('none.label', 'No team')->assertJsonPath('none.value', 2)->assertJsonPath('none.agents', 2)
            ->assertJsonPath('none.filters.team_id', 0)
            ->assertJsonPath('total', 9)->assertJsonPath('reconciled', true);

        // Top 1: the second team folds into "others", the no-team row stays its own.
        $this->api('metrics/listings.added/breakdown', $this->dated(['by' => 'team', 'top' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('others.groups', 1)->assertJsonPath('others.value', 2)->assertJsonPath('others.agents', 1)
            ->assertJsonPath('none.value', 2)
            ->assertJsonPath('total', 9)->assertJsonPath('cells_total', 9);
    }

    public function test_a_breakdown_can_be_narrowed_first(): void
    {
        $this->api('metrics/listings.added/breakdown', $this->dated(['by' => 'office_region', 'team_id' => $this->id['alpha']]))
            ->assertOk()
            ->assertJsonPath('rows.0.key', 'cebu')->assertJsonPath('rows.0.value', 5)
            ->assertJsonPath('filter_labels.team_id', 'Alpha')
            ->assertJsonPath('total', 5);
    }

    public function test_listings_by_office_project_town_and_category(): void
    {
        $byOffice = $this->api('metrics/listings.added/breakdown', $this->dated(['by' => 'office_region']))->assertOk();
        $this->assertSame([['cebu', 6], ['davao', 2]], collect($byOffice->json('rows'))->map(fn ($r) => [$r['key'], $r['value']])->all());
        $this->assertSame(['unassigned', 1], [$byOffice->json('none.key'), $byOffice->json('none.value')]);

        $this->api('metrics/listings.added/breakdown', $this->dated(['by' => 'project']))
            ->assertOk()
            ->assertJsonPath('rows.0.label', 'Mantra')->assertJsonPath('rows.0.value', 1)
            ->assertJsonPath('none.label', 'Not in a project')->assertJsonPath('none.value', 8);

        $byCategory = $this->api('metrics/listings.added/breakdown', $this->dated(['by' => 'category']))->assertOk();
        $this->assertSame([['for_sale', 'For Sale', 8], ['for_rent', 'For Rent', 1]], collect($byCategory->json('rows'))->map(fn ($r) => [$r['key'], $r['label'], $r['value']])->all());

        $byCity = $this->api('metrics/listings.added/breakdown', $this->dated(['by' => 'city']))->assertOk();
        $this->assertSame(9, $byCity->json('cells_total'));
        $this->assertSame(5, $byCity->json('rows.0.value')); // Cebu City, with the staff and flagged listings
    }

    public function test_every_row_opens_exactly_the_records_it_counts(): void
    {
        $splits = [
            ['listings.added', ['team', 'office_region', 'project', 'city', 'province', 'category', 'type', 'agent']],
            ['deals.total', ['team', 'project', 'city', 'type', 'agent']],
            ['inquiries.received', ['team', 'agent', 'project']],
            ['inquiries.hanging.total', ['team', 'agent']],
            ['leads.all', ['team', 'office_region', 'agent']],
            ['leads.waiting', ['team', 'office_region']],
            ['agents.active', ['team', 'office_region']],
            ['agents.headcount', ['team', 'office_region']],
        ];

        foreach ($splits as [$key, $dimensions]) {
            foreach ($dimensions as $by) {
                $breakdown = $this->api("metrics/{$key}/breakdown", $this->dated(['by' => $by, 'top' => 50]))->assertOk();

                $rows = $breakdown->json('rows');
                if ($breakdown->json('none') !== null) {
                    $rows[] = $breakdown->json('none');
                }
                $this->assertNotSame([], $rows, "{$key} by {$by} came back empty");

                foreach ($rows as $row) {
                    $records = $this->api("metrics/{$key}/records", $this->dated($row['filters'] + ['per_page' => 1]))->assertOk();

                    $this->assertSame($row['value'], $records->json('meta.total'), "{$key} by {$by}: '{$row['label']}' counts {$row['value']} but its records are {$records->json('meta.total')}");
                }
            }
        }
    }

    public function test_every_split_the_catalog_promises_works_and_its_cells_add_up_to_the_number(): void
    {
        $splits = 0;

        foreach ($this->api('metrics')->json('metrics') as $metric) {
            foreach ($metric['breakdowns'] as $by) {
                $splits++;
                $response = $this->api("metrics/{$metric['id']}/breakdown", $this->dated(['by' => $by, 'top' => 50]))
                    ->assertOk()->assertJsonPath('metric', $metric['id'])->assertJsonPath('by', $by);

                $shown = array_sum(array_column($response->json('rows'), 'value'))
                    + ($response->json('none.value') ?? 0) + ($response->json('others.value') ?? 0);

                // Rows + none + others is what "cells_total" says.
                $this->assertSame($response->json('cells_total'), $shown, "{$metric['id']} by {$by}");

                // The cells of "distinct clients" can overlap (one client asks two teams), so they may add up to
                // more than the number, and the response says so with reconciled = false. Nothing else may differ.
                if ($metric['id'] === 'inquiries.unique_clients') {
                    $this->assertGreaterThanOrEqual($response->json('total'), $response->json('cells_total'));

                    continue;
                }

                $this->assertSame($response->json('total'), $response->json('cells_total') + $response->json('unattributed'), "{$metric['id']} by {$by}");
                $response->assertJsonPath('reconciled', true);
            }
        }

        $this->assertGreaterThan(100, $splits);
    }

    public function test_what_cannot_be_split_is_refused(): void
    {
        foreach ([
            ['usage.total_users', 'team'],            // people, not a sum of parts
            ['listings.ppsqm_sale', 'team'],          // a median
            ['inquiries.hanging.total', 'project'],   // the lifecycle splits by agent only
            ['leads.all', 'city'],
            ['teams.total', 'team'],
        ] as [$key, $by]) {
            $this->api("metrics/{$key}/breakdown", $this->dated(['by' => $by]))->assertStatus(422)->assertJsonValidationErrors('by');
        }

        $this->api('metrics/listings.added/breakdown', $this->dated())->assertStatus(422)->assertJsonValidationErrors('by');
        $this->api('metrics/listings.added/breakdown', $this->dated(['by' => 'planet']))->assertStatus(422);
        $this->api('metrics/listings.added/breakdown', $this->dated(['by' => 'team', 'top' => 0]))->assertStatus(422);
        $this->api('metrics/listings.added/breakdown', $this->dated(['by' => 'team', 'top' => 51]))->assertStatus(422);
        $this->api('metrics/leads.all/breakdown', $this->dated(['by' => 'team', 'project_id' => 1]))->assertStatus(422)->assertJsonValidationErrors('filters');
    }

    // ── Records ─────────────────────────────────────────────────────────────

    public function test_records_are_the_admin_drill_with_no_phone_or_email(): void
    {
        $api = $this->api('metrics/deals.total/records', $this->dated())->assertOk();
        $drill = app(DrillService::class)->drill('deals.total', $this->range(), $this->now, [], 1, 25);

        $this->assertSame($drill['meta']['total'], $api->json('meta.total'));
        $this->assertSame(array_column($drill['rows'], 'id'), array_column($api->json('rows'), 'id'));
        $this->assertSame('deal', $api->json('entity'));
        $this->assertSame('range', $api->json('measured'));
        $this->assertSame('fh_signal', $api->json('trust'));
        $this->assertArrayNotHasKey('as_of', $api->json());

        // The records that name people (inquiries, leads, users) never carry a way to reach them.
        foreach (['inquiries.received', 'leads.all', 'direct.received', 'usage.active_users', 'agents.active'] as $key) {
            $body = strtolower($this->api("metrics/{$key}/records", $this->dated(['per_page' => 200]))->assertOk()->getContent());

            foreach (['"email"', '"phone"', '"mobile', '@example.com', '"contact'] as $contact) {
                $this->assertStringNotContainsString($contact, $body, "{$key}'s records show contact details ({$contact})");
            }
        }
    }

    public function test_records_page_and_sort_and_refuse_too_much(): void
    {
        $page = $this->api('metrics/listings.added/records', $this->dated(['per_page' => 4, 'page' => 2, 'sort' => 'price', 'dir' => 'asc']))->assertOk();

        $this->assertSame(9, $page->json('meta.total'));
        $this->assertSame(3, $page->json('meta.last_page'));
        $this->assertCount(4, $page->json('rows'));
        $this->assertSame(['by' => 'price', 'dir' => 'asc'], $page->json('sort'));

        $this->api('metrics/listings.added/records', $this->dated(['per_page' => 201]))->assertStatus(422);
        $this->api('metrics/listings.added/records', $this->dated(['page' => 0]))->assertStatus(422);
        $this->api('metrics/leads.all/records', $this->dated(['project_id' => 1]))->assertStatus(422)->assertJsonValidationErrors('filters');
    }

    // ── Lookups ─────────────────────────────────────────────────────────────

    public function test_a_name_becomes_the_id_a_filter_needs_and_that_id_works(): void
    {
        $teams = $this->api('lookup/teams', ['q' => 'alp'])->assertOk();
        $teams->assertJsonPath('type', 'teams')->assertJsonPath('filter', 'team_id')
            ->assertJsonPath('data.0.id', $this->id['alpha'])->assertJsonPath('data.0.label', 'Alpha')->assertJsonPath('data.0.agents', 2);
        $this->api('metrics/listings.added', $this->dated([$teams->json('filter') => $teams->json('data.0.id')]))->assertJsonPath('value', 5);

        $projects = $this->api('lookup/projects', ['q' => 'man'])->assertOk();
        $projects->assertJsonPath('filter', 'project_id')->assertJsonPath('data.0.id', $this->id['mantra'])->assertJsonPath('data.0.label', 'Mantra');
        $this->api('metrics/listings.added', $this->dated(['project_id' => $projects->json('data.0.id')]))->assertJsonPath('value', 1);

        $regions = $this->api('lookup/regions', ['q' => 'davao'])->assertOk();
        $this->assertSame('davao', $regions->json('data.0.id'));
        $this->api('metrics/listings.added', $this->dated(['office_region' => $regions->json('data.0.id')]))->assertJsonPath('value', 2);

        $cities = $this->api('lookup/cities', ['q' => 'cebu'])->assertOk();
        $this->assertNotEmpty($cities->json('data'));
        $this->api('metrics/listings.added', $this->dated(['city_id' => $cities->json('data.0.id')]))->assertOk();
    }

    public function test_lookups_list_search_and_cap(): void
    {
        // A team nobody is credited to isn't listed (Charlie's only agent counts for Alpha).
        $this->assertSame(['Alpha', 'Bravo'], array_column($this->api('lookup/teams')->json('data'), 'label'));
        $this->assertSame(['Bravo'], array_column($this->api('lookup/teams', ['q' => 'RAV'])->json('data'), 'label'));
        $this->assertSame([], $this->api('lookup/teams', ['q' => 'nothing like this'])->json('data'));

        // Places and projects are many: one letter is refused with an empty list, and the answer says so.
        $this->api('lookup/projects', ['q' => 'm'])->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.min_chars', 2);
        $this->api('lookup/cities', ['q' => 'c'])->assertOk()->assertJsonPath('data', []);

        foreach (range(1, 25) as $n) {
            $this->projectWithListing("Tower {$n}");
        }
        $this->projectWithListing('Tower Gone', ['deleted_at' => now()]);
        // A project nobody has listed in isn't offered: its id would filter to nothing.
        DB::table('projects')->insert(['name' => 'Tower Empty', 'slug' => 'tower-empty']);
        $towers = $this->api('lookup/projects', ['q' => 'tower'])->assertOk();
        $this->assertCount(20, $towers->json('data'));
        $this->assertTrue($towers->json('meta.truncated'));
        $this->assertNotContains('Tower Gone', array_column($towers->json('data'), 'label'));
        $this->assertSame([], $this->api('lookup/projects', ['q' => 'tower empty'])->json('data'));

        // Names that start with the text come before names that only contain it.
        $this->projectWithListing('The Tower');
        $this->assertSame('Tower 1', $this->api('lookup/projects', ['q' => 'tower 1'])->json('data.0.label'));

        $this->assertNotEmpty($this->api('lookup/provinces')->json('data'));
        $this->assertNotEmpty($this->api('lookup/types')->json('data'));
        $this->assertSame('type_id', $this->api('lookup/types')->json('filter'));
    }

    // ── Projects with listings ──────────────────────────────────────────────

    public function test_projects_lists_only_projects_with_listings_and_searches(): void
    {
        DB::table('projects')->insert(['name' => 'Empty Tower', 'slug' => 'empty-tower']);

        $all = $this->hit('v1/projects', ['per_page' => 200])->assertOk()
            ->assertJsonPath('filter', 'project_id')->assertJsonPath('meta.per_page', 200);
        $names = array_column($all->json('rows'), 'name');
        $this->assertContains('Mantra', $names);
        $this->assertNotContains('Empty Tower', $names);
        $this->assertSame(count($names), $all->json('meta.total'));

        // `live` is listings.live's own number for that project, and the id is the project_id filter.
        $mantra = collect($all->json('rows'))->firstWhere('name', 'Mantra');
        $this->assertSame($this->id['mantra'], $mantra['id']);
        $this->assertGreaterThanOrEqual(1, $mantra['listings']);
        $this->assertEquals(
            $this->api('metrics/listings.live', ['project_id' => $mantra['id']])->json('value'),
            $mantra['live'],
        );

        // Search is case-insensitive, by name.
        $this->assertSame(['Mantra'], array_column($this->hit('v1/projects', ['q' => 'MANT'])->json('rows'), 'name'));
        $this->assertSame([], $this->hit('v1/projects', ['q' => 'empty'])->json('rows'));
        // A search is text, never SQL.
        $this->hit('v1/projects', ['q' => "x' OR '1'='1"])->assertOk()->assertJsonPath('rows', []);
        $this->hit('v1/projects', ['q' => '%%'])->assertOk()->assertJsonPath('rows', []);

        // Paging and sorting.
        $this->hit('v1/projects', ['per_page' => 1])->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('meta.last_page', $all->json('meta.total'));
        $this->hit('v1/projects', ['sort' => 'name'])->assertOk()->assertJsonPath('sort', ['by' => 'name', 'dir' => 'asc']);
        $this->hit('v1/projects', ['per_page' => 201])->assertStatus(422);
        $this->hit('v1/projects', ['sort' => 'price'])->assertStatus(422);
    }

    public function test_a_project_opens_with_exactly_the_listings_it_counts(): void
    {
        $mantra = collect($this->hit('v1/projects', ['per_page' => 200])->json('rows'))->firstWhere('name', 'Mantra');
        $this->assertArrayHasKey('slug', $mantra);

        $one = $this->hit("v1/projects/{$mantra['id']}", ['per_page' => 200])->assertOk()
            ->assertJsonPath('project', $mantra)->assertJsonPath('live_only', false);
        $this->assertCount($mantra['listings'], $one->json('rows'));
        $this->assertSame($mantra['listings'], $one->json('meta.total'));
        $this->assertNotEmpty($one->json('rows.0.listing.id'));

        // live=1 is the project's live listings only.
        $live = $this->hit("v1/projects/{$mantra['id']}", ['live' => 1, 'per_page' => 200])->assertOk();
        $this->assertSame($mantra['live'], $live->json('meta.total'));

        // Paging.
        $this->hit("v1/projects/{$mantra['id']}", ['per_page' => 1])->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('meta.last_page', $mantra['listings']);
        $this->hit("v1/projects/{$mantra['id']}", ['per_page' => 201])->assertStatus(422);

        // A project with no listings, or none at all, is a 404 that says where to look.
        $empty = (int) DB::table('projects')->insertGetId(['name' => 'Empty Tower', 'slug' => 'empty-tower']);
        $this->hit("v1/projects/{$empty}")->assertStatus(404)->assertJsonPath('error', 'project_not_found');
        $this->hit('v1/projects/999999')->assertStatus(404);
        $this->hit('v1/projects/abc')->assertStatus(404);
    }

    public function test_an_unknown_lookup_or_an_overlong_search_is_refused(): void
    {
        $this->api('lookup/planets')->assertStatus(404)->assertJsonPath('error', 'unknown_lookup');
        $this->api('lookup/teams', ['q' => str_repeat('a', 61)])->assertStatus(422);
        // A search is text, never SQL.
        $this->api('lookup/projects', ['q' => "x' OR '1'='1"])->assertOk()->assertJsonPath('data', []);
        $this->api('lookup/projects', ['q' => '%%'])->assertOk()->assertJsonPath('data', []);
    }

    // ── Read-only ───────────────────────────────────────────────────────────

    public function test_nothing_is_written_to_the_database(): void
    {
        $before = $this->rowCounts();

        foreach ([
            'v1/health', 'v1/metrics', 'v1/lookup/teams?q=alp', 'v1/lookup/projects?q=man',
            'v1/metrics/deals.sold?compare=previous', 'v1/metrics/deals.sold/breakdown?by=team',
            'v1/metrics/leads.all/breakdown?by=office_region', 'v1/metrics/inquiries.hanging.total/records',
            'v1/metrics/agents.active/records', 'v1/metrics/teams.total/records',
        ] as $path) {
            $this->hit($path.(str_contains($path, '?') ? '&' : '?').http_build_query(self::DATES))->assertOk();
        }

        $this->assertSame($before, $this->rowCounts(), 'a call to the FH One API changed a table');
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'") as $table) {
            $counts[$table->name] = DB::table($table->name)->count();
        }

        return $counts;
    }

    public function test_the_failed_key_counter_does_not_leak_between_tests(): void
    {
        RateLimiter::clear('fhone-key-failures:127.0.0.1');
        $this->assertFalse(RateLimiter::tooManyAttempts('fhone-key-failures:127.0.0.1', 1));
    }
}
