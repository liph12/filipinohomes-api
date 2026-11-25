<?php

namespace Tests\Feature;

use App\Mcp\Support\McpRole;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\McpOAuth\OAuthClients;
use App\Services\McpOAuth\OAuthSwitch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/**
 * ChatGPT as a second AI that people sign in (docs/mcp.md, "ChatGPT"). The test plays ChatGPT's part (registers with
 * its return address, sends the browser to /oauth/authorize, trades the code) and the person's part, as
 * FhMcpOAuthTest does for Claude, and checks the three things that are new: ChatGPT's return addresses and nothing
 * that looks like them, ChatGPT's own switch (it starts off and can only narrow the master one), and the issuer
 * (RFC 9207) that ChatGPT needs to use its one stable return address, which is only ever added while ChatGPT is open
 * so that nothing Claude is sent changes before then.
 */
class ChatGptSignInTest extends TestCase
{
    use CreatesExecutiveReportTables;

    private const API = 'https://api.example.test';

    private const SERVER = 'https://api.example.test/mcp/filipinohomes';

    private const CONSENT = 'https://site.example.test/connect/claude';

    private const CLAUDE = 'https://claude.ai/api/mcp/auth_callback';

    private const STABLE = 'https://chatgpt.com/connector_platform_oauth_redirect';

    private const PER_CONNECTION = 'https://chatgpt.com/connector/oauth/Zx9_a-B.c~d1';

    /** @var array<string, int> */
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('x');

        // 2 PM in Manila, written in UTC (see FhMcpOAuthTest).
        Carbon::setTestNow(Carbon::parse('2026-11-10 06:00', 'UTC'));
        $this->createExecutiveReportTables();

        foreach (['2026_11_24_000001_create_mcp_keys_and_calls_tables.php', '2026_11_24_000002_add_who_to_mcp_keys_table.php', '2026_11_24_000003_let_mcp_keys_be_permanent.php', '2026_11_24_000004_create_mcp_oauth_tables.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }

        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('announcement_id')->nullable();
            $table->string('type', 32);
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('expo_token');
            $table->timestamps();
        });

        $this->id = $this->seedExecutiveScenario();
        DB::table('team_agents')->where('agent_id', $this->id['a1'])->update(['is_leader' => false]);
        $this->id['admin'] = $this->makeUser(self::ADMIN, ['name' => 'The Boss', 'email' => 'boss@example.com']);

        config([
            'fhmcp.public_url' => self::API,
            'fhmcp.consent_url' => self::CONSENT,
            'fhmcp.rate_limit' => 100000,
        ]);

        // Claude is open to everyone (the master switch); ChatGPT has not been opened, which is how it starts.
        OAuthSwitch::set(OAuthSwitch::ON);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── The AI's part ───────────────────────────────────────────────────────

    private function verifier(): string
    {
        return Str::random(64);
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** The browser arriving at /oauth/authorize. */
    private function authorize(string $verifier, string $client = 'chatgpt', string $redirect = self::STABLE, array $override = []): TestResponse
    {
        $query = array_merge([
            'response_type' => 'code',
            'client_id' => $client,
            'redirect_uri' => $redirect,
            'code_challenge' => $this->challenge($verifier),
            'code_challenge_method' => 'S256',
            'state' => 'a-state-of-43-characters-0123456789ABCDEFGHI',
            'resource' => self::SERVER,
            'scope' => 'fh:read',
        ], $override);

        return $this->get('/oauth/authorize?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    private function handleOf(TestResponse $response): string
    {
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(self::CONSENT.'?request=', $location);

        return substr($location, strlen(self::CONSENT.'?request='));
    }

    /** The parameters of the address the browser is sent back to. */
    private function parts(string $redirect): array
    {
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        return $query;
    }

    private function as(int $userId): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::query()->findOrFail($userId), 'sanctum');
    }

    /** The person says yes. Returns where the browser goes. */
    private function approve(string $handle, int $userId): string
    {
        $this->as($userId);

        return (string) $this->postJson('/api/mcp/connect/requests/'.$handle.'/approve', ['account_id' => $userId])->assertOk()->json('redirect_to');
    }

    private function open(string $mode = OAuthSwitch::ON): void
    {
        OAuthSwitch::setProvider(OAuthClients::PROVIDER_CHATGPT, $mode);
    }

    /**
     * The whole sign-in, start to tokens.
     *
     * @return array{tokens: array<string, mixed>, back: array<string, mixed>, redirect: string}
     */
    private function connect(int $userId, string $client = 'chatgpt', string $redirect = self::STABLE): array
    {
        $verifier = $this->verifier();
        $handle = $this->handleOf($this->authorize($verifier, $client, $redirect)->assertStatus(302));
        $sentBack = $this->approve($handle, $userId);
        $back = $this->parts($sentBack);
        $this->assertArrayHasKey('code', $back);

        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $back['code'],
            'redirect_uri' => $redirect,
            'client_id' => $client,
            'code_verifier' => $verifier,
            'resource' => self::SERVER,
        ])->assertOk()->json();

