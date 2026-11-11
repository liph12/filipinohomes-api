<?php

namespace Tests\Feature;

use App\Services\Reports\Executive\DrillService;
use App\Services\Reports\Executive\ExplorerService;
use App\Services\Reports\Executive\ReportRange;
use App\Services\Reports\Executive\SoldClaims\SoldClaimReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/**
 * The Market explorer (type → town → project → units) and the "said it's
 * sold, still listed" list, on the shared fixture. Today is Nov 10, 2026,
 * 2 PM Manila; the range is Nov 1–10.
 */
class ExecutiveExplorerTest extends TestCase
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

    private function explore(array $path): array
    {
        return app(ExplorerService::class)->build($this->range, $this->now, $path);
    }

    private function drillTotal(string $metric, array $filters): int
    {
        return app(DrillService::class)->drill($metric, $this->range, $this->now, $filters, 1, 200)['meta']['total'];
    }

    /** Every drillable number of an explorer level equals the drill of its filters. */
    private function assertDrillExact(array $level): void
    {
        $cells = [['totals', $level['totals']['values'], (array) $level['totals']['filters']]];
        foreach ($level['boxes'] ?? [] as $box) {
            $cells[] = ["box {$box['label']}", $box['values'], $box['filters']];
        }
        foreach ($cells as [$name, $values, $filters]) {
            foreach ($level['columns'] as $column) {
                $value = $values[$column['key']];
                $this->assertSame(is_array($value) ? $value['sample'] : $value, $this->drillTotal($column['metric'], $filters), "{$name} · {$column['key']}");
            }
        }
        foreach (['top_sellers', 'top_teams'] as $table) {
            foreach ($level[$table]['rows'] as $row) {
                foreach ($level[$table]['columns'] as $column) {
                    if (isset($column['metric'])) {
                        $this->assertSame($row[$column['key']], $this->drillTotal($column['metric'], $row['filters']), "{$table} · {$column['key']}");
                    }
                }
            }
        }
        foreach ($level['listings']['rows'] ?? [] as $row) {
            $this->assertSame($row['inquiries'], $this->drillTotal('inquiries.received', $row['filters']));
        }
    }

    /** [label => [live, added, deals, sold, rented, inquiries, ₱/sqm sale sample]] */
    private static function boxes(array $level): array
    {
        return collect($level['boxes'])->mapWithKeys(fn ($b) => [$b['label'] => [
            $b['values']['live'], $b['values']['added'], $b['values']['deals'], $b['values']['sold'],
            $b['values']['rented'], $b['values']['inquiries'], $b['values']['ppsqm_sale']['sample'],
        ]])->all();
    }

    public function test_types_then_towns_then_projects_then_units(): void
    {
        $types = $this->explore([]);
        $this->assertSame('type', $types['level']);
        $this->assertSame([
            'Condominium' => [4, 6, 2, 1, 1, 4, 3],
            'House' => [2, 2, 0, 0, 0, 1, 2],
            'Land' => [1, 1, 0, 0, 0, 1, 1],
        ], self::boxes($types));
        $this->assertSame(7, $types['totals']['values']['live']);
        $this->assertDrillExact($types);

        // "How many condos did we sell in Cebu?" — Province Cebu → Condominium.
        $towns = $this->explore(['province_id' => 1, 'type' => 1]);
        $this->assertSame('city', $towns['level']);
        $this->assertSame(1, $towns['totals']['values']['sold']);
        $this->assertSame([
            'Carmen' => [0, 0, 1, 1, 0, 0, 0],
            'Cebu City' => [4, 5, 0, 0, 0, 3, 3],
        ], self::boxes($towns));
        $this->assertSame(['province', 'type'], array_column($towns['path'], 'level'));
        $this->assertDrillExact($towns);

        $projects = $this->explore(['province_id' => 1, 'type' => 1, 'city_id' => 10]);
        $this->assertSame('project', $projects['level']);
        $this->assertSame([
            'Not in a project' => [3, 4, 0, 0, 0, 2, 2],
            'Mantra' => [1, 1, 0, 0, 0, 1, 1],
        ], self::boxes($projects));
        $this->assertDrillExact($projects);

        $units = $this->explore(['province_id' => 1, 'type' => 1, 'city_id' => 10, 'project_id' => $this->id['mantra']]);
        $this->assertSame('listings', $units['level']);
        $this->assertSame(['Cebu', 'Condominium', 'Cebu City', 'Mantra'], array_column($units['path'], 'label'));
        $this->assertCount(1, $units['listings']['rows']);
        $unit = $units['listings']['rows'][0];
        $this->assertSame($this->id['L1'], $unit['listing']['id']);
        $this->assertEquals(50.0, $unit['area']);
        $this->assertEquals(100000.0, $unit['ppsqm']);
        $this->assertSame(['lat' => 10.3157, 'lng' => 123.8854], $unit['pin']);
        $this->assertStringContainsString('query=10.3157,123.8854', $unit['map_url']);
        $this->assertSame('Alpha', $unit['agent']['team']['name']);
        $this->assertSame(3, $units['compare']['ppsqm_sale']['sample'], "Cebu City's condos, to compare Mantra with.");
        $this->assertDrillExact($units);
    }

    public function test_who_sells_here(): void
    {
        // Mantra: a1 (team Alpha) posted L1 and got its inquiry.
        $mantra = $this->explore(['type' => 'all', 'city_id' => 10, 'project_id' => $this->id['mantra']]);
        $this->assertSame([$this->id['a1']], array_column(array_column($mantra['top_sellers']['rows'], 'agent'), 'id'));
        $this->assertSame(['Alpha'], array_column(array_column($mantra['top_teams']['rows'], 'team'), 'name'));

        // All of FH: the deals decide first — a1 (L2) and a3 (L5) one each, ties by id.
        $all = $this->explore(['type' => 'all']);
        $this->assertSame([$this->id['a1'], $this->id['a3']], array_slice(array_column(array_column($all['top_sellers']['rows'], 'agent'), 'id'), 0, 2));
        $this->assertNotContains($this->id['s1'], array_column(array_column($all['top_sellers']['rows'], 'agent'), 'id'), 'The admin\'s agent row is not ranked.');
        $this->assertDrillExact($all);
    }

    public function test_any_id_of_a_duplicate_town_opens_the_whole_town(): void
    {
        $twin = $this->explore(['type' => 'all', 'city_id' => 13]);

        $this->assertSame(12, $twin['path'][1]['id']);
        $this->assertSame(['city_id' => 12], (array) $twin['totals']['filters']);
        $this->assertSame(1, $twin['totals']['values']['deals'], 'L2 sits under the other Carmen row.');
    }

    public function test_said_sold_still_listed(): void
    {
        $page = app(SoldClaimReport::class)->page($this->range, $this->now, 'strong', []);

        $this->assertSame(['strong' => 1, 'medium' => 0, 'inquiries_after' => 1], $page['summary']);
        $this->assertSame(1, $page['meta']['total']);
        $row = $page['rows'][0];
        $this->assertSame($this->id['L9'], $row['listing']['id']);
        $this->assertSame(['sold na', 'Sorry po, sold na po ang unit.', 'agent'], [$row['claim']['phrase'], $row['claim']['snippet'], $row['claim']['speaker']['role']]);
        $this->assertSame('2026-11-09T13:30:00+08:00', $row['claim']['said_at']);
        $this->assertSame([$this->id['claim_chat']], array_column($row['conversations'], 'chat_id'));
        $this->assertSame('listing', $row['conversations'][0]['type']);
        $this->assertSame(1, $row['inquiries_after']);
        $this->assertSame($row['inquiries_after'], $this->drillTotal('claims.inquiries_after', $row['filters']));

        $this->assertSame(1, app(SoldClaimReport::class)->page($this->range, $this->now, 'strong', ['team_id' => $this->id['alpha']])['meta']['total']);
        $this->assertSame(0, app(SoldClaimReport::class)->page($this->range, $this->now, 'strong', ['team_id' => $this->id['bravo']])['meta']['total']);
        $this->assertSame([], app(SoldClaimReport::class)->page($this->range, $this->now, 'medium', [])['rows']);

        // Marked sold → off the list.
        DB::table('properties')->where('id', DB::table('listings')->where('id', $this->id['L9'])->value('property_id'))->update(['status' => 'sold']);
        $this->assertSame(0, app(SoldClaimReport::class)->page($this->range, $this->now, 'strong', [])['meta']['total']);
    }
}
