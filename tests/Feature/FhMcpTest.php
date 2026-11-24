<?php

namespace Tests\Feature;

use App\Mcp\Policies\AdminPolicy;
use App\Mcp\Policies\AgentPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Policies\TeamLeaderPolicy;
use App\Mcp\Servers\FilipinoHomesServer;
use App\Mcp\Support\Asks;
use App\Mcp\Support\McpActor;
use App\Mcp\Support\McpKeys;
use App\Mcp\Support\McpOutput;
use App\Mcp\Support\McpRole;
use App\Mcp\Tools\FhTool;
use App\Models\McpKey;
use App\Models\User;
use App\Services\Audience\AiVisibilityService;
use App\Services\Audience\TrafficSourceService;
use App\Services\User\ClientInsightsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/**
 * FH's MCP server (Claude reading FH's numbers, /mcp/filipinohomes): who is
 * let in, that an admin and an agent are shown and can reach different
 * things, and above all that nothing it offers can change, delete or leak.
 *
 * Runs on the Executive Report's fixture (seedExecutiveScenario()): ten
 * listings, spread over agents a1 (3 of them), a2 (3), a3 (2), a4 (1) and a
 * staff account (1).
 */
class FhMcpTest extends TestCase
{
    use CreatesExecutiveReportTables;

    private const ENDPOINT = '/mcp/filipinohomes';

    private const ADMIN_TOOLS = ['fh_ai_visibility', 'fh_clients', 'fh_lookup', 'fh_metric', 'fh_metric_breakdown', 'fh_metric_records', 'fh_metrics_catalog', 'fh_traffic'];

    private const AGENT_TOOLS = ['my_lookup', 'my_metric', 'my_metric_breakdown', 'my_metric_records', 'my_metrics_catalog'];

    private const TEAM_TOOLS = ['team_metric', 'team_metric_breakdown', 'team_metric_records', 'team_metrics_catalog', 'team_roster'];

    /** @var array<string, int> */
    private array $id = [];

    private string $adminKey;

    private string $a1Key;

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

        $this->createVisitTables();
        $this->fakeClientInsights();
        $this->id = $this->seedExecutiveScenario();
        // The shared fixture makes a1 the leader of team Alpha. These tests are about admins and plain
        // agents, so a1 is one here; team leaders are FhMcpTeamLeaderTest's.
        DB::table('team_agents')->where('agent_id', $this->id['a1'])->update(['is_leader' => false]);

        $this->id['admin'] = $this->makeUser(self::ADMIN, ['name' => 'The Boss', 'email' => 'boss@example.com']);

