<?php

namespace Tests\Feature;

use App\Services\Reports\Executive\DrillService;
use App\Services\Reports\Executive\ExecutiveReportService;
use App\Services\Reports\Executive\Geography;
use App\Services\Reports\Executive\MetricEngine;
use App\Services\Reports\Executive\MetricRegistry;
use App\Services\Reports\Executive\ReportRange;
use App\Services\Reports\Executive\Sections\TrendSection;
use App\Services\Reports\Executive\TeamDirectory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/**
 * The Executive Report's central promise: click any number, get exactly the
 * records behind it. For EVERY metric key, the drill's total must equal the
 * metric's value — and a breakdown cell must equal the drill of its filter,
 * including across duplicate city / province rows.
 *
 * Also pins the definitions with hand-computed values on the shared fixture
 * (CreatesExecutiveReportTables::seedExecutiveScenario()), built to hit each
 * edge: duplicate towns and provinces, an agent in two teams, staff-owned,
 * flagged and private listings, every hanging bucket, and a sold claim.
 *
 * Today is frozen at Nov 10, 2026, 2 PM Manila; the range is Nov 1–10.
 */
class ExecutiveMetricParityTest extends TestCase
{
    use CreatesExecutiveReportTables;

    private ReportRange $range;

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

        $this->range = ReportRange::resolve('2026-11-01', '2026-11-10');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function engine(): MetricEngine
    {
        return app(MetricEngine::class);
    }

    private function drillTotal(string $key, array $filters = [], ?ReportRange $range = null): int
    {
        return app(DrillService::class)->drill($key, $range ?? $this->range, $this->now, $filters, 1, 200)['meta']['total'];
    }

    private function summary(): array
    {
        return app(ExecutiveReportService::class)->build($this->range, $this->now);
    }

    // ── The promise ─────────────────────────────────────────────────────────

    public function test_every_metric_drills_to_exactly_its_own_count(): void
    {
        foreach (MetricRegistry::keys() as $key) {
            $def = MetricRegistry::get($key);
            $expected = $def['kind'] === 'median'
                ? $this->engine()->median($key, $this->range, $this->now)['sample']
                : $this->engine()->value($key, $this->range, $this->now);

            $this->assertSame($expected, $this->drillTotal($key), "Drill total for {$key}");
        }
    }

    public function test_a_breakdown_cell_equals_the_drill_of_its_filter_across_duplicate_rows(): void
    {
        $geo = app(Geography::class);

        // Group by raw city, fold to towns — the twin Carmen rows become one.
        $folded = [];
        foreach ($this->engine()->group('listings.added', 'city', $this->range, $this->now) as $raw => $n) {
            $town = $geo->cityGroup((int) $raw);
            $folded[$town] = ($folded[$town] ?? 0) + $n;
        }

        $this->assertSame([10 => 5, 12 => 1, 20 => 2, 30 => 1], $folded);
        foreach ($folded as $town => $count) {
            $this->assertSame($count, $this->drillTotal('listings.added', ['city_id' => $town]), "city {$town}");
        }

        // Either Carmen id drills to the whole town.
        $this->assertSame(1, $this->drillTotal('listings.added', ['city_id' => 13]));
        $this->assertSame(1, $this->drillTotal('listings.added', ['city_id' => 12]));
    }

    public function test_southern_samar_counts_under_samar_and_eastern_visayas(): void
    {
        $this->assertSame(1, $this->drillTotal('listings.added', ['province_id' => 83]));
        $this->assertSame(1, $this->drillTotal('listings.added', ['province_id' => 82]));
        $this->assertSame(1, $this->drillTotal('listings.added', ['psa_region' => 'eastern-visayas']));
        // Cebu's six plus Samar's one; Davao's two are Mindanao.
        $this->assertSame(7, $this->drillTotal('listings.added', ['island' => 'visayas']));
        $this->assertSame(2, $this->drillTotal('listings.added', ['island' => 'mindanao']));
    }

    // ── The definitions, by hand ────────────────────────────────────────────

    public function test_listing_and_deal_counts(): void
    {
        $value = fn (string $key, array $f = []) => $this->engine()->value($key, $this->range, $this->now, $f);

        $this->assertSame(9, $value('listings.added'));          // L2 was added in October
        $this->assertSame(1, $value('listings.added_by_staff')); // L7
        $this->assertSame(2, $value('deals.total'));             // L2 sold, L5 rented
        $this->assertSame(1, $value('deals.sold'));
        $this->assertSame(1, $value('deals.rented'));
        $this->assertSame(0, $value('deals.leased'));
        $this->assertSame(1, $value('deals.total', ['project_id' => 0, 'city_id' => 12]));
    }

