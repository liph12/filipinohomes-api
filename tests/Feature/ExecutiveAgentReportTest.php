<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Reports\Executive\AgentReportService;
use App\Services\Reports\Executive\DrillService;
use App\Services\Reports\Executive\ReportRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/**
 * The AI Report's Agents page. Its promise is the report's: every number —
 * tile, table cell, spread bucket, spotlight — lists exactly that many records
 * when clicked, with or without an office focus. Today is Nov 10, 2026, 2 PM
 * Manila; the range is Nov 1–10 (see seedExecutiveScenario()).
 */
class ExecutiveAgentReportTest extends TestCase
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

    private function page(?string $office = null): array
    {
        return app(AgentReportService::class)->build($this->range, $this->now, $office);
    }

    private function drillTotal(string $key, array $filters = []): int
    {
        return app(DrillService::class)->drill($key, $this->range, $this->now, $filters, 1, 200)['meta']['total'];
    }

    public function test_every_number_on_the_page_equals_its_drill_with_and_without_a_focus(): void
    {
        foreach ([null, 'cebu', 'davao', 'unassigned'] as $office) {
            $page = $this->page($office);
            $where = $office ?? 'all of FH';
            $checked = 0;

            $stats = [
                ...$page['overview']['stats'], $page['overview']['roster'], $page['overview']['reached'], $page['overview']['first_reply'],
                ...$page['leads']['stats'], ...$page['leads']['sources'], ...$page['teams']['stats'], ...$page['quiet']['stats'],
            ];
            foreach ($stats as $stat) {
                $this->assertSame(
                    $stat['kind'] === 'median' ? $stat['sample'] : $stat['value'],
                    $this->drillTotal($stat['key'], $stat['filters'] ?? []),
                    "{$where} · stat {$stat['key']}",
                );
                $checked++;
            }

            $tables = [
                'leads by team' => $page['leads']['by_team'],
                'regions' => $page['regions']['table'],
                'teams' => $page['teams']['table'],
                'top agents' => $page['top'],
            ];
            foreach ($tables as $name => $table) {
                foreach ($table['rows'] as $row) {
                    foreach ($table['columns'] as $column) {
                        if (! isset($column['metric']) || $row[$column['key']] === null) {
                            continue;
                        }
                        $cell = $row[$column['key']];
                        $this->assertSame(
                            is_array($cell) ? $cell['sample'] : $cell,
                            $this->drillTotal($column['metric'], $row['filters']),
                            "{$where} · {$name} · {$column['key']} · ".json_encode($row['filters']),
                        );
                        $checked++;
                    }
                }
            }

            foreach ($page['leads']['spread'] as $bucket) {
                $this->assertSame($bucket['agents'], $this->drillTotal('agents.with_leads', $bucket['filters']), "{$where} · spread {$bucket['bucket']}");
                $checked++;
            }
            if ($property = $page['regions']['spotlight']['property']) {
                $this->assertSame($property['value'], $this->drillTotal($property['metric'], $property['filters']), "{$where} · spotlight");
            }

            $this->assertGreaterThan(40, $checked, $where);
        }
    }

    public function test_the_page_by_hand(): void
    {
        $page = $this->page();
        $stat = fn (array $stats, string $key) => collect($stats)->firstWhere('key', $key)['value'];

        $o = $page['overview']['stats'];
        $this->assertSame([4, 4, 10, 4, 2, 1], array_map(fn ($k) => $stat($o, $k), [
            'agents.active', 'agents.with_leads', 'leads.all', 'leads.answered', 'leads.waiting', 'agents.dormant_30d',
        ]));
        $this->assertSame(5, $page['overview']['roster']['value']);
        $this->assertSame(7, $page['overview']['reached']['value']);

        $this->assertSame(['1' => 2, '2-3' => 1, '4-9' => 1, '10-plus' => 0], collect($page['leads']['spread'])->pluck('agents', 'bucket')->all());

        // [agents, working, working %, leads, answered, answered %, never answered]
        $regions = collect($page['regions']['table']['rows'])->mapWithKeys(fn ($r) => [$r['key'] => [
            $r['headcount'], $r['active'], $r['active_rate'], $r['leads'], $r['answered'], $r['answered_rate'], $r['waiting'],
        ]])->all();
        $this->assertSame([2, 2, 100, 6, 3, 75, 0], $regions['cebu']);   // a1's L1 + claim thread + DM1 answered of 4
        $this->assertSame([1, 1, 100, 3, 1, 50, 1], $regions['davao']);  // L5 answered, L4 never
        $this->assertSame([1, 0, 0, 1, 0, 0, 1], $regions['bohol']);     // a6's unanswered message
        $this->assertSame(1, $page['regions']['unassigned']);           // a4

        $spot = $page['regions']['spotlight'];
        $this->assertSame(['cebu', 'Cebu'], [$spot['key'], $spot['label']]);
        $this->assertSame(['Cebu', 4, 6], [$spot['property']['province'], $spot['property']['value'], $spot['property']['of']]);

        // Both teams are under five members: listed as small, not ranked.
        $this->assertSame([], $page['teams']['most_active']);
        $this->assertSame(['Alpha', 'Bravo'], array_column(array_column($page['teams']['small'], 'team'), 'name'));
        $this->assertSame([2, 1], [$page['teams']['no_team']['members'], $page['teams']['no_team']['active']]);

        $this->assertSame([$this->id['a1'], $this->id['a3'], $this->id['a2'], $this->id['a6']], $page['top']['tops']['leads']);
        $this->assertSame([$this->id['a1']], $page['top']['tops']['answering']); // the only one with 3+ chat leads
    }

    public function test_teams_rank_by_the_share_of_members_working(): void
    {
        $team = function (string $name, int $size, int $working): void {
            $ids = [];
            for ($i = 0; $i < $size; $i++) {
                [$agent, $user] = $this->makeAgent(['region' => 'iloilo']);
                if ($i < $working) {
                    $this->login($user, '2026-11-05 09:00');
                }
                $ids[] = $agent;
            }
            $this->makeTeam($name, $ids);
        };
        $team('Big', 12, 3);   // 25%
        $team('Mid', 6, 3);    // 50%
        $team('Sleepy', 5, 0); // 0% — ranked last and listed as quiet
        $team('Tiny', 2, 2);   // 100%, but too small to rank

        $teams = $this->page()['teams'];

        $this->assertSame(['Mid', 'Big', 'Sleepy'], array_column(array_column($teams['most_active'], 'team'), 'name'));
        $this->assertSame([50, 25, 0], array_column($teams['most_active'], 'active_rate'));
        $this->assertContains('Tiny', array_column(array_column($teams['small'], 'team'), 'name'));
        $this->assertSame(['Sleepy'], array_column(array_column($teams['quiet'], 'team'), 'name'));
    }

    public function test_the_endpoint_is_admin_only_and_takes_an_office(): void
    {
        Sanctum::actingAs(User::find($this->makeUser(self::ADMIN)));
        $dates = 'date_start=2026-11-01&date_end=2026-11-10';

        $this->getJson("/api/admin/executive-report/agents?{$dates}")
            ->assertOk()
            ->assertJsonPath('office', null)
            ->assertJsonPath('meta.cached', false)
            ->assertJsonCount(6, 'overview.stats');
        $this->getJson("/api/admin/executive-report/agents?{$dates}")->assertJsonPath('meta.cached', true);
        $this->getJson("/api/admin/executive-report/agents?office=cebu&{$dates}")
            ->assertOk()
            ->assertJsonPath('office.label', 'Cebu')
            ->assertJsonPath('overview.stats.0.filters.office_region', 'cebu');
        $this->getJson("/api/admin/executive-report/agents?office=atlantis&{$dates}")->assertUnprocessable();
        $this->getJson("/api/admin/executive-report/drill?metric=agents.with_leads&leads=4-9&{$dates}")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('filter_labels.leads', '4–9 leads');

        Sanctum::actingAs(User::find($this->makeUser(self::AGENT)));
        $this->getJson("/api/admin/executive-report/agents?{$dates}")->assertForbidden();
    }
}
