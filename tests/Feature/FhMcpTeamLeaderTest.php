<?php

namespace Tests\Feature;

use App\Mcp\Policies\TeamLeaderPolicy;
use App\Mcp\Support\McpKeys;
use App\Mcp\Support\McpRole;
use App\Models\McpCall;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/**
 * A team leader on the MCP server: an agent who leads an active team is shown
 * their own numbers (the agent tools) and their team's (the team tools), held to
 * the teams they lead. Not another team, not the company, not a member who is
 * not theirs, and not the numbers that would answer for the whole company
 * while looking like a team's.
 *
 * The shared fixture (seedExecutiveScenario()): team Alpha is a1 (leader) and
 * a2, Bravo is a3 alone, and Charlie is a2 alone, with a2 credited to Alpha
 * (the lowest team id) in the reports. Listings: a1 three, a2 three, a3 two, a4
 * one, the admin's own agent row one: ten in all.
 */
class FhMcpTeamLeaderTest extends TestCase
{
    use CreatesExecutiveReportTables;

    private const ENDPOINT = '/mcp/filipinohomes';

    private const ADMIN_TOOLS = ['fh_ai_visibility', 'fh_clients', 'fh_lookup', 'fh_metric', 'fh_metric_breakdown', 'fh_metric_records', 'fh_metrics_catalog', 'fh_traffic'];

    private const AGENT_TOOLS = ['my_lookup', 'my_metric', 'my_metric_breakdown', 'my_metric_records', 'my_metrics_catalog'];

    private const TEAM_TOOLS = ['team_metric', 'team_metric_breakdown', 'team_metric_records', 'team_metrics_catalog', 'team_roster'];

    /** @var array<string, int> */
    private array $id = [];

    private string $leaderKey;

    private string $adminKey;

    private string $a3Key;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('x');

        Carbon::setTestNow(Carbon::parse('2026-11-10 14:00', 'Asia/Manila'));
        $this->createExecutiveReportTables();

