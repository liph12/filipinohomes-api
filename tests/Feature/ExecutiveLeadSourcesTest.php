<?php

namespace Tests\Feature;

use App\Services\Reports\Executive\DrillService;
use App\Services\Reports\Executive\MetricEngine;
use App\Services\Reports\Executive\ReportRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/**
 * The edges of the Agents page's own numbers: one person with two agent rows,
 * agents who stopped, agents who never used FH, and what a lead's drill row
 * shows. Today is Nov 10, 2026, 2 PM Manila; the range is Nov 1–10 and the
 * period before it Oct 1–10.
 */
class ExecutiveLeadSourcesTest extends TestCase
{
    use CreatesExecutiveReportTables;

    private ReportRange $range;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->now = Carbon::parse('2026-11-10 14:00', 'Asia/Manila');
        Carbon::setTestNow($this->now);
        $this->createExecutiveReportTables();
        $this->range = ReportRange::resolve('2026-11-01', '2026-11-10');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function value(string $key, array $filters = []): int
    {
        return app(MetricEngine::class)->value($key, $this->range, $this->now, $filters);
    }

    private function drill(string $key, array $filters = []): array
    {
        return app(DrillService::class)->drill($key, $this->range, $this->now, $filters, 1, 200);
    }

    public function test_a_message_to_a_person_with_two_agent_rows_counts_once_for_the_first_row(): void
    {
        [$first, $user] = $this->makeAgent(['region' => 'cebu']);
        $second = DB::table('agents')->insertGetId(['user_id' => $user, 'first_name' => 'Same', 'last_name' => 'Person', 'status' => 'active', 'region' => 'davao']);
        $client = $this->makeUser(self::CLIENT);
        $this->makeDirectMessage($user, $client, '2026-11-04 10:00', [[$client, '2026-11-04 10:00']]);

        $this->assertSame(1, $this->value('direct.received'));
        $this->assertSame(1, $this->value('direct.received', ['agent_id' => $first]));
        $this->assertSame(0, $this->value('direct.received', ['agent_id' => $second]));
        $this->assertSame(1, $this->value('direct.received', ['office_region' => 'cebu']), 'The first row\'s office, not the second\'s.');
        $this->assertSame(1, $this->drill('direct.received')['meta']['total']);
    }

    public function test_agents_who_stopped_and_agents_who_never_used_fh(): void
    {
        [$stopped, $stoppedUser] = $this->makeAgent([], ['last_online_at' => $this->utc('2026-10-08 10:00')]);
        $this->login($stoppedUser, '2026-10-05 09:00');                 // active the period before, nothing since
        [$steady, $steadyUser] = $this->makeAgent([], ['last_online_at' => $this->utc('2026-11-09 10:00')]);
        $this->login($steadyUser, '2026-10-05 09:00');
        $this->login($steadyUser, '2026-11-05 09:00');                  // active in both
        [$never] = $this->makeAgent(['created_at' => $this->utc('2026-05-01 09:00')]); // synced, never opened FH
        [$used] = $this->makeAgent([], ['last_online_at' => $this->utc('2026-08-01 10:00')]); // used it, then left

        $this->assertSame([$stopped], $this->ids('agents.gone_quiet'));
        $this->assertSame([$never], $this->ids('agents.never_used'));
        $this->assertEqualsCanonicalizing([$stopped, $used], $this->ids('agents.dormant_used'), 'Last seen Oct 8 and Aug 1: both used FH before.');
        $this->assertSame($this->value('agents.dormant_30d'), $this->value('agents.never_used') + $this->value('agents.dormant_used'));
        $this->assertNotContains($steady, $this->ids('agents.dormant_30d'));
    }

    public function test_a_lead_row_names_its_source_and_never_shows_contact_details(): void
    {
        [$agent, $agentUser] = $this->makeAgent(['region' => 'cebu']);
        $client = $this->makeUser(self::CLIENT, ['name' => 'Maria Client']);
        $chat = $this->makeDirectMessage($agentUser, $client, '2026-11-04 10:00', [[$client, '2026-11-04 10:00']], 'agent_page');
        $this->makeBrief($agent, '2026-11-05 10:00', 'seller', 'Pedro Seller');

        $drill = $this->drill('leads.all');
        $rows = collect($drill['rows'])->keyBy('source');

        $this->assertSame(['chat_id' => $chat, 'type' => 'agent', 'listing_name' => null, 'client_name' => 'Maria Client'], $rows['Messaged the agent']['chat']);
        $this->assertSame('From their My Page', $rows['Messaged the agent']['about']);
        $this->assertNull($rows['Buyer & Seller Link']['chat']);
        $this->assertSame(['id' => null, 'name' => 'Pedro Seller', 'avatar' => null], $rows['Buyer & Seller Link']['client']);
        $this->assertSame('Wants to sell', $rows['Buyer & Seller Link']['about']);
        $this->assertStringNotContainsString('phone', json_encode($drill));
        $this->assertStringNotContainsString('email', json_encode($drill));

        // The unanswered message is waiting, longest first, with its hours.
        $waiting = $this->drill('leads.waiting');
        $this->assertSame(1, $waiting['meta']['total']);
        $this->assertSame('waiting', $waiting['sort']['by']);
        $this->assertEqualsWithDelta(148.0, $waiting['rows'][0]['waiting'], 0.1); // Nov 4 10 AM → Nov 10 2 PM
    }

    public function test_the_lead_bucket_filter_belongs_to_one_metric_only(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(DrillService::class)->assertFilters('agents.active', ['leads' => '1']);
    }

    private function ids(string $key): array
    {
        return array_map(fn ($row) => $row['agent']['id'], $this->drill($key)['rows']);
    }
}