    public function test_price_per_sqm_skips_flagged_private_and_small_areas(): void
    {
        $sale = $this->engine()->median('listings.ppsqm_sale', $this->range, $this->now);
        $rent = $this->engine()->median('listings.ppsqm_rent', $this->range, $this->now);

        // L1, L3, L6, L7, L9 at 100k and the land L4 at 10k (lot area); L8 flagged, L10 private.
        $this->assertSame(6, $sale['sample']);
        $this->assertEquals(100000.0, $sale['value']);
        $this->assertSame(1, $rent['sample']);
        $this->assertEquals(500.0, $rent['value']);
    }

    public function test_inquiry_lifecycle_buckets(): void
    {
        $value = fn (string $key) => $this->engine()->value($key, $this->range, $this->now);

        $this->assertSame(6, $value('inquiries.received'));
        $this->assertSame(3, $value('inquiries.unique_clients'));
        $this->assertSame(1, $value('inquiries.hanging.pending_review'));
        $this->assertSame(1, $value('inquiries.hanging.no_reply'));
        $this->assertSame(1, $value('inquiries.hanging.client_waiting'));
        $this->assertSame(3, $value('inquiries.hanging.total'));
        $this->assertSame(1, $value('inquiries.stalled'));
        $this->assertSame(1, $value('inquiries.pending_now'));
        // The sold-claim thread is neither hanging nor stalled.
        $this->assertSame(5, $value('inquiries.reached_agent'));
        $this->assertSame(3, $value('inquiries.answered'));

        $reply = $this->engine()->median('inquiries.first_reply_minutes', $this->range, $this->now);
        $this->assertSame(3, $reply['sample']);
        $this->assertEquals(50.0, $reply['value']); // 60, 50 and 30 minutes → 50
    }

    public function test_sold_claims_and_the_leads_after_them(): void
    {
        $value = fn (string $key) => $this->engine()->value($key, $this->range, $this->now);

        $this->assertSame(1, $value('claims.sold_unmarked'));
        $this->assertSame(1, $value('claims.inquiries_after'));

        // Marking the listing sold takes it off the list immediately.
        DB::table('properties')->where('id', DB::table('listings')->where('id', $this->id['L9'])->value('property_id'))
            ->update(['status' => 'sold']);
        $this->assertSame(0, $value('claims.sold_unmarked'));
    }

    public function test_people_counts(): void
    {
        $value = fn (string $key) => $this->engine()->value($key, $this->range, $this->now);

        $this->assertSame(5, $value('agents.headcount'));  // a1 a2 a3 a4 a6 — not the inactive a5, not the admin's agent row
        $this->assertSame(4, $value('agents.posting'));    // a1 a2 a3 a4 — the staff listing doesn't count
        $this->assertSame(2, $value('agents.logged_in'));  // a1 a3
        $this->assertSame(4, $value('agents.active'));     // a1 a2 a3 a4; a6 did nothing
        $this->assertSame(1, $value('agents.idle'));       // a6
        $this->assertSame(1, $value('agents.dormant_30d')); // a4, last seen Sep 1
        $this->assertSame(2, $value('usage.new_clients'));      // c1 (login) and c2 (token)
        $this->assertSame(1, $value('usage.unfinished_signups')); // c4
        $this->assertSame(1, $value('usage.returning_clients'));  // c3
    }

    public function test_anyone_who_used_fh_is_real_and_a_userless_agent_row_breaks_nothing(): void
    {
        $value = fn (string $key) => $this->engine()->value($key, $this->range, $this->now);
        [$total, $idle] = [$value('usage.total_users'), $value('agents.idle')];

        // From before sign-in logs and presence, logged out (no token) — but posted this month.
        [$quiet] = $this->makeAgent();
        $this->makeListing($quiet, ['added' => '2026-11-04 10:00']);
        // An agent row with no user at all, owning a listing in the range.
        $orphan = DB::table('agents')->insertGetId(['user_id' => null, 'first_name' => 'No', 'last_name' => 'User', 'status' => 'active']);
        $this->makeListing($orphan, ['added' => '2026-11-04 11:00']);

        $this->assertSame($total + 1, $value('usage.total_users'));
        $this->assertGreaterThanOrEqual($value('usage.active_users'), $value('usage.total_users'), 'Total always holds Active.');
        $this->assertSame($idle, $value('agents.idle'), 'A NULL user id inside NOT IN would empty the idle list.');
        $this->assertSame(3, $value('usage.new_clients') + $value('usage.unfinished_signups'), 'Every client created in the range is one or the other.');
    }

