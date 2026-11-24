<?php

namespace Tests\Feature;

use App\Mcp\Support\McpKeys;
use App\Models\McpKey;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/**
 * The dashboard side of the MCP server (System Settings > Claude access):
 * admins make, list and switch off keys, and nobody else can. A key is shown
 * once, when it is made, and nowhere after. The server itself is FhMcpTest.
 */
class FhMcpKeysAdminTest extends TestCase
{
    use CreatesExecutiveReportTables;

    /** @var array<string, int> */
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Carbon::setTestNow(Carbon::parse('2026-11-10 14:00', 'Asia/Manila'));
        $this->createExecutiveReportTables();

        foreach (['2026_11_24_000001_create_mcp_keys_and_calls_tables.php', '2026_11_24_000002_add_who_to_mcp_keys_table.php', '2026_11_24_000003_let_mcp_keys_be_permanent.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }

        $this->id = $this->seedExecutiveScenario();
        // The shared fixture makes a1 the leader of team Alpha. These tests are about admins and plain
        // agents, so a1 is one here; team leaders are FhMcpTeamLeaderTest's.
        DB::table('team_agents')->where('agent_id', $this->id['a1'])->update(['is_leader' => false]);
        $this->id['admin'] = $this->makeUser(self::ADMIN, ['name' => 'The Boss', 'email' => 'boss@example.com']);
        $this->id['admin2'] = $this->makeUser(self::ADMIN, ['name' => 'Other Boss', 'email' => 'boss2@example.com']);
        $this->id['secretary'] = $this->makeUser(self::SECRETARY, ['name' => 'Sec']);
        $this->id['editor'] = $this->makeUser(self::EDITOR, ['name' => 'Ed']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(int $userId): void
    {
        Sanctum::actingAs(User::query()->findOrFail($userId));
    }

    /** The MCP server's own answer to a key (to see that a key made here really works, or has stopped). */
    private function mcp(string $key, string $method = 'tools/list'): int
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/mcp/filipinohomes', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method], [
            'Authorization' => 'Bearer '.$key,
            'Accept' => 'application/json',
            'MCP-Protocol-Version' => '2025-06-18',
        ])->getStatusCode();
    }

    // ── Admins only ─────────────────────────────────────────────────────────

    public function test_only_an_admin_can_reach_any_of_it(): void
    {
        $paths = [['get', '/api/admin/mcp-keys'], ['post', '/api/admin/mcp-keys'], ['get', '/api/admin/mcp-keys/people?q=ab'], ['delete', '/api/admin/mcp-keys/1'], ['get', '/api/admin/mcp-calls']];

        foreach ($paths as [$method, $path]) {
            $this->app['auth']->forgetGuards();
            $this->{$method.'Json'}($path)->assertUnauthorized();
        }

        foreach (['agent' => $this->id['u1'], 'secretary' => $this->id['secretary'], 'editor' => $this->id['editor'], 'client' => $this->id['c1']] as $who => $userId) {
            foreach ($paths as [$method, $path]) {
                $this->as($userId);
                $this->{$method.'Json'}($path, $method === 'post' ? ['name' => 'x'] : [])->assertForbidden();
            }
        }

        // And nothing was made along the way.
        $this->assertSame(0, McpKey::query()->count());
    }

    // ── Making a key ────────────────────────────────────────────────────────

    public function test_an_admin_makes_a_key_for_themselves_and_sees_it_once(): void
    {
        $this->as($this->id['admin']);

        $made = $this->postJson('/api/admin/mcp-keys', ['name' => 'Claude Code on my laptop', 'days' => 14])->assertCreated();

        $key = $made->json('key');
        $this->assertMatchesRegularExpression('/^fhmcp_[A-Za-z0-9]{48}$/', $key);
        $this->assertStringContainsString('no-store', (string) $made->headers->get('Cache-Control'));
        $made->assertJsonPath('url', url('/mcp/filipinohomes'))
            ->assertJsonPath('data.acts_as', 'admin')
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.person.id', $this->id['admin'])
            ->assertJsonPath('data.created_by.id', $this->id['admin'])
            ->assertJsonPath('data.key_start', substr($key, 0, 12).'…');

        // It works on the MCP server, as an admin.
        $this->assertSame(200, $this->mcp($key));

        // After this answer the key is nowhere: not in the list, and not in the table.
        $this->as($this->id['admin']);
        $list = $this->getJson('/api/admin/mcp-keys')->assertOk();
        $this->assertStringNotContainsString($key, $list->getContent());
        $this->assertStringNotContainsString(hash('sha256', $key), $list->getContent());
        $this->assertStringNotContainsString($key, json_encode(DB::table('mcp_keys')->get()));
        $list->assertJsonPath('data.0.name', 'Claude Code on my laptop')
            ->assertJsonPath('server.url', url('/mcp/filipinohomes'))
            ->assertJsonPath('server.max_keys', 3)
            ->assertJsonPath('server.pickers', ['admin', 'team_leader', 'agent']);
    }