        foreach (['2026_11_24_000001_create_mcp_keys_and_calls_tables.php', '2026_11_24_000002_add_who_to_mcp_keys_table.php', '2026_11_24_000003_let_mcp_keys_be_permanent.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }

        $this->id = $this->seedExecutiveScenario();
        $this->id['admin'] = $this->makeUser(self::ADMIN, ['name' => 'The Boss', 'email' => 'boss@example.com']);
        $this->id['u2'] = (int) DB::table('agents')->where('id', $this->id['a2'])->value('user_id');

        $this->leaderKey = $this->keyFor($this->id['u1']);
        $this->adminKey = $this->keyFor($this->id['admin']);
        $this->a3Key = $this->keyFor($this->id['u3']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function keyFor(int $userId): string
    {
        return McpKeys::issue(User::query()->findOrFail($userId), 'test')['key'];
    }

    private function role(int $userId): ?McpRole
    {
        return McpRole::of(User::query()->with('role', 'agent')->findOrFail($userId));
    }

    private function rpc(string $method, array $params = [], ?string $key = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];

        if ($key !== null) {
            $headers['Authorization'] = 'Bearer '.$key;
        }

        return $this->postJson(self::ENDPOINT, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method] + ($params === [] ? [] : ['params' => $params]), $headers);
    }

    /** @return array{rpc_error: string|null, is_error: bool, text: string, data: array<string, mixed>|null} */
    private function tool(string $tool, array $arguments, string $key): array
    {
        $response = $this->rpc('tools/call', ['name' => $tool, 'arguments' => (object) $arguments], $key);
        $text = (string) $response->json('result.content.0.text');

        return [
            'rpc_error' => $response->json('error.message'),
            'is_error' => (bool) $response->json('result.isError'),
            'text' => $text,
            'data' => json_decode($text, true),
        ];
    }

    /** @return list<string> */
    private function toolsFor(string $key): array
    {
        $names = collect($this->rpc('tools/list', [], $key)->json('result.tools'))->pluck('name')->all();
        sort($names);

        return $names;
    }

    private function listingsOf(int ...$agentIds): array
    {
        return DB::table('listings')->whereIn('agent_id', $agentIds)->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
    }

    private function recordIds(array $records): array
    {
        $ids = [];

        foreach ($records['rows'] as $row) {
            $listing = $row['listing'] ?? $row['id'] ?? null;
            $ids[] = (int) (is_array($listing) ? ($listing['id'] ?? 0) : $listing);
        }

        sort($ids);

        return $ids;
    }

    /** Makes an agent also a leader of a team (a place in it with the leader flag). */
    private function leadAlso(int $agentId, int $teamId): void
    {
        DB::table('team_agents')->insert(['team_id' => $teamId, 'agent_id' => $agentId, 'is_leader' => true, 'status' => 'active']);
    }

    // ── Who is a team leader ────────────────────────────────────────────────

    public function test_a_leader_is_a_team_leader_and_is_shown_their_own_tools_and_the_teams(): void
    {
        $this->assertSame(McpRole::TeamLeader, $this->role($this->id['u1']));
        $this->assertSame(McpRole::Agent, $this->role($this->id['u3']), 'a member of a team with no leader is an agent');
        $this->assertSame(McpRole::Agent, $this->role($this->id['u2']), 'a member who does not lead is an agent');
        $this->assertSame(McpRole::Admin, $this->role($this->id['admin']));

        $both = [...self::AGENT_TOOLS, ...self::TEAM_TOOLS];
        sort($both);

        $this->assertSame($both, $this->toolsFor($this->leaderKey));
        $this->assertSame(self::AGENT_TOOLS, $this->toolsFor($this->a3Key));
        $this->assertSame(self::ADMIN_TOOLS, $this->toolsFor($this->adminKey));

        // The team tools are not there to call for anyone else, even by name.
        foreach ([$this->a3Key, $this->adminKey] as $key) {
            $this->assertNotNull($this->tool('team_metric', ['metric' => 'listings.total'], $key)['rpc_error']);
            $this->assertNotNull($this->tool('team_roster', [], $key)['rpc_error']);
        }
    }

    public function test_which_role_covers_which_tools(): void
    {
        $this->assertTrue(McpRole::TeamLeader->covers(McpRole::Agent));
        $this->assertTrue(McpRole::TeamLeader->covers(McpRole::TeamLeader));
        $this->assertFalse(McpRole::TeamLeader->covers(McpRole::Admin));
        $this->assertFalse(McpRole::Agent->covers(McpRole::TeamLeader));
        $this->assertFalse(McpRole::Admin->covers(McpRole::Agent));
        $this->assertFalse(McpRole::Admin->covers(McpRole::TeamLeader));

        $this->assertSame(McpRole::TeamLeader, (new TeamLeaderPolicy)->role());
    }

    public function test_the_role_follows_the_data_so_a_leader_who_stops_leading_is_an_agent_at_once(): void
    {
        $this->assertSame(McpRole::TeamLeader, $this->role($this->id['u1']));

        // Their team goes quiet.
        DB::table('teams')->where('id', $this->id['alpha'])->update(['status' => 'inactive']);
        $this->assertSame(McpRole::Agent, $this->role($this->id['u1']));
        $this->assertSame(self::AGENT_TOOLS, $this->toolsFor($this->leaderKey));
        $this->assertTrue($this->tool('team_metric', ['metric' => 'listings.total'], $this->leaderKey)['is_error'] || $this->tool('team_metric', ['metric' => 'listings.total'], $this->leaderKey)['rpc_error'] !== null);
        DB::table('teams')->where('id', $this->id['alpha'])->update(['status' => 'active']);

        // The leader flag is taken off them.
        DB::table('team_agents')->where('agent_id', $this->id['a1'])->update(['is_leader' => false]);
        $this->assertSame(McpRole::Agent, $this->role($this->id['u1']));
        $this->assertSame(self::AGENT_TOOLS, $this->toolsFor($this->leaderKey));
        DB::table('team_agents')->where('agent_id', $this->id['a1'])->update(['is_leader' => true]);
        $this->assertSame(McpRole::TeamLeader, $this->role($this->id['u1']));

        // Their account is switched off: no role at all, so the key opens nothing.
        DB::table('agents')->where('id', $this->id['a1'])->update(['status' => 'terminated']);
        $this->assertNull($this->role($this->id['u1']));
        $this->rpc('tools/list', [], $this->leaderKey)->assertStatus(403);
    }

    public function test_leading_a_team_the_reports_credit_to_another_gives_no_team_view(): void
    {
        // Charlie is a2 alone, and the reports count a2 in Alpha (the lower team id), so Charlie has no numbers
        // of its own. Its leader stays an agent instead of being shown a view that is silently empty.
        DB::table('team_agents')->where('team_id', $this->id['charlie'])->update(['is_leader' => true]);

        $this->assertSame(McpRole::Agent, $this->role($this->id['u2']));
        $this->assertSame(self::AGENT_TOOLS, $this->toolsFor($this->keyFor($this->id['u2'])));
    }

    public function test_two_leaders_on_one_team_both_see_it(): void
    {
        DB::table('team_agents')->where('agent_id', $this->id['a2'])->where('team_id', $this->id['alpha'])->update(['is_leader' => true]);

        $second = $this->keyFor($this->id['u2']);
        $this->assertSame(McpRole::TeamLeader, $this->role($this->id['u2']));
        $this->assertSame(6, $this->tool('team_metric', ['metric' => 'listings.total'], $second)['data']['value']);
        $this->assertSame(6, $this->tool('team_metric', ['metric' => 'listings.total'], $this->leaderKey)['data']['value']);
    }

    // ── What a team leader sees ─────────────────────────────────────────────

    public function test_the_teams_numbers_are_the_teams_and_add_up_to_its_members(): void
    {
        $team = $this->tool('team_metric', ['metric' => 'listings.total'], $this->leaderKey);
        $this->assertFalse($team['is_error'], $team['text']);
        $this->assertSame(6, $team['data']['value'], 'a1\'s three and a2\'s three');
        $this->assertSame(['team_id' => $this->id['alpha']], $team['data']['filters'], 'their team is filled in for them');

        // One member's, by naming them.
        $one = $this->tool('team_metric', ['metric' => 'listings.total', 'agent_id' => $this->id['a2']], $this->leaderKey);
        $this->assertSame(3, $one['data']['value']);
        $this->assertSame(['agent_id' => $this->id['a2'], 'team_id' => $this->id['alpha']], $one['data']['filters']);

        // The admin's own view of the same team agrees: the numbers are the company's team numbers.
        $this->assertSame(6, $this->tool('fh_metric', ['metric' => 'listings.total', 'team_id' => $this->id['alpha']], $this->adminKey)['data']['value']);
        $this->assertSame(10, $this->tool('fh_metric', ['metric' => 'listings.total'], $this->adminKey)['data']['value']);

        // A place filter still narrows within the team.
        $city = $this->tool('team_metric', ['metric' => 'listings.total', 'city_id' => 10], $this->leaderKey);
        $this->assertFalse($city['is_error'], $city['text']);
        $this->assertLessThanOrEqual(6, $city['data']['value']);
    }

    public function test_the_split_by_member_and_the_records_hold_only_the_team(): void
    {
        $split = $this->tool('team_metric_breakdown', ['metric' => 'listings.total', 'by' => 'agent', 'top' => 50], $this->leaderKey);
        $this->assertFalse($split['is_error'], $split['text']);

        $rows = collect($split['data']['rows']);
        $this->assertSame([$this->id['a1'], $this->id['a2']], $rows->pluck('key')->map(fn ($key): int => (int) $key)->sort()->values()->all());
        $this->assertSame(6, $rows->sum('value'));

        $records = $this->tool('team_metric_records', ['metric' => 'listings.total', 'per_page' => 25], $this->leaderKey);
        $this->assertFalse($records['is_error'], $records['text']);
        $this->assertSame(6, $records['data']['meta']['total']);
        $this->assertSame($this->listingsOf($this->id['a1'], $this->id['a2']), $this->recordIds($records['data']));

        // And the people in the team, by name and id.
        $roster = $this->tool('team_roster', [], $this->leaderKey);
        $this->assertFalse($roster['is_error'], $roster['text']);
        $team = $roster['data']['teams'][0];
        $members = collect($team['members'])->keyBy('agent_id');

        $this->assertSame($this->id['alpha'], $team['team_id']);
        $this->assertSame('Alpha', $team['team']);
        $this->assertSame([$this->id['a1'], $this->id['a2']], $members->keys()->sort()->values()->all());
        $this->assertTrue($members[$this->id['a1']]['is_leader']);
        $this->assertFalse($members[$this->id['a2']]['is_leader']);
        $this->assertTrue($members[$this->id['a2']]['counted_in_team_numbers'], 'a2 is credited to Alpha, the lower team id');
        $this->assertStringNotContainsString('@', $roster['text'], 'names and ids, no contact details');
    }

    public function test_a_leader_keeps_their_own_numbers_in_the_agent_tools(): void
    {
        $own = $this->tool('my_metric', ['metric' => 'listings.total'], $this->leaderKey);

        $this->assertFalse($own['is_error'], $own['text']);
        $this->assertSame(3, $own['data']['value'], 'a1 as themselves, not the team');
    }

    public function test_a_leader_cannot_reach_another_team_the_company_or_a_member_who_is_not_theirs(): void
    {
        foreach ([
            'another team' => ['team_id' => $this->id['bravo']],
            'no team at all (0)' => ['team_id' => 0],
            'an agent of another team' => ['agent_id' => $this->id['a3']],
            'an agent with no team' => ['agent_id' => $this->id['a4']],
            'the admin\'s own agent row' => ['agent_id' => $this->id['s1']],
        ] as $why => $extra) {
            $answer = $this->tool('team_metric', ['metric' => 'listings.total', ...$extra], $this->leaderKey);

            $this->assertTrue($answer['is_error'], $why);
            $this->assertNull($answer['data'], $why);
            $this->assertStringNotContainsString('Bravo', $answer['text'], $why);
        }

        // The same holds for the split and the records.
        $this->assertTrue($this->tool('team_metric_breakdown', ['metric' => 'listings.total', 'by' => 'project', 'team_id' => $this->id['bravo']], $this->leaderKey)['is_error']);
        $this->assertTrue($this->tool('team_metric_records', ['metric' => 'listings.total', 'team_id' => $this->id['bravo']], $this->leaderKey)['is_error']);
        $this->assertTrue($this->tool('team_roster', ['team_id' => $this->id['bravo']], $this->leaderKey)['is_error']);

        // The company's own numbers: the teams figures (they would answer for everyone), the sign-in figures, anything unknown.
        foreach (['teams.total', 'teams.active', 'teams.no_leader', 'usage.total_users', 'no.such_metric'] as $metric) {
            $answer = $this->tool('team_metric', ['metric' => $metric], $this->leaderKey);
            $this->assertTrue($answer['is_error'], $metric);
            $this->assertNull($answer['data'], $metric);
        }

        // Splitting by team or by office would lead to the others; and there is no way to look a team up.
        foreach (['team', 'office_region'] as $by) {
            $this->assertTrue($this->tool('team_metric_breakdown', ['metric' => 'listings.total', 'by' => $by], $this->leaderKey)['is_error'], "by {$by}");
        }

        $this->assertTrue($this->tool('my_lookup', ['type' => 'teams', 'text' => 'bravo'], $this->leaderKey)['is_error']);

        $listed = collect($this->tool('team_metrics_catalog', [], $this->leaderKey)['data']['metrics'])->pluck('id');
        $this->assertTrue($listed->contains('listings.live'));
        $this->assertTrue($listed->contains('agents.headcount'), 'how many people are in the team is the team\'s own');
        $this->assertFalse($listed->contains('teams.total'));
        $this->assertFalse($listed->contains('usage.total_users'));
    }

    public function test_a_leader_of_two_teams_has_to_say_which(): void
    {
        // a1 also leads Bravo. a1 is credited to Alpha, so Bravo's numbers are a3's.
        $this->leadAlso($this->id['a1'], $this->id['bravo']);

        $this->assertSame(McpRole::TeamLeader, $this->role($this->id['u1']));

        $silent = $this->tool('team_metric', ['metric' => 'listings.total'], $this->leaderKey);
        $this->assertTrue($silent['is_error']);
        $this->assertStringContainsString('more than one team', $silent['text']);

        $this->assertSame(6, $this->tool('team_metric', ['metric' => 'listings.total', 'team_id' => $this->id['alpha']], $this->leaderKey)['data']['value']);
        $this->assertSame(2, $this->tool('team_metric', ['metric' => 'listings.total', 'team_id' => $this->id['bravo']], $this->leaderKey)['data']['value']);

        // A member is a member of the team asked about, not of the other one.
        $this->assertTrue($this->tool('team_metric', ['metric' => 'listings.total', 'team_id' => $this->id['alpha'], 'agent_id' => $this->id['a3']], $this->leaderKey)['is_error']);
        $this->assertSame(2, $this->tool('team_metric', ['metric' => 'listings.total', 'team_id' => $this->id['bravo'], 'agent_id' => $this->id['a3']], $this->leaderKey)['data']['value']);

        // The roster lists both, and says a1 is counted in Alpha, not in Bravo.
        $teams = collect($this->tool('team_roster', [], $this->leaderKey)['data']['teams'])->keyBy('team_id');
        $this->assertSame([$this->id['alpha'], $this->id['bravo']], $teams->keys()->sort()->values()->all());

        $inBravo = collect($teams[$this->id['bravo']]['members'])->keyBy('agent_id');
        $this->assertTrue($inBravo[$this->id['a3']]['counted_in_team_numbers']);
        $this->assertFalse($inBravo[$this->id['a1']]['counted_in_team_numbers']);
    }

    public function test_every_number_a_team_leader_may_ask_for_really_narrows_to_a_team(): void
    {
        // The reason the teams figures are closed: a number can accept a team filter and still count the whole company.
        // Every counting number open to a leader is asked for Alpha, for Bravo and for everyone: if all three come
        // back the same and not empty, the team filter did nothing.
        config(['fhmcp.rate_limit' => 100000]); // a hundred and more calls in a moment, on purpose

        $catalog = collect($this->tool('team_metrics_catalog', [], $this->leaderKey)['data']['metrics']);
        $this->assertGreaterThan(30, $catalog->count());

        $checked = 0;
        $same = [];

        foreach ($catalog as $metric) {
            $id = (string) $metric['id'];
            $alpha = $this->tool('team_metric', ['metric' => $id], $this->leaderKey);

            if ($alpha['is_error']) {
                $this->fail("{$id}: {$alpha['text']}");
            }

            // The leader's answer is the admin's answer for that team.
            $theirs = $this->tool('fh_metric', ['metric' => $id, 'team_id' => $this->id['alpha']], $this->adminKey);
            $this->assertSame($theirs['data']['value'], $alpha['data']['value'], $id);

            if (($metric['unit'] ?? null) !== 'count') {
                continue;
            }

            $bravo = $this->tool('fh_metric', ['metric' => $id, 'team_id' => $this->id['bravo']], $this->adminKey);
            $company = $this->tool('fh_metric', ['metric' => $id], $this->adminKey);
            $values = [(float) $alpha['data']['value'], (float) $bravo['data']['value'], (float) $company['data']['value']];
            $checked++;

            if (max($values) > 0 && count(array_unique($values)) === 1) {
                $same[] = $id;
            }
        }

        $this->assertGreaterThan(20, $checked, 'enough counting numbers were compared');
        $this->assertSame([], $same, 'these answer the same for two teams and for the company, so the team filter does nothing: close them to team leaders');
    }

    public function test_the_teams_figures_really_do_ignore_a_team_filter_which_is_why_they_are_closed(): void
    {
        // The check above is only worth something if it catches a real leak, and this is one: the teams figures take a
        // team filter and still answer for the whole company. If this ever fails, the engine was fixed and the teams
        // figures can be reconsidered for team leaders (TeamLeaderPolicy::allowsMetric).
        foreach (['teams.total', 'teams.active'] as $metric) {
            $company = $this->tool('fh_metric', ['metric' => $metric], $this->adminKey)['data']['value'];
            $alpha = $this->tool('fh_metric', ['metric' => $metric, 'team_id' => $this->id['alpha']], $this->adminKey)['data']['value'];
            $bravo = $this->tool('fh_metric', ['metric' => $metric, 'team_id' => $this->id['bravo']], $this->adminKey)['data']['value'];

            $this->assertGreaterThan(0, $company, $metric);
            $this->assertSame([$company, $company], [$alpha, $bravo], "{$metric} answers for the company whatever the team");
        }
    }

    // ── The commands ────────────────────────────────────────────────────────

    public function test_a_key_made_with_the_command_for_a_team_leader_acts_as_one(): void
    {
        $email = (string) DB::table('users')->where('id', $this->id['u1'])->value('email');

        $this->assertSame(0, Artisan::call('fhmcp:make-key', ['email' => $email, '--name' => 'leader laptop']));
        $out = Artisan::output();

        $this->assertStringContainsString('acting as team_leader', $out);
        $this->assertStringContainsString('own team and its members', $out);
        $this->assertSame(1, preg_match('/fhmcp_[A-Za-z0-9]{48}/', $out, $found));

        $both = [...self::AGENT_TOOLS, ...self::TEAM_TOOLS];
        sort($both);
        $this->assertSame($both, $this->toolsFor($found[0]));
    }

    public function test_the_team_check_prints_counts_only_and_changes_nothing(): void
    {
        $before = [DB::table('team_agents')->count(), DB::table('teams')->count(), DB::table('agents')->count()];

        $this->assertSame(0, Artisan::call('fhmcp:team-check'));
        $out = Artisan::output();

        // The fixture: Alpha (a1 leads, a2), Bravo (a3), Charlie (a2, who is counted in Alpha).
        foreach ([
            'Active teams with a leader' => 1,
            'Active teams with members but no leader' => 2,
            'Active teams the reports can show \(some member is counted in them\)' => 2,
            'who would be shown the team tools' => 1,
            'who would stay a plain agent \(their team has no counted members\)' => 0,
            'Agents in two or more active teams \(counted in the lowest-numbered only\)' => 1,
        ] as $label => $count) {
            $this->assertMatchesRegularExpression('/'.$label.'\s*\|\s*'.$count.'\s*\|/', $out, $label);
        }

        $this->assertMatchesRegularExpression('/smallest \/ middle \/ largest\s*\|\s*1 \/ 1 \/ 2\s*\|/', $out);

        // Counts only.
        foreach (['@', 'Alpha', 'Bravo', 'Charlie'] as $name) {
            $this->assertStringNotContainsString($name, $out, $name);
        }

        $this->assertSame($before, [DB::table('team_agents')->count(), DB::table('teams')->count(), DB::table('agents')->count()]);
    }

    // ── Nothing it does changes anything or hands out contact details ───────

    public function test_calling_every_team_tool_writes_nothing_but_the_audit_and_leaks_no_contact_details(): void
    {
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $tables = ['users', 'agents', 'listings', 'properties', 'teams', 'team_agents', 'chats', 'messages'];
        $before = array_map(fn (string $table): int => DB::table($table)->count(), $tables);

        foreach ([
            ['team_roster', []],
            ['team_metrics_catalog', ['search' => 'sold']],
            ['team_metric', ['metric' => 'deals.sold', 'compare' => true]],
            ['team_metric', ['metric' => 'agents.headcount']],
            ['team_metric_breakdown', ['metric' => 'listings.total', 'by' => 'agent']],
            ['team_metric_breakdown', ['metric' => 'listings.live', 'by' => 'category']],
            ['team_metric_records', ['metric' => 'inquiries.received']],
            ['team_metric_records', ['metric' => 'agents.headcount']],
        ] as [$tool, $arguments]) {
            $answer = $this->tool($tool, $arguments, $this->leaderKey);

            $this->assertNull($answer['rpc_error'], "{$tool}: {$answer['text']}");
            $this->assertFalse($answer['is_error'], "{$tool}: {$answer['text']}");
            $this->assertStringNotContainsString('@', $answer['text'], "{$tool} carries an email address");
        }

        $this->assertNotEmpty($writes, 'the audit is written');

        foreach ($writes as $sql) {
            $this->assertMatchesRegularExpression('/\b(mcp_calls|mcp_keys)\b/', $sql, 'a call wrote somewhere it should not: '.$sql);
        }

        $this->assertSame($before, array_map(fn (string $table): int => DB::table($table)->count(), $tables));
    }

    public function test_a_team_call_is_on_record_as_a_team_leaders(): void
    {
        $this->tool('team_metric', ['metric' => 'listings.total'], $this->leaderKey);
        $this->tool('my_metric', ['metric' => 'listings.total'], $this->leaderKey);

        $calls = McpCall::query()->orderBy('id')->get();

        $this->assertSame(['team_metric', 'my_metric'], $calls->pluck('tool')->all());
        $this->assertSame(['team_leader', 'team_leader'], $calls->pluck('role')->all(), 'one person, one role, whichever of their tools they used');
        $this->assertSame([$this->id['u1'], $this->id['u1']], $calls->pluck('user_id')->map(fn ($id): int => (int) $id)->all());
    }
}