    public function test_leads_from_every_source(): void
    {
        $value = fn (string $key, array $f = []) => $this->engine()->value($key, $this->range, $this->now, $f);

        // Two clients messaged an agent (a fellow agent's message is no lead); a1 answered, nobody answered a6.
        $this->assertSame(2, $value('direct.received'));
        $this->assertSame(1, $value('direct.answered'));
        $this->assertSame(1, $value('direct.waiting'));
        $this->assertSame(1, $value('briefs.received'));          // the admin's own link has no agent
        $this->assertSame(1, $value('open_house.registrations')); // an agent registering is no lead
        $this->assertSame(10, $value('leads.all'));               // 6 inquiries + 2 messages + a brief + a registration
        $this->assertSame(7, $value('leads.reached'));            // 5 inquiries reached their agent + 2 messages
        $this->assertSame(4, $value('leads.answered'));           // 3 inquiries + 1 message
        $this->assertSame(2, $value('leads.waiting'));            // L4's inquiry and the message to a6
        $this->assertSame(1, $value('leads.to_idle_agents'));     // a6 did nothing in the range

        // a1: 3 inquiries + a message + a brief; a3: 2 inquiries + a registration; a2 and a6: one each.
        $this->assertSame(4, $value('agents.with_leads'));
        $this->assertSame([2, 1, 1, 0], array_map(fn ($b) => $value('agents.with_leads', ['leads' => $b]), ['1', '2-3', '4-9', '10-plus']));
        $this->assertSame(2, $value('agents.unanswered'));        // a3 (L4) and a6

        $this->assertSame(6, $value('leads.all', ['office_region' => 'cebu']));
        $this->assertSame(1, $value('leads.all', ['office_region' => 'bohol']));
        $this->assertSame(6, $value('leads.all', ['team_id' => $this->id['alpha']]));
        $this->assertSame(1, $value('leads.all', ['team_id' => 0]));
        $this->assertSame(5, $value('leads.all', ['agent_id' => $this->id['a1']]));

        $this->assertSame(1, $value('teams.no_leader'));          // Bravo
        $this->assertSame(0, $value('agents.never_used'));
        $this->assertSame(1, $value('agents.dormant_used'));      // a4 posted and signed in before
    }

    public function test_teams_credit_each_agent_once(): void
    {
        $teams = app(TeamDirectory::class);
        $this->assertSame($this->id['alpha'], $teams->teamOf($this->id['a2']));

        $value = fn (string $key) => $this->engine()->value($key, $this->range, $this->now);
        $this->assertSame(2, $value('teams.total'));   // Alpha, Bravo — Charlie has nobody credited
        $this->assertSame(2, $value('teams.active'));
        $this->assertSame(0, $value('teams.inactive'));
        // Alpha = a1 (L1, L9) + a2 (L3, L8, L10); a2's Charlie membership adds nothing.
        $this->assertSame(5, $this->drillTotal('listings.added', ['team_id' => $this->id['alpha']]));
        $this->assertSame(0, $this->drillTotal('listings.added', ['team_id' => $this->id['charlie']]));
    }