    public function test_the_address_claude_is_given_is_the_configured_public_one(): void
    {
        // Behind the proxy the request reads http://; what people are told to connect to is fixed in config.
        config(['fhmcp.public_url' => 'https://api.example.test']);
        $this->as($this->id['admin']);

        $this->postJson('/api/admin/mcp-keys', ['name' => 'x'])->assertCreated()->assertJsonPath('url', 'https://api.example.test/mcp/filipinohomes');

        $this->as($this->id['admin']);
        $this->getJson('/api/admin/mcp-keys')->assertOk()->assertJsonPath('server.url', 'https://api.example.test/mcp/filipinohomes');
    }

    public function test_an_admin_can_make_a_key_for_an_active_agent_and_it_sees_only_that_agents_own_numbers(): void
    {
        $this->as($this->id['admin']);

        $made = $this->postJson('/api/admin/mcp-keys', ['user_id' => $this->id['u1'], 'name' => 'For the agent'])->assertCreated();

        $made->assertJsonPath('data.acts_as', 'agent')->assertJsonPath('data.person.id', $this->id['u1'])->assertJsonPath('data.created_by.id', $this->id['admin']);

        $this->app['auth']->forgetGuards();
        $tools = collect($this->postJson('/mcp/filipinohomes', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer '.$made->json('key'), 'Accept' => 'application/json', 'MCP-Protocol-Version' => '2025-06-18'])->json('result.tools'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['my_lookup', 'my_metric', 'my_metric_breakdown', 'my_metric_records', 'my_metrics_catalog'], $tools);
    }

    public function test_a_key_is_made_for_an_admin_a_team_leader_or_an_active_agent_and_nobody_else(): void
    {
        $inactive = (int) DB::table('agents')->where('id', $this->id['a5'])->value('user_id');

        foreach (['a secretary' => $this->id['secretary'], 'an editor' => $this->id['editor'], 'a client' => $this->id['c1'], 'an inactive agent' => $inactive, 'nobody' => 999999] as $who => $userId) {
            $this->as($this->id['admin']);
            $this->postJson('/api/admin/mcp-keys', ['user_id' => $userId, 'name' => 'x'])->assertStatus(422);
        }

        $this->assertSame(0, McpKey::query()->count());

        // Another admin, a team leader (a1 leads Alpha) and an agent are all fine, and the key acts as that person.
        DB::table('team_agents')->where('agent_id', $this->id['a1'])->update(['is_leader' => true]);

        foreach (['another admin' => [$this->id['admin2'], 'admin'], 'a team leader' => [$this->id['u1'], 'team_leader'], 'an agent' => [$this->id['u3'], 'agent']] as [$userId, $acts]) {
            $this->as($this->id['admin']);
            $this->postJson('/api/admin/mcp-keys', ['user_id' => $userId, 'name' => 'x'])
                ->assertCreated()
                ->assertJsonPath('data.acts_as', $acts)
                ->assertJsonPath('data.person.id', $userId)
                ->assertJsonPath('data.created_by.id', $this->id['admin']);
        }
    }

    public function test_the_name_the_days_and_the_number_of_keys_are_checked(): void
    {
        $this->as($this->id['admin']);

        $this->postJson('/api/admin/mcp-keys', [])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/mcp-keys', ['name' => str_repeat('a', 81)])->assertStatus(422);
        $this->postJson('/api/admin/mcp-keys', ['name' => 'x', 'days' => 0])->assertStatus(422);
        $this->postJson('/api/admin/mcp-keys', ['name' => 'x', 'days' => 91])->assertStatus(422);

        foreach (range(1, 3) as $n) {
            $this->postJson('/api/admin/mcp-keys', ['name' => "key {$n}"])->assertCreated();
        }

        // A fourth is refused, in words.
        $this->postJson('/api/admin/mcp-keys', ['name' => 'key 4'])->assertStatus(422)->assertJsonPath('message', 'This person already has 3 keys in use. Revoke one first.');
    }

    // ── A permanent key ─────────────────────────────────────────────────────

    public function test_a_key_can_be_permanent_and_only_a_revoke_or_losing_access_stops_it(): void
    {
        $this->as($this->id['admin']);

        $made = $this->postJson('/api/admin/mcp-keys', ['user_id' => $this->id['u1'], 'name' => 'Always on', 'permanent' => true])->assertCreated();
        $key = $made->json('key');
        $id = $made->json('data.id');

        $made->assertJsonPath('data.expires_at', null)->assertJsonPath('data.state', 'active');
        $this->assertNull(McpKey::query()->findOrFail($id)->expires_at);
        $this->assertSame(200, $this->mcp($key));

        // Years go by and it is still good, and the list says so.
        Carbon::setTestNow(now()->addYears(5));
        $this->assertSame(200, $this->mcp($key));
        $this->as($this->id['admin']);
        $this->getJson('/api/admin/mcp-keys')->assertOk()->assertJsonPath('data.0.state', 'active')->assertJsonPath('data.0.expires_at', null);

        // It is still read through the person's role: when the agent is switched off, the key opens nothing.
        DB::table('agents')->where('id', $this->id['a1'])->update(['status' => 'terminated']);
        $this->assertSame(403, $this->mcp($key));

        // And it is revoked like any other.
        $this->as($this->id['admin']);
        $this->deleteJson('/api/admin/mcp-keys/'.$id)->assertOk()->assertJsonPath('data.state', 'revoked');
        $this->assertSame(401, $this->mcp($key));
    }

    public function test_permanent_and_days_are_never_both_and_a_permanent_key_counts_toward_the_limit(): void
    {
        $this->as($this->id['admin']);

        $this->postJson('/api/admin/mcp-keys', ['name' => 'x', 'permanent' => true, 'days' => 30])->assertStatus(422)
            ->assertJsonPath('message', 'A key is either permanent or lasts a number of days, not both.');
        $this->postJson('/api/admin/mcp-keys', ['name' => 'x', 'permanent' => 'perhaps'])->assertStatus(422)->assertJsonValidationErrors('permanent');
        $this->assertSame(0, McpKey::query()->count());

        // Not permanent is just the days, as before.
        $dated = $this->postJson('/api/admin/mcp-keys', ['name' => 'a week', 'permanent' => false, 'days' => 7])->assertCreated();
        $this->assertEqualsWithDelta(7, Carbon::parse($dated->json('data.created_at'))->diffInDays(Carbon::parse($dated->json('data.expires_at'))), 0.01, 'a week after it was made');

        foreach (range(1, 2) as $n) {
            $this->postJson('/api/admin/mcp-keys', ['name' => "forever {$n}", 'permanent' => true])->assertCreated();
        }

        $this->postJson('/api/admin/mcp-keys', ['name' => 'forever 3', 'permanent' => true])->assertStatus(422)
            ->assertJsonPath('message', 'This person already has 3 keys in use. Revoke one first.');
    }

    public function test_rolling_the_permanent_migration_back_ends_a_permanent_key_instead_of_leaving_it_without_an_end(): void
    {
        $permanent = McpKeys::issue(User::query()->findOrFail($this->id['u1']), 'forever', permanent: true);
        $dated = McpKeys::issue(User::query()->findOrFail($this->id['u3']), 'dated', 10);

        (require database_path('migrations/2026_11_24_000003_let_mcp_keys_be_permanent.php'))->down();

        $this->assertSame(401, $this->mcp($permanent['key']), 'it ended now');
        $this->assertSame(200, $this->mcp($dated['key']), 'a dated key is left as it was');
    }

    // ── Switching a key off ─────────────────────────────────────────────────

    public function test_revoking_a_key_stops_it_at_once_and_records_who_did_it(): void
    {
        $issued = McpKeys::issue(User::query()->findOrFail($this->id['u3']), 'agent laptop', null, User::query()->findOrFail($this->id['admin']));
        $this->assertSame(200, $this->mcp($issued['key']));

        $this->as($this->id['admin2']);
        $this->deleteJson('/api/admin/mcp-keys/'.$issued['record']->id)
            ->assertOk()
            ->assertJsonPath('data.state', 'revoked');

        $this->assertSame(401, $this->mcp($issued['key']));

        $record = $issued['record']->fresh();
        $this->assertSame($this->id['admin2'], (int) $record->revoked_by);
        $this->assertSame($this->id['admin'], (int) $record->created_by);

        // Again is the same answer, and the first admin's name stays the one who revoked it.
        $this->as($this->id['admin']);
        $this->deleteJson('/api/admin/mcp-keys/'.$issued['record']->id)->assertOk()->assertJsonPath('data.state', 'revoked');
        $this->assertSame($this->id['admin2'], (int) $issued['record']->fresh()->revoked_by);

        $this->deleteJson('/api/admin/mcp-keys/999999')->assertNotFound();
        $this->deleteJson('/api/admin/mcp-keys/abc')->assertNotFound();
    }

    public function test_the_commands_keep_working_in_the_moment_between_deploying_and_running_the_second_migration(): void
    {
        // The deploy brings the code first; the column for "who made it" arrives with the migration after.
        (require database_path('migrations/2026_11_24_000002_add_who_to_mcp_keys_table.php'))->down();
        $this->assertFalse(Schema::hasColumn('mcp_keys', 'created_by'));

        $issued = McpKeys::issue(User::query()->findOrFail($this->id['u1']), 'made by the command');
        $this->assertSame(200, $this->mcp($issued['key']));

        McpKeys::revoke($issued['record']);
        $this->assertSame(401, $this->mcp($issued['key']));
    }

    // ── What the list says ──────────────────────────────────────────────────

    public function test_the_list_says_who_what_state_and_how_much_each_key_was_used_without_giving_the_key(): void
    {
        $agent = McpKeys::issue(User::query()->findOrFail($this->id['u1']), 'agent');
        $expired = McpKeys::issue(User::query()->findOrFail($this->id['u3']), 'old');
        $expired['record']->forceFill(['expires_at' => now()->subHour()])->save();
        $revoked = McpKeys::issue(User::query()->findOrFail($this->id['admin']), 'lost');
        McpKeys::revoke($revoked['record']);

        // The agent's key asks twice.
        $this->mcp($agent['key']);
        foreach (range(1, 2) as $_) {
            $this->app['auth']->forgetGuards();
            $this->postJson('/mcp/filipinohomes', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'my_metric', 'arguments' => ['metric' => 'listings.total']]], ['Authorization' => 'Bearer '.$agent['key'], 'Accept' => 'application/json', 'MCP-Protocol-Version' => '2025-06-18'])->assertOk();
        }

        $this->as($this->id['admin']);
        $rows = collect($this->getJson('/api/admin/mcp-keys')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame('active', $rows['agent']['state']);
        $this->assertSame('agent', $rows['agent']['acts_as']);
        $this->assertSame(2, $rows['agent']['calls_7d']);
        $this->assertNotNull($rows['agent']['last_used_at']);
        $this->assertNull($rows['agent']['created_by'], 'made with the command, not from the dashboard');
        $this->assertSame('expired', $rows['old']['state']);
        $this->assertSame('revoked', $rows['lost']['state']);
        $this->assertSame(0, $rows['old']['calls_7d']);
        $this->assertSame(['id', 'name', 'key_start', 'state', 'person', 'acts_as', 'created_by', 'created_at', 'expires_at', 'last_used_at', 'revoked_at', 'calls_7d'], array_keys($rows['agent']));
    }

    public function test_a_key_whose_person_lost_access_says_so(): void
    {
        McpKeys::issue(User::query()->findOrFail($this->id['u1']), 'agent');
        DB::table('agents')->where('id', $this->id['a1'])->update(['status' => 'terminated']);

        $this->as($this->id['admin']);
        $row = $this->getJson('/api/admin/mcp-keys')->assertOk()->json('data.0');

        $this->assertNull($row['acts_as'], 'it opens nothing any more');
        $this->assertSame('active', $row['state']);
    }

    // ── Finding an agent ────────────────────────────────────────────────────

    public function test_people_finds_only_active_agents_and_only_from_two_letters(): void
    {
        DB::table('users')->where('id', $this->id['u1'])->update(['name' => 'Maria Santos', 'email' => 'maria@example.com']);
        DB::table('users')->where('id', $this->id['u3'])->update(['name' => 'Mario Reyes']);
        $inactive = (int) DB::table('agents')->where('id', $this->id['a5'])->value('user_id');
        DB::table('users')->where('id', $inactive)->update(['name' => 'Maricel Gone']);
        DB::table('users')->where('id', $this->id['secretary'])->update(['name' => 'Marie Secretary']);

        $this->as($this->id['admin']);

        $this->getJson('/api/admin/mcp-keys/people?q=m')->assertOk()->assertExactJson(['data' => []]);

        $found = collect($this->getJson('/api/admin/mcp-keys/people?q=mari')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Maria Santos', 'Mario Reyes'], $found, 'no inactive agent, no secretary');

        $this->getJson('/api/admin/mcp-keys/people?q=maria@example')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $this->id['u1']);

        // A "%" is a character, not a wildcard.
        $this->getJson('/api/admin/mcp-keys/people?q=%25%25')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_people_lists_admins_with_you_first_team_leaders_with_their_team_and_agents_who_do_not_lead(): void
    {
        DB::table('team_agents')->where('agent_id', $this->id['a1'])->update(['is_leader' => true]); // a1 leads Alpha
        DB::table('users')->where('id', $this->id['u1'])->update(['name' => 'Lead Lara']);
        DB::table('users')->where('id', $this->id['u3'])->update(['name' => 'Plain Pete']);

        $this->as($this->id['admin2']);

        // Admins list without a search, and the signed-in one comes first.
        $admins = collect($this->getJson('/api/admin/mcp-keys/people?kind=admin')->assertOk()->json('data'));
        $this->assertSame($this->id['admin2'], $admins->first()['user_id']);
        $this->assertTrue($admins->first()['you']);
        $this->assertSame(1, $admins->where('you', true)->count());
        $this->assertTrue($admins->pluck('user_id')->contains($this->id['admin']));
        $this->assertSame(['admin'], $admins->pluck('role')->unique()->values()->all());
        $this->assertFalse($admins->pluck('user_id')->contains($this->id['secretary']));
        $this->assertSame([$this->id['admin']], collect($this->getJson('/api/admin/mcp-keys/people?kind=admin&q=boss')->json('data'))->where('name', 'The Boss')->pluck('user_id')->all());

        // Team leaders list without a search too, with the team they lead.
        $leaders = collect($this->getJson('/api/admin/mcp-keys/people?kind=team_leader')->assertOk()->json('data'));
        $this->assertSame([$this->id['u1']], $leaders->pluck('user_id')->all());
        $this->assertSame(['Alpha'], $leaders->pluck('team')->all());
        $this->assertSame(['team_leader'], $leaders->pluck('role')->all());
        $this->assertCount(1, $this->getJson('/api/admin/mcp-keys/people?kind=team_leader&q=lara')->json('data'));
        $this->assertCount(0, $this->getJson('/api/admin/mcp-keys/people?kind=team_leader&q=pete')->json('data'));

        // Agents still need two letters, and a leader is found as a leader, not as an agent.
        $this->assertCount(0, $this->getJson('/api/admin/mcp-keys/people?kind=agent&q=p')->json('data'));
        $this->assertSame([$this->id['u3']], collect($this->getJson('/api/admin/mcp-keys/people?kind=agent&q=pete')->json('data'))->pluck('user_id')->all());
        $this->assertCount(0, $this->getJson('/api/admin/mcp-keys/people?kind=agent&q=lara')->json('data'));
        $this->assertSame([$this->id['u3']], collect($this->getJson('/api/admin/mcp-keys/people?q=pete')->json('data'))->pluck('user_id')->all(), 'agents when no kind is given');

        // A kind nobody knows is an agent search, not an error and not everyone.
        $this->assertCount(0, $this->getJson('/api/admin/mcp-keys/people?kind=everyone')->json('data'));
    }

    // ── What the keys were asked ────────────────────────────────────────────

    public function test_the_activity_list_shows_who_asked_what_newest_first_and_can_be_filtered(): void
    {
        $agent = McpKeys::issue(User::query()->findOrFail($this->id['u1']), 'agent laptop');
        $headers = ['Authorization' => 'Bearer '.$agent['key'], 'Accept' => 'application/json', 'MCP-Protocol-Version' => '2025-06-18'];

        foreach ([['metric' => 'listings.total'], ['metric' => 'teams.total']] as $arguments) {
            $this->app['auth']->forgetGuards();
            $this->postJson('/mcp/filipinohomes', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'my_metric', 'arguments' => $arguments]], $headers)->assertOk();
        }

        $this->as($this->id['admin']);
        $calls = $this->getJson('/api/admin/mcp-calls')->assertOk()->json('data');

        $this->assertCount(2, $calls);
        $this->assertSame('error', $calls[0]['outcome'], 'the newest is first');
        $this->assertSame(['metric' => 'teams.total'], $calls[0]['arguments']);
        $this->assertSame('agent laptop', $calls[0]['key_name']);
        $this->assertSame($this->id['u1'], $calls[0]['person']['id']);
        $this->assertSame('agent', $calls[0]['role']);
        $this->assertArrayNotHasKey('ip', $calls[0]);

        $this->getJson('/api/admin/mcp-calls?outcome=ok')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/mcp-calls?outcome=nonsense')->assertStatus(422);
        $this->getJson('/api/admin/mcp-calls?limit=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/mcp-calls?limit=500')->assertStatus(422);
    }
}