        return ['tokens' => $tokens, 'back' => $back, 'redirect' => $sentBack];
    }

    private function mcp(string $method, ?string $bearer, array $params = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $headers += ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];

        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        return $this->postJson('/mcp/filipinohomes', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method] + ($params === [] ? [] : ['params' => $params]), $headers);
    }

    // ── Starting off ────────────────────────────────────────────────────────

    public function test_chatgpt_starts_off_and_says_so_where_it_is_asked_to_register_or_sign_in(): void
    {
        $this->assertSame(OAuthSwitch::OFF, OAuthSwitch::providerMode('chatgpt'));

        $refused = $this->postJson('/oauth/register', ['redirect_uris' => [self::STABLE]])->assertStatus(400);
        $this->assertSame('invalid_client_metadata', $refused->json('error'));
        $this->assertSame('Connecting ChatGPT to Filipino Homes is not open yet.', $refused->json('error_description'));

        // The browser is sent back with the reason, and the state it sent.
        $back = $this->authorize($this->verifier())->assertStatus(302);
        $parts = $this->parts((string) $back->headers->get('Location'));
        $this->assertStringStartsWith(self::STABLE.'?', (string) $back->headers->get('Location'));
        $this->assertSame('access_denied', $parts['error']);
        $this->assertSame('Connecting ChatGPT to Filipino Homes is not open yet.', $parts['error_description']);
        $this->assertSame('a-state-of-43-characters-0123456789ABCDEFGHI', $parts['state']);
        $this->assertArrayNotHasKey('iss', $parts, 'nothing about Claude\'s responses changes while ChatGPT is off');

        $this->assertSame(0, \App\Models\McpOauthRequest::query()->count());
    }

    public function test_while_chatgpt_is_off_claude_is_sent_exactly_what_it_always_was(): void
    {
        $this->postJson('/oauth/register', ['redirect_uris' => [self::CLAUDE]])->assertStatus(201)->assertJsonPath('client_id', 'claude');

        $document = $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->json();
        $this->assertArrayNotHasKey('authorization_response_iss_parameter_supported', $document);

        $verifier = $this->verifier();
        $handle = $this->handleOf($this->authorize($verifier, 'claude', self::CLAUDE)->assertStatus(302));
        $back = $this->parts($this->approve($handle, $this->id['u1']));

        $this->assertSame(['code', 'state'], array_keys($back));
    }

    // ── Return addresses ────────────────────────────────────────────────────

    public function test_chatgpt_registers_with_either_of_its_return_addresses_once_it_is_open(): void
    {
        $this->open();

        foreach ([[self::STABLE], [self::PER_CONNECTION], [self::STABLE, self::PER_CONNECTION]] as $uris) {
            $this->postJson('/oauth/register', ['redirect_uris' => $uris, 'client_name' => 'Whatever it says', 'token_endpoint_auth_method' => 'client_secret_basic'])
                ->assertStatus(201)
                ->assertJsonPath('client_id', 'chatgpt')
                ->assertJsonPath('client_name', 'ChatGPT')
                ->assertJsonPath('token_endpoint_auth_method', 'none')
                ->assertJsonPath('redirect_uris', $uris);
        }
    }

    public function test_nothing_that_only_looks_like_a_chatgpt_return_address_is_accepted(): void
    {
        $this->open();

        $lookalikes = [
            'https://chatgpt.com.evil.test/connector_platform_oauth_redirect',
            'https://evilchatgpt.com/connector_platform_oauth_redirect',
            'https://chatgpt.com@evil.test/connector_platform_oauth_redirect',
            'http://chatgpt.com/connector_platform_oauth_redirect',
            'https://chatgpt.com/connector_platform_oauth_redirect/',
            'https://chatgpt.com/connector_platform_oauth_redirect?next=https://evil.test',
            'https://chatgpt.com/connector_platform_oauth_redirect#x',
            'https://chatgpt.com/connector_platform_oauth_redirect2',
            'https://chatgpt.com/connector/oauth/',
            'https://chatgpt.com/connector/oauth/a/b',
            'https://chatgpt.com/connector/oauth/a?b=1',
            'https://chatgpt.com/connector/oauth/a%2F..%2F',
            'https://chatgpt.com/connector/oauth/'.str_repeat('a', 201),
            'https://chatgpt.com:8443/connector/oauth/abc',
            'https://chat.openai.com/connector_platform_oauth_redirect',
            'https://www.chatgpt.com/connector_platform_oauth_redirect',
        ];

        foreach ($lookalikes as $uri) {
            $this->postJson('/oauth/register', ['redirect_uris' => [$uri]])->assertStatus(400);

            // And a sign-in that asks to be sent there is shown a page, and goes nowhere.
            $response = $this->authorize($this->verifier(), 'chatgpt', $uri)->assertStatus(400);
            $this->assertNull($response->headers->get('Location'), $uri);
        }

        // A newline after the address: the sign-in's query is read as it was sent, so it is refused (registration's
        // body is trimmed by the framework first, which leaves the good address it was).
        $response = $this->authorize($this->verifier(), 'chatgpt', self::PER_CONNECTION."\n")->assertStatus(400);
        $this->assertNull($response->headers->get('Location'));

        // Claude's address cannot be used as ChatGPT's, nor the other way round, nor the two together.
        $this->postJson('/oauth/register', ['redirect_uris' => [self::STABLE, self::CLAUDE]])->assertStatus(400);
        $this->authorize($this->verifier(), 'chatgpt', self::CLAUDE)->assertStatus(400);
        $this->authorize($this->verifier(), 'claude', self::STABLE)->assertStatus(400);

        $this->assertSame(0, \App\Models\McpOauthRequest::query()->count());
    }

    // ── The whole sign-in ───────────────────────────────────────────────────

    public function test_a_person_signs_chatgpt_in_and_every_response_names_the_issuer(): void
    {
        $this->open();

        $document = $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->json();
        $this->assertTrue($document['authorization_response_iss_parameter_supported']);
        $this->assertSame(self::API, $document['issuer']);

        $verifier = $this->verifier();
        $handle = $this->handleOf($this->authorize($verifier)->assertStatus(302));

        $this->as($this->id['u1']);
        $this->getJson('/api/mcp/connect/requests/'.$handle)->assertOk()
            ->assertJsonPath('client.name', 'ChatGPT')
            ->assertJsonPath('client.kind', 'hosted')
            ->assertJsonPath('client.return_host', 'chatgpt.com')
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('press_and_hold', false);

        $redirect = $this->approve($handle, $this->id['u1']);
        $this->assertStringStartsWith(self::STABLE.'?', $redirect);
        $back = $this->parts($redirect);

        $this->assertSame('a-state-of-43-characters-0123456789ABCDEFGHI', $back['state']);
        $this->assertSame($document['issuer'], $back['iss'], 'the same string as the issuer, once decoded');

        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $back['code'],
            'redirect_uri' => self::STABLE,
            'client_id' => 'chatgpt',
            'code_verifier' => $verifier,
            'resource' => self::SERVER,
        ])->assertOk()->json();

        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertNotEmpty($tokens['refresh_token']);

        $names = collect($this->mcp('tools/list', $tokens['access_token'])->assertOk()->json('result.tools'))->pluck('name')->all();
        $this->assertContains('my_metric', $names, 'an agent is shown an agent\'s tools');
        $this->assertNotContains('fh_metric', $names);

        $connection = McpConnection::query()->firstOrFail();
        $this->assertSame('chatgpt', $connection->client_id);
        $this->assertSame('hosted', $connection->client_kind);
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->id['u1'])->where('title', 'ChatGPT connected')->count());
        $this->assertSame('ChatGPT was connected to your Filipino Homes account.', DB::table('app_notifications')->where('title', 'ChatGPT connected')->value('body'));
    }

    public function test_the_stable_and_the_per_connection_return_address_both_work_for_a_whole_sign_in(): void
    {
        $this->open();

        foreach ([self::STABLE, self::PER_CONNECTION] as $redirect) {
            $signedIn = $this->connect($this->id['u1'], 'chatgpt', $redirect);

            $this->assertStringStartsWith($redirect.'?', $signedIn['redirect'], 'the browser goes back to the address it came from');
            $this->assertSame(self::API, $signedIn['back']['iss']);
            $this->mcp('tools/list', $signedIn['tokens']['access_token'])->assertOk();
        }
    }

    public function test_a_denial_and_an_early_error_name_the_issuer_too(): void
    {
        $this->open();

        // The person says no.
        $handle = $this->handleOf($this->authorize($this->verifier())->assertStatus(302));
        $this->as($this->id['u1']);
        $denied = $this->parts((string) $this->postJson('/api/mcp/connect/requests/'.$handle.'/deny')->assertOk()->json('redirect_to'));
        $this->assertSame('access_denied', $denied['error']);
        $this->assertSame(self::API, $denied['iss']);

        // The request itself was wrong, but its return address was good.
        $this->app['auth']->forgetGuards();
        $wrong = $this->authorize($this->verifier(), 'chatgpt', self::STABLE, ['code_challenge_method' => 'plain'])->assertStatus(302);
        $parts = $this->parts((string) $wrong->headers->get('Location'));
        $this->assertSame('invalid_request', $parts['error']);
        $this->assertSame(self::API, $parts['iss']);
        $this->assertSame('a-state-of-43-characters-0123456789ABCDEFGHI', $parts['state']);
    }

    public function test_the_issuer_is_named_only_on_an_address_that_is_fixed(): void
    {
        $this->open();
        $this->assertTrue(OAuthSwitch::issuerIdentified());

        // Without a configured address the issuer would follow whatever host a request came to: not worth naming.
        config(['fhmcp.public_url' => '']);
        $this->assertFalse(OAuthSwitch::issuerIdentified());
    }

    public function test_once_chatgpt_is_open_claude_is_told_the_issuer_too_and_it_is_the_one_in_the_document(): void
    {
        $this->open(OAuthSwitch::ADMINS);

        $document = $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->json();
        $handle = $this->handleOf($this->authorize($this->verifier(), 'claude', self::CLAUDE)->assertStatus(302));
        $back = $this->parts($this->approve($handle, $this->id['u1']));

        $this->assertSame($document['issuer'], $back['iss']);
    }

    // ── ChatGPT's own switch ────────────────────────────────────────────────

    /** @return array<string, array{string, string, bool, bool}> */
    public static function switches(): array
    {
        // master, chatgpt, may an agent use ChatGPT, may an admin
        return [
            'master off' => ['off', 'on', false, false],
            'master admins, chatgpt on' => ['admins', 'on', false, true],
            'master on, chatgpt off' => ['on', 'off', false, false],
            'master on, chatgpt admins' => ['on', 'admins', false, true],
            'master on, chatgpt on' => ['on', 'on', true, true],
            'master admins, chatgpt admins' => ['admins', 'admins', false, true],
        ];
    }

    #[DataProvider('switches')]
    public function test_chatgpt_works_for_a_role_only_where_the_master_switch_and_its_own_both_allow_it(string $master, string $own, bool $agent, bool $admin): void
    {
        OAuthSwitch::set($master);
        $this->open($own);

        $this->assertSame($agent, OAuthSwitch::allows(McpRole::Agent, 'chatgpt'));
        $this->assertSame($admin, OAuthSwitch::allows(McpRole::Admin, 'chatgpt'));

        // What the portal says is the same, and Claude's side of it is the master's, as it always was.
        foreach ([[$this->id['u1'], $agent], [$this->id['admin'], $admin]] as [$userId, $expected]) {
            $this->as($userId);
            $status = $this->getJson('/api/mcp/connect/status')->assertOk();
            $chatgpt = collect($status->json('providers'))->firstWhere('id', 'chatgpt');

            $this->assertSame($expected, $chatgpt['available'], "{$master}/{$own}");
            $this->assertSame($own, $chatgpt['mode']);
        }
    }

    public function test_an_app_that_is_not_one_of_ours_is_allowed_nobody_and_no_app_means_the_master_alone(): void
    {
        $this->assertFalse(OAuthSwitch::allows(McpRole::Admin, 'someone-else'));
        $this->assertTrue(OAuthSwitch::allows(McpRole::Agent));
        $this->assertTrue(OAuthSwitch::allows(McpRole::Agent, 'claude'));
        $this->assertTrue(OAuthSwitch::allows(McpRole::Agent, 'claude-code'));
        $this->assertFalse(OAuthSwitch::allows(McpRole::Agent, 'chatgpt'));
    }

    public function test_an_agent_is_told_chatgpt_is_not_open_to_them_when_it_is_only_open_to_admins(): void
    {
        $this->open(OAuthSwitch::ADMINS);

        $handle = $this->handleOf($this->authorize($this->verifier())->assertStatus(302));

        $this->as($this->id['u1']);
        $this->getJson('/api/mcp/connect/requests/'.$handle)->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('reason', 'paused');

        $refused = $this->postJson('/api/mcp/connect/requests/'.$handle.'/approve', ['account_id' => $this->id['u1']])->assertStatus(403);
        $this->assertSame('Connecting ChatGPT is not open to your account yet.', $refused->json('error_description'));
        $this->assertSame(0, McpConnection::query()->count());

        // An admin may, and is asked to hold the button as every admin is.
        $this->as($this->id['admin']);
        $handle = $this->handleOf($this->authorize($this->verifier())->assertStatus(302));
        $this->getJson('/api/mcp/connect/requests/'.$handle)->assertOk()->assertJsonPath('allowed', true)->assertJsonPath('press_and_hold', true);
    }

    public function test_a_chatgpt_connection_is_paused_when_its_switch_goes_off_and_works_again_when_it_comes_back(): void
    {
        $this->open();
        $chatgpt = $this->connect($this->id['u1'])['tokens'];
        $claude = $this->connect($this->id['u1'], 'claude', self::CLAUDE)['tokens'];

        $this->mcp('tools/list', $chatgpt['access_token'])->assertOk();
        $this->mcp('tools/list', $claude['access_token'])->assertOk();

        $this->open(OAuthSwitch::OFF);

        // ChatGPT's connection is paused (a 403 that says why, never a 401 that would have it sign in again) and
        // Claude's is not touched.
        $paused = $this->mcp('tools/list', $chatgpt['access_token'])->assertStatus(403);
        $this->assertSame('Connecting ChatGPT is not open to your account yet.', $paused->json('error.message'));
        $this->mcp('tools/list', $claude['access_token'])->assertOk();

        // It is kept, and what it holds can still be refreshed (a pause does not strand it).
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $chatgpt['refresh_token'], 'client_id' => 'chatgpt'])->assertOk();
        $this->assertSame(2, McpConnection::query()->active()->count());

        $this->open();
        $this->mcp('tools/list', $chatgpt['access_token'])->assertOk();
    }

    public function test_turning_the_master_off_stops_everything_whatever_chatgpts_own_switch_says(): void
    {
        $this->open();
        $chatgpt = $this->connect($this->id['u1'])['tokens'];

        OAuthSwitch::set(OAuthSwitch::OFF);

        $this->mcp('tools/list', $chatgpt['access_token'])->assertStatus(401);
        $this->postJson('/oauth/register', ['redirect_uris' => [self::STABLE]])->assertNotFound();
    }

    // ── Cap, names, shapes ──────────────────────────────────────────────────

    public function test_each_ai_has_its_own_connection_cap(): void
    {
        $this->open();
        config(['fhmcp.max_connections' => 1]);

        $this->connect($this->id['u1'], 'claude', self::CLAUDE);
        $this->connect($this->id['u1']);
        $this->assertSame(2, McpConnection::query()->active()->count(), 'one of each');

        // A second ChatGPT replaces the first ChatGPT, and Claude's is not the one that goes.
        $this->connect($this->id['u1']);
        $this->assertSame(['claude', 'chatgpt'], McpConnection::query()->active()->orderBy('id')->pluck('client_id')->all());
        $this->assertSame(['replaced'], McpConnection::query()->whereNotNull('revoked_at')->pluck('revoked_reason')->all());
        $this->assertSame('chatgpt', McpConnection::query()->whereNotNull('revoked_at')->value('client_id'));
    }

    public function test_the_names_and_the_ai_each_app_belongs_to(): void
    {
        $this->assertSame('Claude', OAuthClients::name('claude'));
        $this->assertSame('Claude Code', OAuthClients::name('claude-code'));
        $this->assertSame('ChatGPT', OAuthClients::name('chatgpt'));
        $this->assertSame('An AI app', OAuthClients::name('unlisted'), 'an app that is not ours is never called Claude');

        $this->assertSame('claude', OAuthClients::providerOf('claude'));
        $this->assertSame('claude', OAuthClients::providerOf('claude-code'));
        $this->assertSame('chatgpt', OAuthClients::providerOf('chatgpt'));
        $this->assertNull(OAuthClients::providerOf('unlisted'));
        $this->assertSame(['claude' => 'Claude', 'chatgpt' => 'ChatGPT'], OAuthClients::providers());
        $this->assertSame(['claude', 'claude-code'], OAuthClients::clientsOf('claude'));
    }

    public function test_the_portal_status_keeps_its_keys_and_says_which_ai_each_connection_is(): void
    {
        $this->open();
        $this->connect($this->id['u1'], 'claude', self::CLAUDE);
        $this->connect($this->id['u1']);

        $this->as($this->id['u1']);
        $status = $this->getJson('/api/mcp/connect/status')->assertOk();

        // What the deployed website reads is as it was (Claude's, the master's); the AIs are added beside it.
        $this->assertSame(['mode', 'available', 'role', 'address', 'connect_link', 'providers', 'connections'], array_keys($status->json()));
        $this->assertSame('on', $status->json('mode'));
        $this->assertTrue($status->json('available'));
        $this->assertSame(self::SERVER, $status->json('address'));
        $this->assertSame([['id' => 'claude', 'name' => 'Claude', 'mode' => 'on', 'available' => true], ['id' => 'chatgpt', 'name' => 'ChatGPT', 'mode' => 'on', 'available' => true]], $status->json('providers'));
        $this->assertSame(['ChatGPT', 'Claude'], collect($status->json('connections'))->pluck('client')->all());
        $this->assertSame(['chatgpt', 'claude'], collect($status->json('connections'))->pluck('provider')->all());
    }

    public function test_the_portal_status_while_nothing_is_open_has_no_ai_available(): void
    {
        OAuthSwitch::set(OAuthSwitch::OFF);

        $this->as($this->id['u1']);
        $status = $this->getJson('/api/mcp/connect/status')->assertOk();

        $this->assertFalse($status->json('available'));
        $this->assertSame([false, false], collect($status->json('providers'))->pluck('available')->all());
        $this->assertSame([], $status->json('connections'));
    }

    // ── The admin's switch ──────────────────────────────────────────────────

    public function test_an_admin_opens_chatgpt_on_its_own_and_the_master_switch_is_not_touched(): void
    {
        $this->as($this->id['admin']);

        $state = $this->getJson('/api/admin/mcp-oauth')->assertOk();
        $this->assertSame('on', $state->json('mode'));
        $this->assertSame([
            ['id' => 'claude', 'name' => 'Claude', 'mode' => 'on', 'connections' => ['active' => 0, 'needing_reconnect' => 0]],
            ['id' => 'chatgpt', 'name' => 'ChatGPT', 'mode' => 'off', 'connections' => ['active' => 0, 'needing_reconnect' => 0]],
        ], $state->json('providers'));

        $this->putJson('/api/admin/mcp-oauth/providers/chatgpt', ['mode' => 'admins'])->assertOk()
            ->assertJsonPath('mode', 'on')
            ->assertJsonPath('providers.1.mode', 'admins');
        $this->assertSame('admins', OAuthSwitch::providerMode('chatgpt'));
        $this->assertSame('on', OAuthSwitch::mode(), 'Claude\'s switch is not moved by ChatGPT\'s');

        // Setting the master is what it was, and does not move ChatGPT's.
        $this->putJson('/api/admin/mcp-oauth', ['mode' => 'admins'])->assertOk()->assertJsonPath('providers.1.mode', 'admins');
        $this->assertSame('admins', OAuthSwitch::mode());
    }

    public function test_only_chatgpts_switch_can_be_set_that_way_and_only_to_a_known_position_by_an_admin(): void
    {
        $this->as($this->id['admin']);

        $this->putJson('/api/admin/mcp-oauth/providers/chatgpt', ['mode' => 'everyone'])->assertStatus(422);
        $this->putJson('/api/admin/mcp-oauth/providers/chatgpt', [])->assertStatus(422);
        $this->putJson('/api/admin/mcp-oauth/providers/claude', ['mode' => 'off'])->assertNotFound();
        $this->putJson('/api/admin/mcp-oauth/providers/gemini', ['mode' => 'on'])->assertNotFound();
        $this->assertSame('on', OAuthSwitch::mode(), 'Claude\'s switch was not touched by any of them');
        $this->assertSame('off', OAuthSwitch::providerMode('chatgpt'));

        $this->as($this->id['u1']);
        $this->putJson('/api/admin/mcp-oauth/providers/chatgpt', ['mode' => 'on'])->assertForbidden();
        $this->assertSame('off', OAuthSwitch::providerMode('chatgpt'));
    }

    public function test_an_admin_sees_each_ais_own_connections_counted(): void
    {
        $this->open();
        $this->connect($this->id['u1'], 'claude', self::CLAUDE);
        $this->connect($this->id['u1']);

        $this->as($this->id['admin']);
        $providers = collect($this->getJson('/api/admin/mcp-oauth')->assertOk()->json('providers'))->keyBy('id');

        $this->assertSame(1, $providers['claude']['connections']['active']);
        $this->assertSame(1, $providers['chatgpt']['connections']['active']);

        $rows = collect($this->getJson('/api/admin/mcp-connections')->assertOk()->json('data'));
        $this->assertSame(['chatgpt', 'claude'], $rows->pluck('provider')->all());
        $this->assertSame(['ChatGPT', 'Claude'], $rows->pluck('client')->all());
    }

    // ── Odds and ends a real ChatGPT does ───────────────────────────────────

    public function test_a_token_call_that_carries_a_basic_authorization_header_is_still_answered(): void
    {
        $this->open();
        $verifier = $this->verifier();
        $handle = $this->handleOf($this->authorize($verifier)->assertStatus(302));
        $back = $this->parts($this->approve($handle, $this->id['u1']));

        $this->app['auth']->forgetGuards();
        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $back['code'],
            'redirect_uri' => self::STABLE,
            'code_verifier' => $verifier,
        ], ['Authorization' => 'Basic '.base64_encode('chatgpt:')])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    }

    public function test_the_mcp_server_lets_a_request_from_a_chatgpt_page_in_and_still_refuses_a_stranger(): void
    {
        $this->open();
        $token = $this->connect($this->id['u1'])['tokens']['access_token'];

        $this->assertContains('https://chatgpt.com', config('mcp.allowed_origins'));
        $this->mcp('tools/list', $token, [], ['Origin' => 'https://chatgpt.com'])->assertOk();
        $this->mcp('tools/list', $token, [], ['Origin' => 'https://evil.example.test'])->assertForbidden();
    }
}