        $this->adminKey = $this->keyFor($this->id['admin']);
        $this->a1Key = $this->keyFor($this->id['u1']);
        $this->a3Key = $this->keyFor($this->id['u3']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * The Client Insights page's summary, as fh_clients reads it. The service itself runs MySQL-only SQL
     * (and has its own page), so these tests give the tool a fixed summary and check what it makes of it.
     */
    private function fakeClientInsights(): void
    {
        $this->app->instance(ClientInsightsService::class, new class extends ClientInsightsService
        {
            public function cachedSummary(): array
            {
                return [
                    'totals' => ['clients' => 4450, 'google' => 2118, 'inquired' => 1475, 'repeat' => 635, 'saved' => 508],
                    'journey_ranges' => [
                        '30d' => ['anonymous' => 9000, 'clients' => 794, 'saved' => 40, 'inquired' => 200, 'repeat' => 50],
                        '90d' => ['anonymous' => 30000, 'clients' => 2000, 'saved' => 150, 'inquired' => 600, 'repeat' => 230],
                        '12m' => ['anonymous' => 400000, 'clients' => 4446, 'saved' => 507, 'inquired' => 1474, 'repeat' => 635],
                        'all' => ['anonymous' => 426843, 'clients' => 4450, 'saved' => 508, 'inquired' => 1475, 'repeat' => 635],
                    ],
                    'inquiry_buckets' => ['1' => 840, '2–3' => 389, '4–9' => 183, '10–19' => 52, '20+' => 11],
                    'missed_reply' => [
                        'replied' => ['clients' => 524, 'inquired_again' => 210, 'came_back' => 257],
                        'no_reply' => ['clients' => 951, 'inquired_again' => 428, 'came_back' => 456],
                    ],
                    // On the page, not for Claude: the per-agent table with emails.
                    'replies' => ['agents' => [['name' => 'Maria', 'email' => 'maria@example.com']]],
                ];
            }
        });
    }

    /** What fh_traffic reads (the Audience Insights tables), as the migrations make them. */
    private function createVisitTables(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_id', 64)->nullable();
            $table->string('channel', 32)->default('direct');
            $table->string('utm_source', 128)->nullable();
            $table->string('utm_medium', 128)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('referrer', 512)->nullable();
            $table->string('landing_path', 512)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('country', 64)->nullable();
            $table->string('region', 96)->nullable();
            $table->string('city', 96)->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('visitor_id', 64)->nullable();
        });
        Schema::create('ai_events', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 8);
            $table->string('source', 32);
            $table->string('agent', 64)->nullable();
            $table->string('path', 512);
            $table->timestamp('created_at')->nullable();
        });
        Schema::table('listings', function (Blueprint $table) {
            $table->timestamp('photos_variants_generated_at')->nullable();
        });
        Schema::create('user_info', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('country', 64)->nullable();
            $table->string('state', 96)->nullable();
            $table->string('city', 96)->nullable();
        });
    }

    private function keyFor(int $userId, string $name = 'test'): string
    {
        return McpKeys::issue(User::query()->findOrFail($userId), $name)['key'];
    }

    /** One JSON-RPC request to the server, with a key (or none). */
    private function rpc(string $method, array $params = [], ?string $key = null, array $headers = []): TestResponse
    {
        // Each call is its own request: no one stays signed in from the one before.
        $this->app['auth']->forgetGuards();

        $headers += ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];

        if ($key !== null) {
            $headers['Authorization'] = 'Bearer '.$key;
        }

        return $this->postJson(self::ENDPOINT, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method] + ($params === [] ? [] : ['params' => $params]), $headers);
    }

    /**
     * Runs a tool and reads what came back.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{rpc_error: string|null, is_error: bool, text: string, data: array<string, mixed>|null}
     */
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

    /** @return list<string> the tools this key is shown, by name */
    private function toolsFor(string $key): array
    {
        $names = collect($this->rpc('tools/list', [], $key)->json('result.tools'))->pluck('name')->all();
        sort($names);

        return $names;
    }

    private function listingsOf(int $agentId): array
    {
        return DB::table('listings')->where('agent_id', $agentId)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    private function rows(): array
    {
        return array_combine($tables = ['users', 'agents', 'listings', 'properties', 'teams', 'team_agents', 'chats', 'messages'], array_map(fn (string $t): int => DB::table($t)->count(), $tables));
    }

    // ── Who is let in ───────────────────────────────────────────────────────

    public function test_every_request_needs_a_key(): void
    {
        foreach ([['initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']]], ['tools/list', []], ['tools/call', ['name' => 'fh_metric', 'arguments' => (object) []]]] as [$method, $params]) {
            $this->rpc($method, $params)
                ->assertStatus(401)
                ->assertHeader('WWW-Authenticate')
                ->assertJsonPath('error.message', 'Unauthorized');
        }
    }

    public function test_a_wrong_an_expired_and_a_revoked_key_are_refused_alike(): void
    {
        $expired = McpKeys::issue(User::query()->findOrFail($this->id['u1']), 'old');
        $expired['record']->forceFill(['expires_at' => now()->subSecond()])->save();

        $revoked = McpKeys::issue(User::query()->findOrFail($this->id['u1']), 'lost');
        McpKeys::revoke($revoked['record']);

        foreach (['fhmcp_'.str_repeat('a', 48), 'not-even-a-key', $expired['key'], $revoked['key']] as $key) {
            $this->rpc('tools/list', [], $key)
                ->assertStatus(401)
                ->assertExactJson(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Unauthorized']]);
        }

        // And a good one still works beside them.
        $this->rpc('tools/list', [], $this->a1Key)->assertOk();
    }

    public function test_a_key_is_not_a_login_token_and_a_login_token_is_not_a_key(): void
    {
        // A Sanctum token (id|secret) gets nowhere on the MCP server.
        $this->rpc('tools/list', [], '17|'.str_repeat('x', 40))->assertStatus(401);

        // And an MCP key gets nowhere on the rest of the API: the API does not know it.
        $this->getJson('/api/user/profile', ['Authorization' => 'Bearer '.$this->adminKey])->assertStatus(401);
        $this->getJson('/api/fh-one/v1/metrics', ['Authorization' => 'Bearer '.$this->adminKey])->assertStatus(401);
    }

    public function test_only_an_admin_a_team_leader_or_an_active_agent_can_have_a_key(): void
    {
        $inactive = (int) DB::table('agents')->where('id', $this->id['a5'])->value('user_id');

        foreach ([
            'a client' => $this->id['c1'],
            'a secretary' => $this->makeUser(self::SECRETARY),
            'an editor' => $this->makeUser(self::EDITOR),
            'an inactive agent' => $inactive,
        ] as $who => $userId) {
            try {
                McpKeys::issue(User::query()->findOrFail($userId), 'nope');
                $this->fail("{$who} was given a key.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('a team leader or an active agent', $e->getMessage(), $who);
            }
        }
    }

    public function test_a_key_stops_working_the_moment_its_person_may_no_longer_use_it(): void
    {
        $this->rpc('tools/list', [], $this->a1Key)->assertOk();

        // The agent's account is switched off.
        DB::table('agents')->where('id', $this->id['a1'])->update(['status' => 'terminated']);
        $this->rpc('tools/list', [], $this->a1Key)->assertStatus(403);

        // An admin is made a client.
        DB::table('users')->where('id', $this->id['admin'])->update(['role_id' => self::CLIENT]);
        $this->tool('fh_metric', ['metric' => 'listings.total'], $this->adminKey);
        $this->rpc('tools/list', [], $this->adminKey)->assertStatus(403);
    }

    public function test_wrong_keys_are_counted_per_address_and_then_more_guesses_are_cut_off_but_a_real_key_is_not(): void
    {
        config(['fhmcp.ip_failures' => 3]);

        foreach (range(1, 3) as $_) {
            $this->rpc('tools/list', [], 'fhmcp_'.str_repeat('z', 48))->assertStatus(401);
        }

        // Past the count, one more guess from that address is cut off for the rest of the minute...
        $this->rpc('tools/list', [], 'fhmcp_'.str_repeat('y', 48))->assertStatus(429)->assertHeader('Retry-After');

        // ...but a real key from the same address is not turned away: the people who use Claude arrive
        // from a few shared addresses, and someone else's guessing must not lock them out.
        $this->rpc('tools/list', [], $this->adminKey)->assertOk();
    }

    public function test_a_request_with_no_key_is_not_a_guess_and_is_never_counted_against_its_address(): void
    {
        config(['fhmcp.ip_failures' => 3]);

        // A client that has no key yet asks what it needs; so does anything that is not shaped like a key.
        foreach (range(1, 10) as $_) {
            $this->rpc('tools/list')->assertStatus(401);
            $this->rpc('tools/list', [], 'not-a-key')->assertStatus(401);
        }

        // Nothing above counted: a wrong key still gets the ordinary answer, not the cut-off.
        $this->rpc('tools/list', [], 'fhmcp_'.str_repeat('z', 48))->assertStatus(401);
    }

    public function test_a_refusal_tells_the_client_what_it_needs_in_the_gates_own_words(): void
    {
        // The package's own header middleware is off this route, so what is said here is the gate's.
        $this->rpc('tools/list')->assertStatus(401)->assertHeader('WWW-Authenticate', 'Bearer realm="mcp"');
        $this->rpc('tools/list', [], 'fhmcp_'.str_repeat('a', 48))->assertStatus(401)->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
    }

    public function test_a_request_from_a_claude_page_is_allowed_and_one_from_another_site_is_not(): void
    {
        // Claude's own servers send no Origin at all; a browser on a Claude page sends its site.
        $this->rpc('tools/list', [], $this->a1Key, ['Origin' => 'https://claude.ai'])->assertOk();
        $this->rpc('tools/list', [], $this->a1Key, ['Origin' => 'https://claude.com'])->assertOk();
        $this->rpc('tools/list', [], $this->a1Key)->assertOk();

        $this->rpc('tools/list', [], $this->a1Key, ['Origin' => 'https://evil.example'])->assertStatus(403);
    }

    public function test_the_server_offers_the_fh_mark_as_its_icon_at_the_configured_public_address(): void
    {
        config(['fhmcp.public_url' => 'https://api.example.test/']);

        $icons = $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']], $this->a1Key)
            ->assertOk()
            ->json('result.serverInfo.icons');

        $this->assertSame(['https://api.example.test/fh-mark-192.png?v=3d', 'https://api.example.test/fh-mark-512.png?v=3d'], array_column($icons, 'src'));

        // Every tool carries it too, so a tool call is drawn with FH's mark.
        foreach ($this->rpc('tools/list', [], $this->adminKey)->json('result.tools') as $tool) {
            $this->assertSame(['https://api.example.test/fh-mark-192.png?v=3d', 'https://api.example.test/fh-mark-512.png?v=3d'], array_column($tool['icons'] ?? [], 'src'), $tool['name']);
        }

        // And the files are really there to be fetched.
        foreach (['fh-mark-192.png', 'fh-mark-512.png', 'favicon.ico'] as $file) {
            $this->assertGreaterThan(1000, filesize(public_path($file)), $file);
        }
    }

    public function test_a_key_is_limited_per_minute_and_one_busy_key_does_not_slow_another(): void
    {
        config(['fhmcp.rate_limit' => 3]);

        foreach (range(1, 3) as $_) {
            $this->rpc('tools/list', [], $this->adminKey)->assertOk();
        }

        $this->rpc('tools/list', [], $this->adminKey)->assertStatus(429)->assertHeader('Retry-After');
        $this->rpc('tools/list', [], $this->a1Key)->assertOk();
    }

    public function test_with_the_open_handshake_a_client_without_a_key_can_start_but_never_call(): void
    {
        config(['fhmcp.open_handshake' => true]);

        $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'Filipino Homes');

        // It can see what exists (all eighteen), to add the connector...
        $everything = [...self::ADMIN_TOOLS, ...self::AGENT_TOOLS, ...self::TEAM_TOOLS];
        sort($everything);
        $this->assertSame($everything, collect($this->rpc('tools/list')->assertOk()->json('result.tools'))->pluck('name')->sort()->values()->all());

        // ...and run none of it.
        $this->rpc('tools/call', ['name' => 'fh_metric', 'arguments' => ['metric' => 'listings.total']])->assertStatus(401);

        // A key that is wrong is still refused, handshake or not.
        $this->rpc('initialize', [], 'fhmcp_'.str_repeat('q', 48))->assertStatus(401);

        // Someone with a key is shown their own tools, not the whole list.
        $this->assertSame(self::AGENT_TOOLS, $this->toolsFor($this->a1Key));
    }

    // ── Admin and agent are different people ────────────────────────────────

    public function test_each_role_is_shown_only_its_own_tools(): void
    {
        $this->assertSame(self::ADMIN_TOOLS, $this->toolsFor($this->adminKey));
        $this->assertSame(self::AGENT_TOOLS, $this->toolsFor($this->a1Key));
    }

    public function test_every_tool_is_read_only_and_none_is_named_or_shaped_like_a_way_to_write(): void
    {
        foreach ([$this->adminKey, $this->a1Key] as $key) {
            foreach ($this->rpc('tools/list', [], $key)->json('result.tools') as $tool) {
                $this->assertTrue($tool['annotations']['readOnlyHint'], $tool['name']);
                $this->assertFalse($tool['annotations']['destructiveHint'], $tool['name']);
                $this->assertFalse($tool['annotations']['openWorldHint'], $tool['name']);

                // Nothing that takes a statement, a command or code to run.
                $this->assertDoesNotMatchRegularExpression('/(create|update|delete|drop|truncate|insert|exec|run|sql|shell|write|remove|send|query|command)/i', $tool['name']);

                foreach (array_keys($tool['inputSchema']['properties']) as $argument) {
                    $this->assertDoesNotMatchRegularExpression('/^(sql|query|statement|command|code|script|path|file|url)$/i', $argument, $tool['name']);
                }
            }
        }
    }

    public function test_a_tool_of_the_other_role_is_not_there_to_call(): void
    {
        $before = DB::table('mcp_calls')->count();

        $agentTryingAdmin = $this->rpc('tools/call', ['name' => 'fh_metric', 'arguments' => (object) ['metric' => 'listings.total']], $this->a1Key);
        $adminTryingAgent = $this->rpc('tools/call', ['name' => 'my_metric', 'arguments' => (object) ['metric' => 'listings.total']], $this->adminKey);

        foreach ([$agentTryingAdmin, $adminTryingAgent] as $response) {
            $this->assertNotNull($response->json('error'), 'the call should have been refused outright');
            $this->assertNull($response->json('result'));
        }

        $this->assertSame($before, DB::table('mcp_calls')->count(), 'nothing ran, so nothing is on record as run');
    }

    public function test_a_request_to_delete_everything_has_nothing_to_call_and_changes_nothing(): void
    {
        $before = $this->rows();

        // What a person might say to Claude, as the tool calls it could try. None of these exist.
        foreach (['delete_all_tables', 'drop_database', 'run_sql', 'execute_query', 'delete_listing', 'delete_user', 'update_listing', 'create_listing', 'shell', 'tinker', 'artisan', 'export_all_users'] as $tool) {
            foreach ([$this->adminKey, $this->a1Key] as $key) {
                $response = $this->rpc('tools/call', ['name' => $tool, 'arguments' => (object) ['sql' => 'DROP TABLE users']], $key);

                $this->assertNotNull($response->json('error'), "{$tool} should not exist");
                $this->assertNull($response->json('result'), $tool);
            }
        }

        $this->assertSame($before, $this->rows(), 'not a row changed');
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('users'));
    }

    // ── An agent sees their own work and nothing else ───────────────────────

    public function test_an_agent_only_ever_counts_their_own_listings(): void
    {
        $this->assertSame(3, $this->tool('my_metric', ['metric' => 'listings.total'], $this->a1Key)['data']['value']);
        $this->assertSame(2, $this->tool('my_metric', ['metric' => 'listings.total'], $this->a3Key)['data']['value']);

        // The same number, asked by an admin, is the whole company's.
        $this->assertSame(10, $this->tool('fh_metric', ['metric' => 'listings.total'], $this->adminKey)['data']['value']);
    }

    public function test_an_agent_cannot_widen_their_scope_by_naming_someone_else(): void
    {
        $widen = ['metric' => 'listings.total', 'agent_id' => $this->id['a3'], 'team_id' => $this->id['bravo'], 'office_region' => 'davao', 'listing_id' => $this->id['L4']];
        $answer = $this->tool('my_metric', $widen, $this->a1Key);

        $this->assertFalse($answer['is_error'], $answer['text']);
        $this->assertSame(3, $answer['data']['value'], 'still a1\'s own listings, not a3\'s');
        $this->assertSame($this->id['a1'], $answer['data']['filters']['agent_id']);
        $this->assertSame(['agent_id'], array_keys($answer['data']['filters']), 'the extras were not even taken in');

        // A filter agents may use is passed through, over their own work.
        $answer = $this->tool('my_metric', ['metric' => 'listings.total', 'city_id' => 10], $this->a1Key);
        $this->assertSame(['agent_id' => $this->id['a1'], 'city_id' => 10], $answer['data']['filters']);
        $this->assertLessThanOrEqual(3, $answer['data']['value']);
    }

    public function test_an_agents_splits_and_records_hold_only_their_own(): void
    {
        $split = $this->tool('my_metric_breakdown', ['metric' => 'listings.total', 'by' => 'city', 'top' => 50], $this->a1Key);
        $this->assertFalse($split['is_error'], $split['text']);
        $this->assertSame(3, collect($split['data']['rows'])->sum('value'), 'the split adds up to a1\'s three, not the company\'s ten');

        $records = $this->tool('my_metric_records', ['metric' => 'listings.total', 'per_page' => 25], $this->a1Key);
        $this->assertFalse($records['is_error'], $records['text']);
        $this->assertSame(3, $records['data']['meta']['total']);

        $this->assertSame($this->listingsOf($this->id['a1']), $this->recordIds($records['data']));
    }

    public function test_an_agent_cannot_split_by_agent_or_team_nor_ask_the_companys_own_workings(): void
    {
        foreach (['agent', 'team', 'office_region'] as $by) {
            $this->assertTrue($this->tool('my_metric_breakdown', ['metric' => 'listings.total', 'by' => $by], $this->a1Key)['is_error'], "by {$by}");
        }

        foreach (['agents.headcount', 'agents.dormant_30d', 'usage.total_users', 'teams.total', 'leads.to_idle_agents', 'no.such_metric'] as $metric) {
            $answer = $this->tool('my_metric', ['metric' => $metric], $this->a1Key);
            $this->assertTrue($answer['is_error'], $metric);
            $this->assertNull($answer['data'], $metric);
        }

        // Lookups: places, projects and types yes; teams no.
        $this->assertFalse($this->tool('my_lookup', ['type' => 'cities', 'text' => 'cebu'], $this->a1Key)['is_error']);
        $this->assertTrue($this->tool('my_lookup', ['type' => 'teams', 'text' => 'alpha'], $this->a1Key)['is_error']);

        // And their catalog does not so much as list them.
        $listed = collect($this->tool('my_metrics_catalog', [], $this->a1Key)['data']['metrics'])->pluck('id');
        $this->assertTrue($listed->contains('listings.live'));
        $this->assertFalse($listed->contains('agents.headcount'));
        $this->assertFalse($listed->contains('teams.total'));
        $this->assertFalse($listed->contains('usage.total_users'));
    }

    public function test_an_admin_sees_the_whole_company(): void
    {
        $split = $this->tool('fh_metric_breakdown', ['metric' => 'listings.total', 'by' => 'agent', 'top' => 50], $this->adminKey);
        $this->assertFalse($split['is_error'], $split['text']);
        $this->assertSame(10, collect($split['data']['rows'])->sum('value'));
        $this->assertGreaterThan(2, count($split['data']['rows']));

        $records = $this->tool('fh_metric_records', ['metric' => 'listings.total', 'per_page' => 25], $this->adminKey);
        $this->assertSame(10, $records['data']['meta']['total']);

        $team = $this->tool('fh_lookup', ['type' => 'teams', 'text' => 'alp'], $this->adminKey);
        $this->assertFalse($team['is_error'], $team['text']);
        $this->assertSame('Alpha', $team['data']['data'][0]['label'] ?? $team['data']['data'][0]['name'] ?? null);

        $catalog = collect($this->tool('fh_metrics_catalog', [], $this->adminKey)['data']['metrics'])->pluck('id');
        $this->assertTrue($catalog->contains('agents.headcount'));
        $this->assertTrue($catalog->contains('teams.total'));
    }

    public function test_an_admin_sees_website_traffic_by_source_as_audience_insights_counts_it(): void
    {
        $client = $this->makeUser(self::CLIENT, ['visitor_id' => 'dev-c1', 'created_at' => '2026-11-04 02:00:00', 'updated_at' => '2026-11-04 02:00:00']);

        foreach ([
            ['dev-g1', 'google', null, null, '2026-11-02 03:00:00', 'PH', 'Cebu City'],
            ['dev-g1', 'google', null, null, '2026-11-05 03:00:00', 'PH', 'Cebu City'],
            ['dev-g2', 'google', null, null, '2026-11-05 04:00:00', 'PH', 'Davao City'],
            ['dev-f1', 'facebook', null, null, '2026-11-03 05:00:00', 'PH', 'Cebu City'],
            ['dev-f1', 'google', null, null, '2026-11-06 05:00:00', 'PH', 'Cebu City'],
            ['dev-r1', 'referral', null, 'https://www.lamudi.com.ph/listing/1', '2026-11-07 06:00:00', 'SG', 'Singapore'],
            ['dev-c1', 'facebook', $client, null, '2026-11-04 02:00:00', 'PH', 'Manila'],
            // Staff browsing is not audience, and a day outside the range is not counted.
            ['dev-admin', 'google', $this->id['admin'], null, '2026-11-05 07:00:00', 'PH', 'Manila'],
            ['dev-old', 'direct', null, null, '2026-09-01 07:00:00', 'PH', 'Manila'],
        ] as [$device, $channel, $user, $referrer, $at, $country, $city]) {
            DB::table('visits')->insert(['visitor_id' => $device, 'channel' => $channel, 'user_id' => $user, 'referrer' => $referrer, 'created_at' => $at, 'country' => $country, 'city' => $city]);
        }

        $traffic = $this->tool('fh_traffic', ['geography' => true], $this->adminKey);
        $this->assertFalse($traffic['is_error'], $traffic['text']);
        $data = $traffic['data'];

        $this->assertSame(['from' => '2026-10-12', 'to' => '2026-11-10'], $data['days']);
        $this->assertSame(5, $data['unique_visitors']);
        $this->assertSame(4, $data['anonymous_visitors']);
        $this->assertSame(1, $data['visitors_from_more_than_one_source']);

        $sources = collect($data['sources'])->keyBy('source');
        // Biggest first, nothing from staff or old days; "unknown" holds the fixture's other clients, who have no visit.
        $this->assertSame(['google', 'facebook', 'referral', 'unknown'], $sources->keys()->all());
        $this->assertSame(0, $sources['unknown']['people']);
        $this->assertSame(['source' => 'google', 'people' => 3, 'visits' => 4, 'first_source_of' => 2, 'new_clients' => 0, 'returning_clients' => 0], $sources['google']);
        $this->assertSame(['source' => 'facebook', 'people' => 2, 'visits' => 2, 'first_source_of' => 2, 'new_clients' => 1, 'returning_clients' => 0], $sources['facebook']);
        $this->assertSame(5, $sources->sum('first_source_of'), 'first sources add up to the unique visitors');

        $this->assertSame([['name' => 'lamudi.com.ph', 'kind' => 'site', 'people' => 1, 'visits' => 1]], $data['referral_sites']);
        $this->assertSame('PH', $data['geography']['countries'][0]['name']);

        // The day-by-day trend (the Audience Insights charts), only when asked.
        $this->assertArrayNotHasKey('daily', $data);
        $daily = $this->tool('fh_traffic', ['daily' => true], $this->adminKey)['data']['daily'];
        $visitorsOn = array_combine($daily['totals']['dates'], $daily['totals']['visitors']);
        $this->assertSame(2, $visitorsOn['2026-11-05'], 'g1 and g2; the admin\'s own browsing is not counted');
        $this->assertSame(7, array_sum($daily['totals']['visitors']));
        $this->assertSame(['google', 'facebook', 'referral'], array_keys($daily['anonymous_visitors_by_source']['sources']));
        $this->assertSame(4, array_sum($daily['anonymous_visitors_by_source']['sources']['google']));
        $this->assertSame(1, array_sum($daily['anonymous_visitors_by_source']['sources']['facebook']), 'the client\'s visit is not anonymous');

        // The page's numbers and Claude's are the same.
        $page = app(TrafficSourceService::class)->range('2026-10-12', '2026-11-10')->channels();
        $this->assertSame($page['unique_visitors'], $data['unique_visitors']);

        // The catalog points to it, for an admin only.
        $this->assertStringContainsString('fh_traffic', (string) $this->tool('fh_metrics_catalog', ['search' => 'traffic'], $this->adminKey)['data']['website_traffic']);
        $this->assertArrayNotHasKey('website_traffic', $this->tool('my_metrics_catalog', [], $this->a1Key)['data']);

        // An agent has no traffic tool to call.
        $this->assertNotNull($this->rpc('tools/call', ['name' => 'fh_traffic', 'arguments' => (object) []], $this->a1Key)->json('error'));
    }

    public function test_an_admin_sees_ai_visibility_as_the_ai_visibility_page_counts_it(): void
    {
        $listing = DB::table('listings')->orderBy('id')->first(['slug', 'name']);
        $page = '/'.$listing->slug;

        foreach ([
            ['view', 'chatgpt', 'ChatGPT-User', $page, '2026-11-05 03:00:00'],
            ['view', 'chatgpt', 'ChatGPT-User', $page, '2026-11-06 03:00:00'],
            ['crawl', 'perplexity', 'PerplexityBot', $page, '2026-11-06 04:00:00'],
            ['click', 'gemini', null, '/projects', '2026-11-07 05:00:00'],
            // A scanner posing as an AI bot, and a day outside the range: neither is counted.
            ['view', 'chatgpt', 'ChatGPT-User', '/.env', '2026-11-07 06:00:00'],
            ['view', 'chatgpt', 'ChatGPT-User', $page, '2026-09-01 06:00:00'],
        ] as [$kind, $source, $agent, $path, $at]) {
            DB::table('ai_events')->insert(['kind' => $kind, 'source' => $source, 'agent' => $agent, 'path' => $path, 'created_at' => $at]);
        }

        $ai = $this->tool('fh_ai_visibility', [], $this->adminKey);
        $this->assertFalse($ai['is_error'], $ai['text']);
        $data = $ai['data'];

        $this->assertSame(['from' => '2026-10-12', 'to' => '2026-11-10'], $data['days']);
        $this->assertSame(['view' => 2, 'crawl' => 1, 'click' => 1], $data['totals']);
        $this->assertSame(1, $data['scanner_requests_left_out']);
        $this->assertSame(['source' => 'chatgpt', 'view' => 2, 'crawl' => 0, 'click' => 0], collect($data['assistants'])->firstWhere('source', 'chatgpt'));
        $this->assertSame(['page' => $page, 'listing' => $listing->name, 'views' => 2, 'clicks' => 0, 'crawls' => 1], $data['top_pages'][0]);

        // The quick two-scan count Claude gets is the AI Visibility page's report, number for number.
        $service = app(AiVisibilityService::class);
        [$from, $to] = [Carbon::parse('2026-10-12')->startOfDay(), Carbon::parse('2026-11-10')->endOfDay()];
        $quick = $service->overview($from, $to, 50);
        $full = $service->report($from, $to, 1, 50);
        $this->assertSame($full['totals'], $quick['totals']);
        $this->assertSame($full['sources'], $quick['sources']);
        $this->assertSame($full['suspicious_total'], $quick['suspicious_total']);
        $this->assertSame($full['top_pages_meta']['total'], $quick['pages_seen']);
        $this->assertSame(array_column($full['top_pages'], 'views', 'path'), array_column($quick['top_pages'], 'views', 'path'));

        $this->assertArrayNotHasKey('daily', $data);
        $this->assertSame([
            'dates' => ['2026-11-05', '2026-11-06', '2026-11-07'],
            'views' => [1, 1, 0],
            'crawls' => [0, 1, 0],
            'clicks' => [0, 0, 1],
        ], array_diff_key($this->tool('fh_ai_visibility', ['daily' => true], $this->adminKey)['data']['daily'], ['how_counted' => 1]), 'the scanner and the old day are left out');

        $byClicks = $this->tool('fh_ai_visibility', ['sort' => 'clicks', 'top' => 1], $this->adminKey)['data'];
        $this->assertSame(['/projects'], array_column($byClicks['top_pages'], 'page'));

        $this->assertNotNull($this->rpc('tools/call', ['name' => 'fh_ai_visibility', 'arguments' => (object) []], $this->a1Key)->json('error'), 'an agent has no AI visibility tool to call');
    }

    public function test_an_admin_reads_the_audit_lens_and_an_agent_only_their_own(): void
    {
        $value = fn (string $metric, string $key, array $more = []): int => (int) $this->tool($key === $this->adminKey ? 'fh_metric' : 'my_metric', ['metric' => $metric, ...$more], $key)['data']['value'];

        // Verification: L1 fully verified, L3 verified, L8 flagged, L9 waiting for re-review; the other six none.
        $this->assertSame(2, $value('audit.verified', $this->adminKey));
        $this->assertSame(1, $value('audit.fully_verified', $this->adminKey));
        $this->assertSame(1, $value('audit.flagged', $this->adminKey));
        $this->assertSame(1, $value('audit.pending_review', $this->adminKey));
        $this->assertSame(6, $value('audit.unverified', $this->adminKey));
        $this->assertSame(2, $value('audit.audited', $this->adminKey));
        $this->assertSame(8, $value('audit.not_audited', $this->adminKey));
        $this->assertSame(1, $value('audit.audited_in_range', $this->adminKey), 'L1 in the last 30 days; L3 was audited before them');

        // ATS counts only where one was uploaded: the default "approve" on the other five is not an ATS.
        $this->assertSame(2, $value('ats.approved', $this->adminKey));
        $this->assertSame(1, $value('ats.pending', $this->adminKey));
        $this->assertSame(1, $value('ats.rejected', $this->adminKey));
        $this->assertSame(1, $value('ats.expired', $this->adminKey));
        $this->assertSame(5, $value('ats.none', $this->adminKey));
        $this->assertSame(1, $value('ats.expiring_30d', $this->adminKey), 'L1 ends on Nov 20, inside 30 days of Nov 10');
        $this->assertSame(1, $value('ats.past_expiry', $this->adminKey), 'L3 ended Nov 1 and is still "approved"');

        // Split by agent, and the listings behind a number.
        $split = $this->tool('fh_metric_breakdown', ['metric' => 'ats.approved', 'by' => 'agent'], $this->adminKey)['data']['rows'];
        $this->assertSame(2, collect($split)->sum('value'));
        $records = $this->tool('fh_metric_records', ['metric' => 'ats.expiring_30d'], $this->adminKey)['data'];
        $this->assertSame(1, $records['meta']['total']);

        // An agent sees the same lens on their own listings only: a1 has L1 (fully verified) and L9 (re-review).
        $this->assertSame(1, $value('audit.verified', $this->a1Key));
        $this->assertSame(1, $value('audit.pending_review', $this->a1Key));
        $this->assertSame(0, $value('ats.past_expiry', $this->a1Key), 'L3 is a2\'s');
    }

    public function test_an_admin_reads_the_client_journey_repeat_inquiries_and_what_a_missed_reply_costs(): void
    {
        $answer = $this->tool('fh_clients', [], $this->adminKey);
        $this->assertFalse($answer['is_error'], $answer['text']);
        $data = $answer['data'];

        $this->assertSame(['clients' => 4450, 'inquired' => 1475, 'inquired_pct' => 33.1, 'never_inquired' => 2975, 'never_inquired_pct' => 66.9], $data['did_they_inquire']);
        $this->assertSame(['clients' => 635, 'pct_of_clients_who_inquired' => 43.1], $data['inquired_more_than_once']);
        $this->assertSame(['1' => 840, '2–3' => 389, '4–9' => 183, '10–19' => 52, '20+' => 11], $data['inquiries_per_client']);

        $all = $data['client_journey']['all_time'];
        $this->assertSame([426843, 4450, 1, 508, 11.4, 1475, 33.1, 635, 43.1], array_values($all));
        $this->assertSame(['last_30_days', 'last_90_days', 'last_12_months', 'all_time'], array_keys($data['client_journey']));

        $this->assertSame(['clients' => 524, 'inquired_again' => 210, 'inquired_again_pct' => 40.1, 'came_back_to_the_site' => 257, 'came_back_pct' => 49], $data['missed_reply_cost']['got_a_reply']);
        $this->assertSame(951, $data['missed_reply_cost']['no_reply']['clients']);

        // Only counts: the page's per-agent table (names, emails) never reaches Claude.
        $this->assertStringNotContainsString('Maria', $answer['text']);
        $this->assertStringNotContainsString('example.com', $answer['text']);

        // The catalog points to it; an agent has no such tool.
        $this->assertStringContainsString('fh_clients', (string) $this->tool('fh_metrics_catalog', [], $this->adminKey)['data']['website_traffic']);
        $this->assertNotNull($this->rpc('tools/call', ['name' => 'fh_clients', 'arguments' => (object) []], $this->a1Key)->json('error'));
    }

    // ── Nothing it hands out carries a person's contact details ─────────────

    public function test_no_tool_returns_an_email_address_to_anyone(): void
    {
        $asks = [
            [$this->adminKey, 'fh_metrics_catalog', []],
            [$this->adminKey, 'fh_metric', ['metric' => 'inquiries.received', 'compare' => true]],
            [$this->adminKey, 'fh_metric_breakdown', ['metric' => 'deals.sold', 'by' => 'agent']],
            [$this->adminKey, 'fh_metric_records', ['metric' => 'listings.total']],
            [$this->adminKey, 'fh_metric_records', ['metric' => 'inquiries.received']],
            [$this->adminKey, 'fh_metric_records', ['metric' => 'agents.headcount']],
            [$this->adminKey, 'fh_lookup', ['type' => 'teams', 'text' => 'al']],
            [$this->adminKey, 'fh_traffic', ['geography' => true]],
            [$this->adminKey, 'fh_ai_visibility', []],
            [$this->adminKey, 'fh_clients', []],
            [$this->a1Key, 'my_metrics_catalog', []],
            [$this->a1Key, 'my_metric', ['metric' => 'inquiries.received']],
            [$this->a1Key, 'my_metric_breakdown', ['metric' => 'listings.total', 'by' => 'project']],
            [$this->a1Key, 'my_metric_records', ['metric' => 'listings.total']],
            [$this->a1Key, 'my_metric_records', ['metric' => 'inquiries.received']],
            [$this->a1Key, 'my_lookup', ['type' => 'cities', 'text' => 'ce']],
        ];

        foreach ($asks as [$key, $tool, $arguments]) {
            $answer = $this->tool($tool, $arguments, $key);

            $this->assertNull($answer['rpc_error'], "{$tool}: {$answer['text']}");
            $this->assertDoesNotMatchRegularExpression('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $answer['text'], "{$tool} let an email address out");
            $this->assertStringNotContainsString('example.com', $answer['text'], $tool);
        }
    }

    public function test_the_last_lock_on_the_door_drops_contact_fields_and_blanks_contact_details_in_text(): void
    {
        $clean = McpOutput::clean([
            'listing' => 'Mantra',
            'email' => 'a@b.co',
            'agent' => ['name' => 'Juan', 'mobile_no' => '0917 123 4567', 'Phone' => '123', 'whatsapp_number' => '1', 'password' => 'x', 'api_token' => 'y', 'birthdate' => '2000-01-01'],
            'note' => 'Call juan@example.com or +63 917 123 4567 or 09171234567.',
            'price' => 12500000000,
            'text_price' => '5,000,000.00',
            'sqm' => '100.5',
            'rows' => [['id' => 7, 'contact_email' => 'x@y.zz', 'title' => 'Lot 9171234567']],
        ]);

        $this->assertSame([
            'listing' => 'Mantra',
            'agent' => ['name' => 'Juan'],
            'note' => 'Call [email removed] or [number removed] or [number removed].',
            'price' => 12500000000,
            'text_price' => '5,000,000.00',
            'sqm' => '100.5',
            'rows' => [['id' => 7, 'title' => 'Lot [number removed]']],
        ], $clean);
    }

    public function test_an_answer_that_is_too_big_is_refused_with_advice_instead_of_sent(): void
    {
        config(['fhmcp.max_result_bytes' => 400]);

        $answer = $this->tool('fh_metrics_catalog', [], $this->adminKey);

        $this->assertTrue($answer['is_error']);
        $this->assertStringContainsString('too big', $answer['text']);

        $this->expectException(RuntimeException::class);
        McpOutput::json(['x' => str_repeat('a', 500)]);
    }

    // ── What a call can and cannot do ───────────────────────────────────────

    public function test_calling_every_tool_writes_nothing_but_the_audit_and_the_keys_last_used_stamp(): void
    {
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $before = $this->rows();

        foreach ([
            [$this->adminKey, 'fh_metrics_catalog', []],
            [$this->adminKey, 'fh_metric', ['metric' => 'deals.sold', 'compare' => true]],
            [$this->adminKey, 'fh_metric_breakdown', ['metric' => 'listings.live', 'by' => 'team']],
            [$this->adminKey, 'fh_metric_records', ['metric' => 'inquiries.received']],
            [$this->adminKey, 'fh_lookup', ['type' => 'provinces', 'text' => 'ce']],
            [$this->adminKey, 'fh_traffic', ['geography' => true]],
            [$this->adminKey, 'fh_ai_visibility', []],
            [$this->adminKey, 'fh_clients', []],
            [$this->a1Key, 'my_metrics_catalog', ['search' => 'sold']],
            [$this->a1Key, 'my_metric', ['metric' => 'listings.live']],
            [$this->a1Key, 'my_metric_breakdown', ['metric' => 'listings.total', 'by' => 'category']],
            [$this->a1Key, 'my_metric_records', ['metric' => 'listings.total']],
            [$this->a1Key, 'my_lookup', ['type' => 'types', 'text' => 'ho']],
        ] as [$key, $tool, $arguments]) {
            $answer = $this->tool($tool, $arguments, $key);
            $this->assertNull($answer['rpc_error'], "{$tool}: {$answer['text']}");
            $this->assertFalse($answer['is_error'], "{$tool}: {$answer['text']}");
        }

        $this->assertNotEmpty($writes, 'the audit is written');

        foreach ($writes as $sql) {
            $this->assertMatchesRegularExpression('/\b(mcp_calls|mcp_keys)\b/', $sql, 'a call wrote somewhere it should not: '.$sql);
        }

        $this->assertSame($before, $this->rows());
    }

    public function test_the_tools_contain_no_code_that_writes_or_runs_anything(): void
    {
        // The only MCP code that may write is the audit trail and the key store. Everything else is read-only code.
        $writers = ['McpAudit.php', 'McpKeys.php'];
        $forbidden = '/(DB::|->save\(|->update\(|->delete\(|->insert|->create\(|->forceDelete|->truncate|->upsert|->increment|->decrement|Schema::|Artisan::|Process::|Http::|Mail::|Notification::|Storage::|Queue::|dispatch\(|shell_exec|\bexec\(|\bsystem\(|passthru|proc_open|popen|file_put_contents|unlink\(|eval\(|->raw\(|selectRaw|whereRaw|unprepared|statement\()/';

        $scanned = 0;

        foreach ((new Finder)->files()->in(app_path('Mcp'))->name('*.php') as $file) {
            if (in_array($file->getFilename(), $writers, true)) {
                continue;
            }

            $scanned++;
            $this->assertDoesNotMatchRegularExpression($forbidden, $file->getContents(), $file->getRelativePathname().' has code that can write, run or reach out');
        }

        $this->assertGreaterThan(15, $scanned, 'the scan found the MCP code');
    }

    public function test_no_tool_refers_to_the_sign_in_code(): void
    {
        // Signing Claude in writes (tokens, requests); it lives outside the read-only code, and no tool may reach for it.
        $scanned = 0;

        foreach ((new Finder)->files()->in(app_path('Mcp/Tools'))->name('*.php') as $file) {
            $scanned++;
            $this->assertDoesNotMatchRegularExpression('/McpOAuth|McpConnection|OAuthTokens|OAuthSwitch|OAuthAuthorizations|McpOauth/', $file->getContents(), $file->getRelativePathname());
        }

        $this->assertGreaterThan(15, $scanned);
    }

    public function test_the_server_lists_only_tools_built_on_the_read_only_base_and_the_three_policies(): void
    {
        $tools = (new ReflectionClass(FilipinoHomesServer::class))->getDefaultProperties()['tools'];

        $this->assertCount(18, $tools);

        foreach ($tools as $class) {
            $this->assertTrue(is_subclass_of($class, FhTool::class), "{$class} does not extend FhTool, so it would not be read-only by construction");
        }

        $this->assertSame(McpRole::Admin, (new AdminPolicy)->role());
        $this->assertSame(McpRole::Agent, (new AgentPolicy)->role());
        $this->assertSame(McpRole::TeamLeader, (new TeamLeaderPolicy)->role());
    }

    public function test_a_forced_filter_beats_the_callers_own_even_if_a_policy_let_them_name_it(): void
    {
        // The second lock: today no policy lets an agent name agent_id, but if one ever did, what is forced still wins.
        $policy = new class implements McpPolicy
        {
            public function role(): McpRole
            {
                return McpRole::Agent;
            }

            public function allowsMetric(string $metric): bool
            {
                return true;
            }

            public function filters(): array
            {
                return ['agent_id', 'city_id'];
            }

            public function forced(McpActor $actor): array
            {
                return ['agent_id' => 5];
            }

            public function constrain(array $filters, McpActor $actor): array
            {
                return $filters;
            }

            public function dimensions(): array
            {
                return [];
            }

            public function lookups(): array
            {
                return [];
            }
        };

        $actor = new McpActor(User::query()->findOrFail($this->id['u1']), McpRole::Agent, null, 5);

        $this->assertSame(['agent_id' => 5, 'city_id' => 10], Asks::filters(['agent_id' => 99, 'city_id' => '10', 'team_id' => 3], $policy, $actor));
    }

    public function test_an_agent_policy_with_no_agent_row_refuses_instead_of_widening(): void
    {
        $user = User::query()->findOrFail($this->id['u1']);

        $this->expectException(\LogicException::class);
        (new AgentPolicy)->forced(new McpActor($user, McpRole::Agent, null, null));
    }

    public function test_arguments_are_validated_and_a_mistake_is_told_plainly(): void
    {
        foreach ([
            ['fh_metric', []],
            ['fh_metric', ['metric' => 'listings.total', 'date_start' => 'yesterday']],
            ['fh_metric', ['metric' => 'listings.total', 'date_start' => '2020-01-01', 'date_end' => '2026-11-10']],
            ['fh_metric', ['metric' => 'no.such.metric']],
            ['fh_metric_breakdown', ['metric' => 'deals.sold', 'by' => 'astrology']],
            ['fh_metric_records', ['metric' => 'listings.total', 'per_page' => 500]],
            ['fh_metric_records', ['metric' => 'listings.total', 'page' => 0]],
            ['fh_lookup', ['type' => 'teams', 'text' => 'a']],
            ['fh_lookup', ['type' => 'users', 'text' => 'smith']],
            ['fh_metric', ['metric' => 'listings.total', 'office_region' => 'atlantis']],
        ] as [$tool, $arguments]) {
            $answer = $this->tool($tool, $arguments, $this->adminKey);

            $this->assertTrue($answer['is_error'], $tool.' '.json_encode($arguments));
            $this->assertNotSame('', $answer['text']);
            $this->assertStringNotContainsString('Exception', $answer['text']);
            $this->assertStringNotContainsString('/app/', $answer['text'], 'no file paths');
        }
    }

    // ── What is on record ───────────────────────────────────────────────────

    public function test_every_tool_call_is_on_record_with_who_and_how_it_went_and_nothing_else_is(): void
    {
        $this->rpc('initialize', [], $this->a1Key);
        $this->rpc('tools/list', [], $this->a1Key);
        $this->assertSame(0, DB::table('mcp_calls')->count(), 'the handshake and listing tools are not tool calls');

        $this->tool('my_metric', ['metric' => 'listings.total', 'city_id' => 10], $this->a1Key);
        $this->tool('my_metric', ['metric' => 'teams.total'], $this->a1Key);
        $this->tool('fh_metric', ['metric' => 'listings.total'], $this->adminKey);

        $rows = DB::table('mcp_calls')->orderBy('id')->get();

        $this->assertCount(3, $rows);
        $this->assertSame(['ok', 'error', 'ok'], $rows->pluck('outcome')->all());
        $this->assertSame(['agent', 'agent', 'admin'], $rows->pluck('role')->all());
        $this->assertSame(['my_metric', 'my_metric', 'fh_metric'], $rows->pluck('tool')->all());
        $this->assertSame([$this->id['u1'], $this->id['u1'], $this->id['admin']], $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['metric' => 'listings.total', 'city_id' => 10], json_decode($rows[0]->arguments, true));
        $this->assertStringContainsString('not available', (string) $rows[1]->error);
        $this->assertGreaterThan(0, (int) $rows[0]->bytes);
        $this->assertNotNull($rows[0]->mcp_key_id);
    }

    public function test_what_was_asked_is_kept_without_secrets_or_enormous_text(): void
    {
        $this->tool('fh_lookup', ['type' => 'teams', 'text' => 'juan@example.com'], $this->adminKey);

        $kept = (string) DB::table('mcp_calls')->value('arguments');

        $this->assertStringNotContainsString('juan@example.com', $kept);
        $this->assertStringContainsString('[email removed]', $kept);
    }

    public function test_a_key_is_stored_only_as_a_hash_and_works_until_it_is_revoked(): void
    {
        $issued = McpKeys::issue(User::query()->findOrFail($this->id['u1']), 'laptop');
        $key = $issued['key'];

        $this->assertStringStartsWith('fhmcp_', $key);
        $this->assertSame(54, strlen($key));

        $stored = (array) DB::table('mcp_keys')->where('id', $issued['record']->id)->first();
        $this->assertSame(hash('sha256', $key), $stored['token_hash']);
        $this->assertSame(substr($key, 0, 12), $stored['prefix']);
        $this->assertStringNotContainsString($key, json_encode($stored), 'the key itself is nowhere in the table');

        $this->rpc('tools/list', [], $key)->assertOk();
        $this->assertNotNull(McpKey::query()->find($issued['record']->id)->last_used_at);

        McpKeys::revoke($issued['record']);
        $this->rpc('tools/list', [], $key)->assertStatus(401);
    }

    public function test_a_person_may_hold_only_a_few_keys_and_for_a_limited_time(): void
    {
        $user = User::query()->findOrFail($this->id['u3']);

        // a3 already holds one from setUp; two more fill the three allowed.
        McpKeys::issue($user, 'second');
        McpKeys::issue($user, 'third');

        foreach ([['fourth', null], ['too long', 400], ['too short', 0], ['', 30]] as [$name, $days]) {
            try {
                McpKeys::issue($user, $name, $days);
                $this->fail("a key was made for: {$name} / {$days}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── The commands that hand keys out ─────────────────────────────────────

    public function test_the_commands_make_list_and_revoke_keys_and_show_a_key_once(): void
    {
        $this->assertSame(0, Artisan::call('fhmcp:make-key', ['email' => 'boss@example.com', '--name' => 'from the server', '--days' => 7]));
        $made = Artisan::output();

        $this->assertStringContainsString('acting as admin', $made);
        $this->assertSame(1, preg_match('/fhmcp_[A-Za-z0-9]{48}/', $made, $found), 'the key is shown');
        $key = $found[0];

        // It is the key the server accepts, and the list never shows more of it than its first characters.
        $this->rpc('tools/list', [], $key)->assertOk();
        $this->assertSame(0, Artisan::call('fhmcp:keys', ['--user' => 'boss@example.com']));
        $listed = Artisan::output();
        $this->assertStringContainsString('from the server', $listed);
        $this->assertStringContainsString(substr($key, 0, 12).'…', $listed);
        $this->assertStringNotContainsString($key, $listed);

        $record = McpKey::query()->where('name', 'from the server')->firstOrFail();
        $this->assertSame(0, Artisan::call('fhmcp:revoke-key', ['id' => $record->id]));
        $this->assertNotNull($record->fresh()->revoked_at);
        $this->rpc('tools/list', [], $key)->assertStatus(401);

        // A person who can't have a key is told so, and none is made.
        $before = McpKey::query()->count();
        $this->assertSame(1, Artisan::call('fhmcp:make-key', ['email' => (string) DB::table('users')->where('id', $this->id['c1'])->value('email')]));
        $this->assertSame(1, Artisan::call('fhmcp:make-key', ['email' => 'nobody@nowhere.test']));
        $this->assertSame($before, McpKey::query()->count());

        // Revoking by person cuts every key of theirs.
        $this->assertSame(0, Artisan::call('fhmcp:revoke-key', ['--user' => 'boss@example.com']));
        $this->rpc('tools/list', [], $this->adminKey)->assertStatus(401);

        // Neither a number nor a person: nothing is revoked.
        $this->assertSame(1, Artisan::call('fhmcp:revoke-key'));
    }

    public function test_a_command_can_make_a_permanent_key_but_not_one_that_is_also_for_days(): void
    {
        $this->assertSame(0, Artisan::call('fhmcp:make-key', ['email' => 'boss@example.com', '--name' => 'forever', '--permanent' => true]));
        $this->assertStringContainsString('no end date', Artisan::output());
        $this->assertNull(McpKey::query()->where('name', 'forever')->firstOrFail()->expires_at);

        $this->assertSame(0, Artisan::call('fhmcp:keys', ['--user' => 'boss@example.com']));
        $this->assertStringContainsString('never (permanent)', Artisan::output());

        $before = McpKey::query()->count();
        $this->assertSame(1, Artisan::call('fhmcp:make-key', ['email' => 'boss@example.com', '--days' => 5, '--permanent' => true]));
        $this->assertStringContainsString('not both', Artisan::output());
        $this->assertSame($before, McpKey::query()->count());
    }

    // ── Helpers for the records ─────────────────────────────────────────────

    /** The ids of the listings in a records answer. */
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
}