    public function test_a_filter_the_metric_does_not_understand_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(DrillService::class)->drill('agents.active', $this->range, $this->now, ['city_id' => 10]);
    }

    // ── The summary ─────────────────────────────────────────────────────────

    public function test_every_number_in_the_summary_equals_its_drill(): void
    {
        $report = $this->summary();
        $checked = 0;

        $stats = $report['kpis'];
        foreach ($report['sections'] as $section) {
            array_push($stats, ...($section['stats'] ?? []), ...($section['hanging'] ?? []), ...($section['claims'] ?? []));
        }
        foreach ($stats as $stat) {
            $this->assertSame($stat['kind'] === 'median' ? $stat['sample'] : $stat['value'], $this->drillTotal($stat['key']), "Stat {$stat['key']}");
            if (array_key_exists('previous', $stat) && $stat['kind'] === 'count') {
                $this->assertSame($stat['previous'], $this->drillTotal($stat['key'], [], $this->range->previous()), "Previous {$stat['key']}");
            }
            $checked++;
        }

        $s = $report['sections'];
        $tables = [
            'usage by role' => $s['usage']['by_role'],
            'agent leaderboard' => $s['agents']['leaderboard'],
            'agents by office' => $s['agents']['by_office'],
            'teams' => $s['teams']['table'],
            'most-inquired listings' => $s['inquiries']['top_listings'],
            'deals by type' => $s['deals']['by']['type'],
            'deals by city' => $s['deals']['by']['city'],
            'deals by project' => $s['deals']['by']['project'],
            'deals by team' => $s['deals']['by']['team'],
            'towns' => $s['places']['cities'],
            '₱/sqm by type' => $s['places']['ppsqm_by_type'],
        ];
        foreach ($tables as $name => $table) {
            $this->assertNotEmpty($table['rows'], "{$name} has rows in this fixture");
            foreach ($table['rows'] as $row) {
                foreach ($table['columns'] as $column) {
                    if (! isset($column['metric'])) {
                        continue;
                    }
                    $cell = $row[$column['key']];
                    $this->assertSame(
                        is_array($cell) ? $cell['sample'] : $cell,
                        $this->drillTotal($column['metric'], $row['filters']),
                        "{$name} · {$column['key']} · ".json_encode($row['filters']),
                    );
                    $checked++;
                }
            }
        }

        $i = $s['inquiries'];
        foreach ([...$i['by_region']['psa'], ...$i['by_region']['office'], ...$i['by_type']['rows'], ...$i['by_category']['rows']] as $row) {
            $this->assertSame($row['value'], $this->drillTotal('inquiries.received', $row['filters']), json_encode($row['filters']));
            $checked++;
        }

        $this->assertGreaterThan(150, $checked);
    }

    public function test_kpi_deltas_compare_with_the_same_days_last_month(): void
    {
        $report = $this->summary();
        $kpis = collect($report['kpis'])->keyBy('key');

        $this->assertSame(['start' => '2026-10-01', 'end' => '2026-10-10'], $report['range']['previous']);
        $this->assertSame([9, 1, 800.0], [$kpis['listings.added']['value'], $kpis['listings.added']['previous'], $kpis['listings.added']['change_pct']]); // L2, added Oct 1
        $this->assertSame([4, 1], [$kpis['agents.active']['value'], $kpis['agents.active']['previous']]);                                        // a1 posted L2 on Oct 1
        $this->assertSame(0, $kpis['deals.total']['previous']);
        $this->assertNull($kpis['deals.total']['change_pct'], 'No percentage against zero.');
        $this->assertArrayNotHasKey('previous', $kpis['agents.dormant_30d'], 'A figure measured now has no previous period.');
        $this->assertFalse($kpis['inquiries.received']['partial']);
    }

    public function test_the_sections_by_hand(): void
    {
        $report = $this->summary();
        $s = $report['sections'];

        $this->assertSame(['central-visayas' => 4, 'davao' => 2], collect($s['inquiries']['by_region']['psa'])->pluck('value', 'key')->all());

        $this->assertSame(
            ['Carmen, Cebu' => [1, 1, 0], 'Davao City, Davao del Sur' => [1, 0, 1]],
            collect($s['deals']['by']['city']['rows'])->mapWithKeys(fn ($r) => [$r['label'] => [$r['total'], $r['sold'], $r['rented']]])->all(),
        );

        // [agents, active, listings, deals, inquiries, hanging]; the admin's listing L7 is under "No team".
        $teams = collect($s['teams']['table']['rows'])->mapWithKeys(fn ($r) => [$r['team']['name'] => [
            $r['members'], $r['active'], $r['listings'], $r['deals'], $r['inquiries'], $r['hanging'],
        ]])->all();
        $this->assertSame([
            'Alpha' => [2, 2, 5, 1, 4, 1],
            'Bravo' => [1, 1, 2, 1, 2, 2],
            'No team' => [2, 1, 2, 0, 0, 0],
        ], $teams);

        // Top posters: agents only (the admin's row is left out), ties by id.
        $this->assertSame([$this->id['a2'], $this->id['a1'], $this->id['a3'], $this->id['a4']], $s['agents']['leaderboard']['tops']['posters']);

        // [town, listings, inquiries, deals, ₱/sqm sample]; the twin Carmens and both Catbalogans fold.
        $this->assertSame([
            ['Cebu City', 5, 3, 0, 3],
            ['Davao City', 2, 2, 1, 1],
            ['Carmen', 1, 1, 1, 1],
            ['Catbalogan City', 1, 0, 0, 1],
        ], array_map(fn ($r) => [$r['city'], $r['listings'], $r['inquiries'], $r['deals'], $r['ppsqm_sale']['sample']], $s['places']['cities']['rows']));

        $trend = $report['trend'];
        $this->assertSame('day', $trend['bucket']);
        $this->assertCount(10, $trend['points']);
        foreach (TrendSection::SERIES as $series => $key) {
            $this->assertSame($this->engine()->value($key, $this->range, $this->now), array_sum(array_column($trend['points'], $series)), $series);
        }
        $this->assertSame(2, collect($trend['points'])->firstWhere('start', '2026-11-02')['listings']); // L4 and L10
    }

    public function test_the_summary_is_cached_per_range_and_refresh_rebuilds_only_a_stale_one(): void
    {
        $service = app(ExecutiveReportService::class);

        $first = $service->summary($this->range, $this->now);
        $this->assertFalse($first['meta']['cached']);
        $this->assertTrue($service->summary($this->range, $this->now)['meta']['cached']);
        $this->assertTrue($service->summary($this->range, $this->now, refresh: true)['meta']['cached'], 'Not rebuilt within a minute.');
        $this->assertFalse($service->summary($this->range, $this->now->copy()->addMinutes(2), refresh: true)['meta']['cached']);
        $this->assertArrayNotHasKey('built_at', $first['meta']);
    }
}
