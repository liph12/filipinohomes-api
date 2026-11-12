<?php

namespace Tests\Feature;

use App\Services\Reports\Executive\Analysis\ExecutiveAnalysisService;
use App\Services\Reports\Executive\DrillService;
use App\Services\Reports\Executive\ReportRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/**
 * The Agents page's AI summary and the nightly run that writes it ahead.
 * OpenAI is faked throughout. Today is Nov 10, 2026, 2 PM Manila.
 */
class ExecutiveAgentAnalysisTest extends TestCase
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
        config(['services.openai.key' => null, 'services.openai.chat_model' => 'gpt-test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private static function reply(array $arguments): CreateResponse
    {
        return CreateResponse::fake(['choices' => [[
            'message' => ['content' => null, 'function_call' => ['name' => 'write_executive_analysis', 'arguments' => json_encode($arguments)]],
        ]]]);
    }

    private static function agentsAnswer(): array
    {
        return [
            'headline' => '{leads_answered_rate} of chat leads were answered on FH.',
            'bullets' => [
                ['text' => '{leads_waiting} leads were never answered on FH.', 'fact_ids' => ['leads_waiting'], 'kind' => 'risk'],
                ['text' => '{spot_agents} agents work from {spot_agents.name} offices.', 'fact_ids' => ['spot_agents'], 'kind' => 'watch'],
            ],
            'actions' => [
                ['title' => 'Call back the clients nobody answered', 'text' => 'Team leaders should answer the {leads_waiting} waiting leads today.', 'fact_ids' => ['leads_waiting']],
                ['title' => 'Give 5 agents a team', 'text' => 'Place the agents in no team.', 'fact_ids' => ['no_team_agents']], // a typed number: dropped
                ['title' => 'Check in with the quiet teams', 'text' => 'Ask the office heads to call their leaders this week.', 'fact_ids' => ['teams_inactive']],
            ],
        ];
    }

    private static function overviewAnswer(): array
    {
        return [
            'headline' => '{deals_total} deals closed as {inquiries_received} inquiries came in.',
            'bullets' => [
                ['text' => '{agents_dormant_30d} agent has not opened FH in a month.', 'fact_ids' => ['agents_dormant_30d'], 'kind' => 'risk'],
                ['text' => 'Ask {closers_1.name} to coach the team on closing.', 'fact_ids' => ['closers_1'], 'kind' => 'action'],
            ],
        ];
    }

    private function service(?ClientFake $fake = null): ExecutiveAnalysisService
    {
        $service = app(ExecutiveAnalysisService::class);

        return $fake ? $service->withClient($fake) : $service;
    }

    public function test_every_agents_fact_opens_exactly_its_number(): void
    {
        $facts = $this->service()->factsFor($this->range, $this->now, 'agents')['facts'];
        $this->assertGreaterThan(50, count($facts));

        foreach ($facts as $id => $fact) {
            if ($fact['metric'] === null) {
                $this->assertSame('percent', $fact['unit'], "{$id} is a worked-out share");

                continue;
            }
            $total = app(DrillService::class)->drill($fact['metric'], $this->range, $this->now, $fact['filters'], 1, 200)['meta']['total'];
            $this->assertSame($fact['unit'] === 'minutes' ? $fact['sample'] : $fact['value'], $total, $id);
        }

        $this->assertSame(57, $facts['leads_answered_rate']['value']); // 4 of 7 chat leads
        $this->assertSame(['Cebu', 6], [$facts['spot_leads']['name'], $facts['spot_leads']['value']]);
        $this->assertSame(1, $facts['spread_4_9']['value']);
    }

    public function test_the_agents_summary_is_its_own_with_actions_that_open_their_list(): void
    {
        $fake = new ClientFake([self::reply(self::agentsAnswer())]);
        $result = $this->service($fake)->generate($this->range, $this->now, $this->id['u1'], scope: 'agents');

        $this->assertSame('ok', $result['status']);
        $a = $result['analysis'];
        $this->assertSame('agents', $a['scope']);
        $this->assertSame(['type' => 'value', 'text' => '57%', 'fact' => 'leads_answered_rate'], $a['headline'][0]);
        $this->assertSame(['Call back the clients nobody answered', 'Check in with the quiet teams'], array_column($a['actions'], 'title'));
        $this->assertSame('leads.waiting', $a['actions'][0]['open']['metric']);
        $this->assertSame('teams.inactive', $a['actions'][1]['open']['metric']);
        $this->assertSame('admin', $a['written_by']);

        $fake->assertSent(Chat::class, fn (string $method, array $p) => str_contains($p['messages'][0]['content'], 'Agents page')
            && $p['functions'][0]['parameters']['required'] === ['headline', 'bullets', 'actions']
            && str_contains($p['messages'][1]['content'], '{leads_answered_rate} | Share of chat leads answered | value 57% |'));

        // The hub's analysis is separate: none yet.
        $this->assertSame('none', $this->service()->stored($this->range, $this->now)['status']);
        $this->assertSame('ok', $this->service()->stored($this->range, $this->now, 'agents')['status']);
    }

    public function test_numbers_that_move_by_the_minute_dont_make_it_stale_but_new_leads_do(): void
    {
        $service = $this->service(new ClientFake([self::reply(self::agentsAnswer())]));
        $waitingThen = $service->factsFor($this->range, $this->now, 'agents')['facts']['leads_waiting']['value'];
        $service->generate($this->range, $this->now, null, scope: 'agents');

        // A day later the Nov 10 inquiry has waited 24 hours: "never answered" moved, the range didn't.
        Cache::flush();
        $later = $this->now->copy()->addHours(25);
        $this->assertNotSame($waitingThen, $service->factsFor($this->range, $later, 'agents')['facts']['leads_waiting']['value']);
        $this->assertFalse($service->stored($this->range, $later, 'agents')['stale']);

        $this->makeBrief($this->id['a2'], '2026-11-09 10:00');
        Cache::flush();
        $this->assertTrue($service->stored($this->range, $later, 'agents')['stale']);
    }

    public function test_the_nightly_run_writes_each_page_once_a_day(): void
    {
        config(['services.openai.key' => 'sk-test']);
        $fake = new ClientFake([
            self::reply(self::overviewAnswer()), self::reply(self::overviewAnswer()),
            self::reply(self::agentsAnswer()), self::reply(self::agentsAnswer()),
        ]);
        $this->app->instance(ExecutiveAnalysisService::class, app(ExecutiveAnalysisService::class)->withClient($fake));

        $this->artisan('reports:write-analyses')->expectsOutputToContain('agents   This month   2026-11-01 → 2026-11-10: written')->assertSuccessful();
        $fake->assertSent(Chat::class, 4);
        $this->assertSame(
            ['agents 2026-10-12', 'agents 2026-11-01', 'overview 2026-10-12', 'overview 2026-11-01'],
            DB::table('executive_report_analyses')->orderBy('scope')->orderBy('date_start')->get()->map(fn ($r) => "{$r->scope} {$r->date_start}")->all(),
        );
        $this->assertSame('nightly', $this->service()->stored(ReportRange::resolve('2026-10-12', '2026-11-10'), $this->now, 'agents')['analysis']['written_by']);

        // Again the same day: nothing new, nothing billed.
        $this->artisan('reports:write-analyses')->expectsOutputToContain('already')->assertSuccessful();
        $fake->assertSent(Chat::class, 4);

        // Another host holding the lock: skipped.
        DB::table('executive_report_analyses')->delete();
        $lock = Cache::store('database')->lock('exec-report:nightly:overview:2026-10-12:2026-11-10:2026-11-10', 900);
        $lock->get();
        $this->assertSame('locked', $this->service($fake)->writeNightly(ReportRange::resolve('2026-10-12', '2026-11-10'), $this->now, 'overview'));
        $lock->release();
    }

    public function test_without_a_key_the_nightly_run_writes_nothing_and_succeeds(): void
    {
        $this->artisan('reports:write-analyses')->expectsOutputToContain('nothing written')->assertSuccessful();
        $this->assertSame(0, DB::table('executive_report_analyses')->count());
    }
}
