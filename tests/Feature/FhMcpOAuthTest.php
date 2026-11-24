<?php

namespace Tests\Feature;

use App\Mcp\Support\McpKeys;
use App\Models\McpConnection;
use App\Models\McpOauthRequest;
use App\Models\McpOauthToken;
use App\Models\User;
use App\Services\McpOAuth\OAuthSwitch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/**
 * Signing Claude in: a person connects their own Claude to their FH account
 * with their FH login (docs/mcp.md, "Sign-in"). The test plays Claude's part:
 * registers, sends the browser to /oauth/authorize, and trades the code for
 * tokens; and the person's part: the website asks the API who is asking and
 * answers. Then the attacks: a wrong return address, a code used twice, a
 * refresh token stolen, a person who is no longer who they were.
 */
class FhMcpOAuthTest extends TestCase
{
    use CreatesExecutiveReportTables;

    private const API = 'https://api.example.test';

    private const SERVER = 'https://api.example.test/mcp/filipinohomes';

    private const CONSENT = 'https://site.example.test/connect/claude';

    private const CLAUDE = 'https://claude.ai/api/mcp/auth_callback';

    /** @var array<string, int> */
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('x');

        // 2 PM in Manila, written in UTC: a clock frozen in another zone makes the model read stored times back in
        // that zone, which a request that lasts fifteen minutes or a code that lasts one cannot survive.
        Carbon::setTestNow(Carbon::parse('2026-11-10 06:00', 'UTC'));
        $this->createExecutiveReportTables();

        foreach (['2026_11_24_000001_create_mcp_keys_and_calls_tables.php', '2026_11_24_000002_add_who_to_mcp_keys_table.php', '2026_11_24_000003_let_mcp_keys_be_permanent.php', '2026_11_24_000004_create_mcp_oauth_tables.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }

        // What a bell notification needs.
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
        DB::table('team_agents')->where('agent_id', $this->id['a1'])->update(['is_leader' => false]); // a1 is a plain agent unless a test says
        $this->id['admin'] = $this->makeUser(self::ADMIN, ['name' => 'The Boss', 'email' => 'boss@example.com']);
        $this->id['admin2'] = $this->makeUser(self::ADMIN, ['name' => 'Other Boss', 'email' => 'boss2@example.com']);
        $this->id['u2'] = (int) DB::table('agents')->where('id', $this->id['a2'])->value('user_id');

        config([
            'fhmcp.public_url' => self::API,
            'fhmcp.consent_url' => self::CONSENT,
            'fhmcp.rate_limit' => 100000,
        ]);

        OAuthSwitch::set(OAuthSwitch::ON);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Claude's part ───────────────────────────────────────────────────────

    private function verifier(): string
    {
        return Str::random(64);
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** The browser arriving at /oauth/authorize. */
    private function authorize(string $verifier, array $override = []): TestResponse
    {
        $query = array_merge([
            'response_type' => 'code',
            'client_id' => 'claude',
            'redirect_uri' => self::CLAUDE,
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

    private function token(array $form): TestResponse
    {
        return $this->post('/oauth/token', $form);
    }

    /** The code and state in the address the browser is sent back to. */
    private function parts(string $redirect): array
    {
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        return $query;
    }

    // ── The person's part (what the website does) ───────────────────────────

    private function as(int $userId): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::query()->findOrFail($userId), 'sanctum');
    }

    /** The person says yes. Returns where the browser goes. */
    private function approve(string $handle, int $userId): string
    {
        $this->as($userId);

        return (string) $this->postJson('/api/mcp/connect/requests/'.$handle.'/approve')->assertOk()->json('redirect_to');
    }

    /**
     * The whole sign-in, start to tokens.
     *
     * @return array{tokens: array<string, mixed>, verifier: string, handle: string, code: string}
     */
    private function connect(int $userId, ?string $verifier = null): array
    {
        $verifier ??= $this->verifier();
        $handle = $this->handleOf($this->authorize($verifier)->assertStatus(302));
        $back = $this->parts($this->approve($handle, $userId));
        $this->assertArrayHasKey('code', $back);

        $tokens = $this->token([
            'grant_type' => 'authorization_code',
            'code' => $back['code'],
            'redirect_uri' => self::CLAUDE,
            'client_id' => 'claude',
            'code_verifier' => $verifier,
            'resource' => self::SERVER,
        ])->assertOk()->json();

        return ['tokens' => $tokens, 'verifier' => $verifier, 'handle' => $handle, 'code' => $back['code']];
    }

    // ── The MCP server ──────────────────────────────────────────────────────

    private function mcp(string $method, ?string $bearer, array $params = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];

        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        return $this->postJson('/mcp/filipinohomes', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method] + ($params === [] ? [] : ['params' => $params]), $headers);
    }

    /** @return list<string> */
    private function toolsFor(string $bearer): array
    {
        $names = collect($this->mcp('tools/list', $bearer)->assertOk()->json('result.tools'))->pluck('name')->all();
        sort($names);

        return $names;
    }

    // ── Off ─────────────────────────────────────────────────────────────────

    public function test_while_the_switch_is_off_nothing_answers_and_the_401_says_nothing_about_signing_in(): void
    {
        OAuthSwitch::set(OAuthSwitch::OFF);

        foreach (['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp/filipinohomes', '/.well-known/oauth-authorization-server', '/oauth/authorize', '/authorize'] as $path) {
            $this->get($path)->assertNotFound();
        }

        foreach (['/oauth/register', '/oauth/token', '/oauth/revoke', '/register', '/token'] as $path) {
            $this->post($path)->assertNotFound();
        }

        $this->mcp('tools/list', null)->assertStatus(401)->assertHeader('WWW-Authenticate', 'Bearer realm="mcp"');
        $this->assertSame(0, McpOauthRequest::query()->count());
    }

    public function test_it_is_off_too_on_an_address_claude_could_not_use(): void
    {
        config(['fhmcp.public_url' => 'http://api.example.test']); // not https

        $this->get('/.well-known/oauth-authorization-server')->assertNotFound();

        // But localhost over http is fine, for trying it out.
        config(['fhmcp.public_url' => 'http://localhost:8000']);
        $this->get('/.well-known/oauth-authorization-server')->assertOk();
    }

    // ── What Claude reads ───────────────────────────────────────────────────

    public function test_the_401_points_at_the_documents_and_the_documents_name_the_configured_address(): void
    {
        $challenge = (string) $this->mcp('tools/list', null)->assertStatus(401)->headers->get('WWW-Authenticate');

        $this->assertStringContainsString('resource_metadata="'.self::API.'/.well-known/oauth-protected-resource/mcp/filipinohomes"', $challenge);
        $this->assertStringContainsString('scope="fh:read"', $challenge);

        foreach (['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp/filipinohomes'] as $path) {
            $this->getJson($path)->assertOk()
                ->assertExactJson([
                    'resource' => self::SERVER,
                    'authorization_servers' => [self::API],
                    'scopes_supported' => ['fh:read'],
                    'bearer_methods_supported' => ['header'],
                    'resource_name' => 'Filipino Homes',
                ]);
        }

        $this->getJson('/.well-known/oauth-protected-resource/something/else')->assertNotFound();

        $server = $this->getJson('/.well-known/oauth-authorization-server')->assertOk();
        $server->assertJsonPath('issuer', self::API)
            ->assertJsonPath('authorization_endpoint', self::API.'/oauth/authorize')
            ->assertJsonPath('token_endpoint', self::API.'/oauth/token')
            ->assertJsonPath('registration_endpoint', self::API.'/oauth/register')
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('token_endpoint_auth_methods_supported', ['none']);
        $this->assertNotContains(null, $server->json(), 'no field is sent as null');
        $this->getJson('/.well-known/oauth-authorization-server/mcp')->assertNotFound();

        // A request that came to another host still gets the one configured address.
        $this->get('http://other.example.test/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('issuer', self::API);
    }

    public function test_a_request_with_no_key_is_never_counted_against_its_address_even_while_signing_in_is_on(): void
    {
        config(['fhmcp.ip_failures' => 3]);

        foreach (range(1, 12) as $_) {
            $this->mcp('tools/list', null)->assertStatus(401);
            $this->mcp('tools/list', 'fhoat_'.str_repeat('x', 48))->assertStatus(401);
        }

        // Nothing above counted: a good connection still works, and so does a wrong key's ordinary answer.
        $this->mcp('tools/list', $this->connect($this->id['u1'])['tokens']['access_token'])->assertOk();
        $this->mcp('tools/list', 'fhmcp_'.str_repeat('z', 48))->assertStatus(401);
    }

    public function test_a_handshake_without_a_key_is_not_allowed_while_signing_in_is_on(): void
    {
        config(['fhmcp.open_handshake' => true]);

        // The 401 is what starts the sign-in; a handshake that let Claude in without a key would skip it.
        $this->mcp('initialize', null, ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']])->assertStatus(401);

        OAuthSwitch::set(OAuthSwitch::OFF);
        $this->mcp('initialize', null, ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']])->assertOk();
    }

    // ── Registering ─────────────────────────────────────────────────────────

    public function test_registering_stores_nothing_and_answers_a_fixed_public_client_whatever_was_asked(): void
    {
        $before = [DB::table('mcp_oauth_requests')->count(), DB::table('mcp_connections')->count(), DB::table('mcp_oauth_tokens')->count()];

        $asked = ['client_name' => 'Totally Legit Bank', 'redirect_uris' => [self::CLAUDE], 'token_endpoint_auth_method' => 'client_secret_post', 'grant_types' => ['client_credentials']];

        $first = $this->postJson('/oauth/register', $asked)->assertCreated();
        $second = $this->postJson('/oauth/register', $asked)->assertCreated();

        $first->assertJsonPath('client_id', 'claude')
            ->assertJsonPath('client_name', 'Claude')
            ->assertJsonPath('token_endpoint_auth_method', 'none')
            ->assertJsonPath('redirect_uris', [self::CLAUDE]);
        $this->assertSame($first->json('client_id'), $second->json('client_id'));
        $this->assertSame($before, [DB::table('mcp_oauth_requests')->count(), DB::table('mcp_connections')->count(), DB::table('mcp_oauth_tokens')->count()]);

        // Claude Code, on its own computer, on any port.
        $this->postJson('/oauth/register', ['redirect_uris' => ['http://localhost:54321/callback', 'http://127.0.0.1:1234/callback']])->assertCreated()->assertJsonPath('client_id', 'claude-code');
    }

    public function test_a_return_address_that_is_not_on_the_list_is_refused_at_registration(): void
    {
        foreach ([
            'a lookalike host' => ['https://claude.ai.evil.example/api/mcp/auth_callback'],
            'a login part' => ['https://claude.ai@evil.example/api/mcp/auth_callback'],
            'another path' => ['https://claude.ai/other'],
            'a query' => [self::CLAUDE.'?x=1'],
            'a fragment' => [self::CLAUDE.'#x'],
            'http, not https' => ['http://claude.ai/api/mcp/auth_callback'],
            'a loopback with another path' => ['http://localhost:3000/steal'],
            'a loopback over https' => ['https://localhost:3000/callback'],
            'a different app mixed in' => [self::CLAUDE, 'http://localhost:3000/callback'],
            'nothing' => [],
        ] as $why => $uris) {
            $this->postJson('/oauth/register', ['redirect_uris' => $uris])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        }

        $this->postJson('/oauth/register', ['redirect_uris' => 'https://claude.ai/api/mcp/auth_callback'])->assertStatus(400);
        $this->postJson('/oauth/register', [])->assertStatus(400);
    }

    // ── Starting a sign-in ──────────────────────────────────────────────────

    public function test_a_good_request_is_kept_and_the_browser_goes_to_the_website_with_only_a_handle(): void
    {
        $response = $this->authorize($this->verifier())->assertStatus(302);
        $handle = $this->handleOf($response);

        $this->assertSame(43, strlen($handle));
        $this->assertStringNotContainsString('code_challenge', (string) $response->headers->get('Location'));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $kept = McpOauthRequest::query()->firstOrFail();
        $this->assertSame(hash('sha256', $handle), (string) DB::table('mcp_oauth_requests')->value('handle_hash'), 'only the hash of the handle is kept');
        $this->assertSame('claude', $kept->client_id);
        $this->assertNull($kept->decision);
        $this->assertTrue($kept->expires_at->isFuture());
    }

    public function test_an_unknown_client_or_a_return_address_that_is_not_theirs_gets_a_page_and_never_a_redirect(): void
    {
        $verifier = $this->verifier();

        foreach ([
            ['client_id' => 'someone-else'],
            ['redirect_uri' => 'https://evil.example/callback'],
            ['redirect_uri' => 'http://localhost:3000/callback'],      // Claude Code's address, but the Claude apps' client
            ['client_id' => 'claude-code'],                              // the Claude apps' address, but Claude Code's client
            ['redirect_uri' => ''],
            ['client_id' => ''],
        ] as $wrong) {
            $response = $this->authorize($verifier, $wrong)->assertStatus(400);

            $this->assertNull($response->headers->get('Location'), json_encode($wrong));
            $response->assertHeader('X-Frame-Options', 'DENY');
            $this->assertStringContainsString('not valid', $response->getContent());
            $this->assertStringNotContainsString('evil.example', $response->getContent(), 'nothing of what was sent is shown');
        }

        $this->assertSame(0, McpOauthRequest::query()->count());
    }

    public function test_pkce_is_required_and_only_the_s256_method(): void
    {
        $verifier = $this->verifier();

        foreach ([
            'no challenge' => ['code_challenge' => null],
            'a short challenge' => ['code_challenge' => 'abc'],
            'a challenge with bad characters' => ['code_challenge' => str_repeat('+', 43)],
            'the plain method' => ['code_challenge_method' => 'plain'],
            'no method' => ['code_challenge_method' => null],
        ] as $why => $override) {
            $response = $this->authorize($verifier, $override); // a null is left out of the query altogether
            // A mistake after the return address was found good goes back to Claude, as OAuth says.
            $response->assertStatus(302);
            $location = (string) $response->headers->get('Location');
            $this->assertStringStartsWith(self::CLAUDE.'?', $location, $why);
            $this->assertSame('invalid_request', $this->parts($location)['error'], $why);
        }

        $this->assertSame(0, McpOauthRequest::query()->count());
    }

    public function test_only_response_type_code_and_only_this_server_as_the_resource(): void
    {
        $verifier = $this->verifier();

        $this->assertSame('unsupported_response_type', $this->parts((string) $this->authorize($verifier, ['response_type' => 'token'])->assertStatus(302)->headers->get('Location'))['error']);

        // The resource is optional, and may be written any way that is the same address.
        $this->authorize($verifier, ['resource' => null])->assertStatus(302);
        $this->authorize($verifier, ['resource' => 'https://API.example.test/mcp/filipinohomes/'])->assertStatus(302);
        $this->authorize($verifier, ['resource' => 'https://api.example.test:443/mcp/filipinohomes'])->assertStatus(302);

        foreach (['https://api.example.test/mcp', 'https://other.example.test/mcp/filipinohomes', 'http://api.example.test/mcp/filipinohomes'] as $resource) {
            $this->assertSame('invalid_target', $this->parts((string) $this->authorize($verifier, ['resource' => $resource])->assertStatus(302)->headers->get('Location'))['error'], $resource);
        }
    }

    public function test_the_state_comes_back_exactly_and_an_unusable_one_is_refused(): void
    {
        $verifier = $this->verifier();
        $state = 'ab+c d/é?&=#%'; // anything Claude may send, once decoded

        $handle = $this->handleOf($this->authorize($verifier, ['state' => 'Zm9v+/=_-. ~!*()'])->assertStatus(302));
        $back = $this->parts($this->approve($handle, $this->id['u1']));
        $this->assertSame('Zm9v+/=_-. ~!*()', $back['state'], 'byte for byte');

        // Too long, or beyond printable characters: sent back to Claude without it.
        foreach ([str_repeat('a', 1025), "line\nbreak", 'caf'."\u{e9}"] as $bad) {
            $location = (string) $this->authorize($verifier, ['state' => $bad])->assertStatus(302)->headers->get('Location');
            $this->assertSame('invalid_request', $this->parts($location)['error']);
            $this->assertArrayNotHasKey('state', $this->parts($location));
        }

        $this->assertNotSame('', $state);
    }

    // ── The person's part ───────────────────────────────────────────────────

    public function test_the_website_is_told_who_is_asking_and_which_account_it_is(): void
    {
        $handle = $this->handleOf($this->authorize($this->verifier())->assertStatus(302));
        $this->as($this->id['u1']);

        $this->getJson('/api/mcp/connect/requests/'.$handle)
            ->assertOk()
            ->assertJsonPath('client.name', 'Claude')
            ->assertJsonPath('client.kind', 'hosted')
            ->assertJsonPath('client.return_host', 'claude.ai')
            ->assertJsonPath('account.email', User::query()->find($this->id['u1'])->email)
            ->assertJsonPath('account.role', 'agent')
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('press_and_hold', false);

        // An admin's connection opens the whole company's reports: that one takes a press and hold.
        $this->as($this->id['admin']);
        $this->getJson('/api/mcp/connect/requests/'.$handle)->assertOk()->assertJsonPath('account.role', 'admin')->assertJsonPath('press_and_hold', true);
    }

    public function test_the_consent_endpoints_never_answer_401_for_a_sign_in_that_is_wrong(): void
    {
        $this->as($this->id['u1']);

        // The website signs a person out when the API says 401, so a sign-in that is unknown, expired or answered is not that.
        $this->getJson('/api/mcp/connect/requests/'.str_repeat('a', 43))->assertNotFound();

        $handle = $this->handleOf($this->authorize($this->verifier())->assertStatus(302));
        $this->postJson('/api/mcp/connect/requests/'.$handle.'/deny')->assertOk();
        $this->postJson('/api/mcp/connect/requests/'.$handle.'/approve')->assertStatus(409)->assertJsonPath('error', 'answered');
        $this->getJson('/api/mcp/connect/requests/'.$handle)->assertStatus(409);

        $expired = $this->handleOf($this->authorize($this->verifier())->assertStatus(302));
        Carbon::setTestNow(now()->addMinutes(16));
        $this->getJson('/api/mcp/connect/requests/'.$expired)->assertStatus(410)->assertJsonPath('error', 'expired');
        $this->postJson('/api/mcp/connect/requests/'.$expired.'/approve')->assertStatus(410);
    }

    public function test_saying_no_sends_the_browser_back_with_access_denied_and_the_state(): void
    {
        $handle = $this->handleOf($this->authorize($this->verifier())->assertStatus(302));
        $this->as($this->id['u1']);

        $back = (string) $this->postJson('/api/mcp/connect/requests/'.$handle.'/deny')->assertOk()->json('redirect_to');

        $this->assertStringStartsWith(self::CLAUDE.'?', $back);
        $this->assertSame('access_denied', $this->parts($back)['error']);
        $this->assertSame('a-state-of-43-characters-0123456789ABCDEFGHI', $this->parts($back)['state']);
        $this->assertSame(0, McpConnection::query()->count());
    }

    public function test_someone_who_may_not_connect_cannot_approve_and_an_impersonation_login_cannot(): void
    {
        $handle = $this->handleOf($this->authorize($this->verifier())->assertStatus(302));

        // A client and a secretary have no role on the MCP server: the page is told so, and approving is refused.
        foreach ([$this->id['c1'], $this->makeUser(self::SECRETARY)] as $userId) {
            $this->as($userId);
            $this->getJson('/api/mcp/connect/requests/'.$handle)->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('reason', 'no_role');
            $this->postJson('/api/mcp/connect/requests/'.$handle.'/approve')->assertStatus(403)->assertJsonPath('error', 'not_allowed');
        }

        // An agent who has been switched off is stopped before it gets that far, like everything else on the API.
        $this->as((int) DB::table('agents')->where('id', $this->id['a5'])->value('user_id'));
        $this->getJson('/api/mcp/connect/requests/'.$handle)->assertStatus(403)->assertJsonPath('account_status', 'inactive');
        $this->postJson('/api/mcp/connect/requests/'.$handle.'/approve')->assertStatus(403);

        // An admin looking at an agent's account through "log in as" must not connect Claude for them.
        $agent = User::query()->findOrFail($this->id['u1']);
        $agent->withAccessToken(new PersonalAccessToken(['name' => 'admin-impersonation:'.$this->id['admin']]));
        $this->app['auth']->forgetGuards();
        $this->actingAs($agent, 'sanctum');
        $this->getJson('/api/mcp/connect/requests/'.$handle)->assertOk()->assertJsonPath('reason', 'impersonated');
        $this->postJson('/api/mcp/connect/requests/'.$handle.'/approve')->assertStatus(403);

        $this->assertNull(McpOauthRequest::query()->firstOrFail()->decision, 'nothing was answered');
        $this->assertSame(0, McpConnection::query()->count());
    }

    public function test_a_request_is_answered_once_even_by_two_at_the_same_moment(): void
    {
        $handle = $this->handleOf($this->authorize($this->verifier())->assertStatus(302));
        $this->approve($handle, $this->id['u1']);

        $this->as($this->id['u1']);
        $this->postJson('/api/mcp/connect/requests/'.$handle.'/approve')->assertStatus(409);
        $this->postJson('/api/mcp/connect/requests/'.$handle.'/deny')->assertStatus(409);
    }

    // ── The code, and the tokens ────────────────────────────────────────────

    public function test_the_whole_sign_in_gives_tokens_that_open_the_mcp_server_as_that_person(): void
    {
        $made = $this->connect($this->id['u1']);
        $tokens = $made['tokens'];

        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertStringStartsWith('fhoat_', $tokens['access_token']);
        $this->assertStringStartsWith('fhort_', $tokens['refresh_token']);
        $this->assertSame('fh:read', $tokens['scope']);
        $this->assertSame(8 * 3600, $tokens['expires_in']);

        // The person's own agent tools, and the numbers are theirs.
        $this->assertSame(['my_lookup', 'my_metric', 'my_metric_breakdown', 'my_metric_records', 'my_metrics_catalog'], $this->toolsFor($tokens['access_token']));
        $answer = $this->mcp('tools/call', $tokens['access_token'], ['name' => 'my_metric', 'arguments' => (object) ['metric' => 'listings.total']])->assertOk();
        $this->assertSame(3, json_decode((string) $answer->json('result.content.0.text'), true)['value']);

        // Only hashes are kept, and the connection is the person's, for the role they had.
        $this->assertSame(1, McpConnection::query()->count());
        $connection = McpConnection::query()->firstOrFail();
        $this->assertSame($this->id['u1'], (int) $connection->user_id);
        $this->assertSame('agent', $connection->role);
        $this->assertSame(self::SERVER, $connection->resource);
        $this->assertStringNotContainsString($tokens['access_token'], json_encode(DB::table('mcp_oauth_tokens')->get()));
        $this->assertStringNotContainsString($tokens['refresh_token'], json_encode(DB::table('mcp_oauth_tokens')->get()));
        $this->assertStringNotContainsString($made['code'], json_encode(DB::table('mcp_oauth_requests')->get()));

        // And the call is on record as made through the connection.
        $this->assertSame($connection->id, (int) DB::table('mcp_calls')->value('mcp_connection_id'));
        $this->assertNull(DB::table('mcp_calls')->value('mcp_key_id'));

        // The person was told.
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->id['u1'])->where('title', 'Claude connected')->count());
    }

    public function test_a_key_is_still_a_key_and_a_connection_is_not_one(): void
    {
        $key = McpKeys::issue(User::query()->findOrFail($this->id['u3']), 'laptop')['key'];

        // Nothing about a key changed.
        $this->assertSame(['my_lookup', 'my_metric', 'my_metric_breakdown', 'my_metric_records', 'my_metrics_catalog'], $this->toolsFor($key));

        // A connection's token is not a Sanctum token, and a login token is no use on the MCP server.
        $access = $this->connect($this->id['u1'])['tokens']['access_token'];
        $this->app['auth']->forgetGuards(); // nobody is signed in on the test's side any more
        $this->getJson('/api/user/profile', ['Authorization' => 'Bearer '.$access])->assertStatus(401);
        $this->mcp('tools/list', '17|'.str_repeat('x', 40))->assertStatus(401);
    }

    public function test_a_code_works_once_and_a_wrong_verifier_client_return_address_or_resource_spends_it(): void
    {
        $verifier = $this->verifier();

        $form = fn (string $code, array $override = []) => array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::CLAUDE,
            'client_id' => 'claude',
            'code_verifier' => $verifier,
        ], $override);

        // Each mistake spends the code, so it cannot be tried again with the right answer.
        foreach ([
            'a wrong verifier' => ['code_verifier' => $this->verifier()],
            'another client' => ['client_id' => 'claude-code'],
            'another return address' => ['redirect_uri' => 'https://claude.com/api/mcp/auth_callback'],
        ] as $why => $override) {
            $code = $this->parts($this->approve($this->handleOf($this->authorize($verifier)->assertStatus(302)), $this->id['u1']))['code'];

            $this->token($form($code, $override))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
            $this->token($form($code))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        }

        // A resource that is not this server is not a good target.
        $code = $this->parts($this->approve($this->handleOf($this->authorize($verifier)->assertStatus(302)), $this->id['u1']))['code'];
        $this->token($form($code, ['resource' => 'https://other.example.test/mcp/filipinohomes']))->assertStatus(400)->assertJsonPath('error', 'invalid_target');
        $this->token($form($code))->assertStatus(400);

        $this->assertSame(0, McpConnection::query()->count(), 'none of those made a connection');

        // The right one, once; the same code again is refused and leaves the connection alone.
        $code = $this->parts($this->approve($this->handleOf($this->authorize($verifier)->assertStatus(302)), $this->id['u1']))['code'];
        $access = (string) $this->token($form($code))->assertOk()->json('access_token');
        $this->token($form($code))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->mcp('tools/list', $access)->assertOk();
        $this->assertSame(1, McpConnection::query()->count());
    }

    public function test_a_code_runs_out_after_a_minute_and_a_request_that_was_not_approved_has_none(): void
    {
        $verifier = $this->verifier();
        $code = $this->parts($this->approve($this->handleOf($this->authorize($verifier)->assertStatus(302)), $this->id['u1']))['code'];

        Carbon::setTestNow(now()->addSeconds(61));
        $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CLAUDE, 'code_verifier' => $verifier])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        // A made-up code, and no code at all.
        $this->token(['grant_type' => 'authorization_code', 'code' => Str::random(48), 'redirect_uri' => self::CLAUDE, 'code_verifier' => $verifier])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->token(['grant_type' => 'authorization_code', 'redirect_uri' => self::CLAUDE, 'code_verifier' => $verifier])->assertStatus(400)->assertJsonPath('error', 'invalid_request');
    }

    public function test_the_token_endpoint_takes_form_bodies_and_nothing_from_the_query_string(): void
    {
        $verifier = $this->verifier();
        $code = $this->parts($this->approve($this->handleOf($this->authorize($verifier)->assertStatus(302)), $this->id['u1']))['code'];

        // JSON is not what the standard asks for here.
        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CLAUDE, 'code_verifier' => $verifier])->assertStatus(400)->assertJsonPath('error', 'invalid_request');

        // Parameters in the query string are not read.
        $this->post('/oauth/token?'.http_build_query(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CLAUDE, 'code_verifier' => $verifier]))
            ->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');

        $response = $this->token(['grant_type' => 'client_credentials'])->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // ── Refreshing ──────────────────────────────────────────────────────────

    private function refresh(string $refreshToken): TestResponse
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => 'claude']);
    }

    public function test_a_refresh_gives_the_next_pair_and_slides_the_connection_forward(): void
    {
        $first = $this->connect($this->id['u1'])['tokens'];
        $endsBefore = McpConnection::query()->firstOrFail()->expires_at;

        Carbon::setTestNow(now()->addDays(10));
        $second = $this->refresh($first['refresh_token'])->assertOk()->json();

        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertTrue(McpConnection::query()->firstOrFail()->expires_at->gt($endsBefore), 'it lasts from the last time it was used');
        $this->mcp('tools/list', $second['access_token'])->assertOk();
        $this->assertSame(2, McpOauthToken::query()->count());
    }

    public function test_an_access_token_that_has_run_out_is_a_plain_401_that_is_never_counted_and_a_refresh_fixes_it(): void
    {
        config(['fhmcp.ip_failures' => 2]);
        $first = $this->connect($this->id['u1'])['tokens'];

        Carbon::setTestNow(now()->addHours(9));

        foreach (range(1, 5) as $_) {
            $this->mcp('tools/list', $first['access_token'])->assertStatus(401);
        }

        $second = $this->refresh($first['refresh_token'])->assertOk()->json();
        $this->mcp('tools/list', $second['access_token'])->assertOk();
    }

    public function test_two_refreshes_with_the_same_token_at_the_same_moment_both_get_a_pair(): void
    {
        $first = $this->connect($this->id['u1'])['tokens'];

        // Claude has been seen to send the same refresh twice a few milliseconds apart.
        $one = $this->refresh($first['refresh_token'])->assertOk()->json();
        $two = $this->refresh($first['refresh_token'])->assertOk()->json();

        $this->assertNotSame($one['refresh_token'], $two['refresh_token']);
        $this->mcp('tools/list', $one['access_token'])->assertOk();
        $this->mcp('tools/list', $two['access_token'])->assertOk();
    }

    public function test_a_lost_answer_can_be_asked_for_again_until_the_newer_token_has_been_used(): void
    {
        $first = $this->connect($this->id['u1'])['tokens'];

        $this->refresh($first['refresh_token'])->assertOk();           // the answer is lost: nobody ever uses it
        Carbon::setTestNow(now()->addHours(3));
        $again = $this->refresh($first['refresh_token'])->assertOk()->json(); // hours later, Claude asks again with the old one

        $this->mcp('tools/list', $again['access_token'])->assertOk();
        $this->assertSame(1, McpConnection::query()->active()->count(), 'the connection was not ended');
    }

    public function test_an_old_refresh_token_presented_after_the_newer_pair_was_used_ends_the_connection(): void
    {
        $first = $this->connect($this->id['u1'])['tokens'];
        $second = $this->refresh($first['refresh_token'])->assertOk()->json();

        // The holder of the newer pair moves on...
        $this->mcp('tools/list', $second['access_token'])->assertOk();

        // ...and later someone presents the old token: it is in other hands.
        Carbon::setTestNow(now()->addMinutes(10));
        $this->refresh($first['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        $connection = McpConnection::query()->firstOrFail();
        $this->assertSame('refresh_reuse', $connection->revoked_reason);
        $this->assertNotNull($connection->revoked_at);

        // Everything the connection held is dead, the newer pair included.
        $this->mcp('tools/list', $second['access_token'])->assertStatus(401);
        $this->refresh($second['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_a_late_repeat_within_a_couple_of_minutes_is_a_retry_even_after_the_newer_pair_was_used(): void
    {
        $first = $this->connect($this->id['u1'])['tokens'];
        $second = $this->refresh($first['refresh_token'])->assertOk()->json();
        $this->mcp('tools/list', $second['access_token'])->assertOk();

        Carbon::setTestNow(now()->addSeconds(60));
        $this->refresh($first['refresh_token'])->assertOk();
        $this->assertNull(McpConnection::query()->firstOrFail()->revoked_at);
    }

    public function test_refreshing_is_refused_for_something_that_is_not_a_refresh_token_and_for_another_client(): void
    {
        $first = $this->connect($this->id['u1'])['tokens'];

        foreach (['', 'nothing', $first['access_token'], 'fhort_'.str_repeat('q', 64)] as $bad) {
            $this->refresh($bad)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        }

        $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => 'claude-code'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_a_connection_that_has_run_out_of_time_cannot_be_refreshed(): void
    {
        $first = $this->connect($this->id['u1'])['tokens'];

        Carbon::setTestNow(now()->addDays(61));
        $this->refresh($first['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->mcp('tools/list', $first['access_token'])->assertStatus(401);
    }

    // ── Who the person is ───────────────────────────────────────────────────

    public function test_a_person_who_loses_their_role_gets_403_and_one_whose_role_changed_has_to_connect_again(): void
    {
        $made = $this->connect($this->id['u1'])['tokens'];
        $this->mcp('tools/list', $made['access_token'])->assertOk();

        // Switched off: no role, so the connection opens nothing, and a refresh is refused.
        DB::table('agents')->where('id', $this->id['a1'])->update(['status' => 'terminated']);
        $this->mcp('tools/list', $made['access_token'])->assertStatus(403);
        $this->refresh($made['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        DB::table('agents')->where('id', $this->id['a1'])->update(['status' => 'active']);
        $this->mcp('tools/list', $made['access_token'])->assertOk();

        // Made a team leader: what Claude can see is no longer what they agreed to, so it ends and asks again.
        DB::table('team_agents')->where('agent_id', $this->id['a1'])->update(['is_leader' => true]);
        $this->mcp('tools/list', $made['access_token'])->assertStatus(401);
        $this->assertSame('role_changed', McpConnection::query()->firstOrFail()->revoked_reason);
        $this->refresh($made['refresh_token'])->assertStatus(400);

        // They connect again and are shown, and agree to, the team view.
        $again = $this->connect($this->id['u1'])['tokens'];
        $this->assertContains('team_roster', $this->toolsFor($again['access_token']));
        $this->assertSame('team_leader', McpConnection::query()->latest('id')->firstOrFail()->role);
    }

    public function test_the_switch_decides_who_may_connect_and_use_one_and_pausing_does_not_strand_the_connectors(): void
    {
        $agent = $this->connect($this->id['u1'])['tokens'];
        $admin = $this->connect($this->id['admin'])['tokens'];

        // Admins only: the documents and refreshes still answer, but only an admin can use or make a connection.
        OAuthSwitch::set(OAuthSwitch::ADMINS);
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk();
        $this->mcp('tools/list', $admin['access_token'])->assertOk();
        $this->mcp('tools/list', $agent['access_token'])->assertStatus(403);
        $refreshed = $this->refresh($agent['refresh_token'])->assertOk()->json();
        $this->mcp('tools/list', $refreshed['access_token'])->assertStatus(403);

        $handle = $this->handleOf($this->authorize($this->verifier())->assertStatus(302));
        $this->as($this->id['u3']);
        $this->getJson('/api/mcp/connect/requests/'.$handle)->assertOk()->assertJsonPath('reason', 'paused');
        $this->postJson('/api/mcp/connect/requests/'.$handle.'/approve')->assertStatus(403);

        // On again: the same connection works, nothing was lost.
        OAuthSwitch::set(OAuthSwitch::ON);
        $this->mcp('tools/list', $refreshed['access_token'])->assertOk();
        $this->assertSame(2, McpConnection::query()->active()->count());
    }

    public function test_claude_code_on_a_computer_is_for_admins_only_and_its_port_does_not_matter(): void
    {
        $verifier = $this->verifier();
        $loopback = fn (int $port) => $this->authorize($verifier, ['client_id' => 'claude-code', 'redirect_uri' => "http://localhost:{$port}/callback"]);

        $handle = $this->handleOf($loopback(54321)->assertStatus(302));
        $this->as($this->id['u1']);
        $this->getJson('/api/mcp/connect/requests/'.$handle)->assertOk()->assertJsonPath('client.kind', 'loopback')->assertJsonPath('reason', 'admins_only');
        $this->postJson('/api/mcp/connect/requests/'.$handle.'/approve')->assertStatus(403);

        // An admin may, on any port, and it takes a press and hold.
        $handle = $this->handleOf($loopback(61234)->assertStatus(302));
        $this->as($this->id['admin']);
        $this->getJson('/api/mcp/connect/requests/'.$handle)->assertOk()->assertJsonPath('press_and_hold', true)->assertJsonPath('allowed', true);
        $back = $this->parts($this->approve($handle, $this->id['admin']));

        $access = (string) $this->token([
            'grant_type' => 'authorization_code', 'code' => $back['code'], 'redirect_uri' => 'http://localhost:61234/callback', 'client_id' => 'claude-code', 'code_verifier' => $verifier,
        ])->assertOk()->json('access_token');

        $this->mcp('tools/list', $access)->assertOk();
        $this->assertSame('loopback', McpConnection::query()->firstOrFail()->client_kind);

        // The code is for the port it was asked for.
        $handle = $this->handleOf($loopback(40000)->assertStatus(302));
        $back = $this->parts($this->approve($handle, $this->id['admin']));
        $this->token(['grant_type' => 'authorization_code', 'code' => $back['code'], 'redirect_uri' => 'http://localhost:40001/callback', 'client_id' => 'claude-code', 'code_verifier' => $verifier])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    // ── Ending a connection ─────────────────────────────────────────────────

    public function test_the_person_an_admin_and_claude_can_each_end_a_connection_and_nobody_else_can(): void
    {
        $mine = $this->connect($this->id['u1'])['tokens'];
        $theirs = $this->connect($this->id['u3'])['tokens'];
        $third = $this->connect($this->id['u2'])['tokens'];
        $ids = McpConnection::query()->orderBy('id')->pluck('id')->all();

        // The person: their own list, their own to end, and not someone else's.
        $this->as($this->id['u1']);
        $this->getJson('/api/mcp/connect/status')->assertOk()->assertJsonCount(1, 'connections')->assertJsonPath('connections.0.id', $ids[0])->assertJsonPath('available', true);
        $this->deleteJson('/api/mcp/connect/connections/'.$ids[1])->assertNotFound();
        $this->deleteJson('/api/mcp/connect/connections/'.$ids[0])->assertOk()->assertJsonPath('data.state', 'revoked');
        $this->mcp('tools/list', $mine['access_token'])->assertStatus(401);
        $this->mcp('tools/list', $theirs['access_token'])->assertOk();

        // An agent is not an admin.
        $this->deleteJson('/api/admin/mcp-connections/'.$ids[1])->assertForbidden();

        // An admin.
        $this->as($this->id['admin']);
        $this->deleteJson('/api/admin/mcp-connections/'.$ids[1])->assertOk()->assertJsonPath('data.state', 'revoked');
        $this->mcp('tools/list', $theirs['access_token'])->assertStatus(401);
        $this->assertSame('admin', McpConnection::query()->find($ids[1])->revoked_reason);
        $this->assertSame($this->id['admin'], (int) McpConnection::query()->find($ids[1])->revoked_by);

        // Claude itself: it always answers 200, whatever it is told.
        $this->post('/oauth/revoke', ['token' => 'nonsense'])->assertOk();
        $this->post('/oauth/revoke', ['token' => $third['refresh_token']])->assertOk();
        $this->mcp('tools/list', $third['access_token'])->assertStatus(401);
        $this->assertSame('claude', McpConnection::query()->find($ids[2])->revoked_reason);
    }

    public function test_a_sixth_connection_retires_the_one_used_least_recently(): void
    {
        $made = [];

        foreach (range(1, 5) as $n) {
            $made[$n] = $this->connect($this->id['u1'])['tokens'];
            Carbon::setTestNow(now()->addMinutes(5));
        }

        // The second is used, so the first is the least recently used.
        $this->mcp('tools/list', $made[2]['access_token'])->assertOk();
        Carbon::setTestNow(now()->addMinutes(5));

        $sixth = $this->connect($this->id['u1'])['tokens'];

        $this->assertSame(5, McpConnection::query()->where('user_id', $this->id['u1'])->active()->count());
        $this->mcp('tools/list', $made[1]['access_token'])->assertStatus(401);
        $this->assertSame('replaced', McpConnection::query()->orderBy('id')->firstOrFail()->revoked_reason);
        $this->mcp('tools/list', $made[2]['access_token'])->assertOk();
        $this->mcp('tools/list', $sixth['access_token'])->assertOk();
    }

    public function test_connections_do_not_count_toward_the_three_keys_a_person_may_hold(): void
    {
        foreach (range(1, 4) as $_) {
            $this->connect($this->id['u1']);
        }

        foreach (range(1, 3) as $n) {
            McpKeys::issue(User::query()->findOrFail($this->id['u1']), "key {$n}");
        }

        $this->assertSame(4, McpConnection::query()->active()->count());
    }

    // ── The admin's side ────────────────────────────────────────────────────

    public function test_only_an_admin_can_reach_the_switch_and_the_connections(): void
    {
        $paths = [
            ['get', '/api/admin/mcp-oauth'], ['put', '/api/admin/mcp-oauth'], ['post', '/api/admin/mcp-oauth/notify-address-change'],
            ['get', '/api/admin/mcp-connections'], ['delete', '/api/admin/mcp-connections/1'], ['post', '/api/admin/mcp-connections/disconnect-all'],
        ];

        foreach ($paths as [$method, $path]) {
            $this->app['auth']->forgetGuards();
            $this->{$method.'Json'}($path)->assertUnauthorized();
        }

        foreach ([$this->id['u1'], $this->makeUser(self::SECRETARY), $this->id['c1']] as $userId) {
            foreach ($paths as [$method, $path]) {
                $this->as($userId);
                $this->{$method.'Json'}($path, $method === 'put' ? ['mode' => 'on'] : [])->assertForbidden();
            }
        }

        $this->assertSame('on', OAuthSwitch::mode(), 'nothing was changed');
    }

    public function test_the_switch_is_one_of_three_values_set_by_an_admin_and_says_how_things_stand(): void
    {
        $this->as($this->id['admin']);

        $this->getJson('/api/admin/mcp-oauth')->assertOk()
            ->assertJsonPath('mode', 'on')
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('address_usable', true)
            ->assertJsonPath('address', self::SERVER)
            ->assertJsonPath('consent_url', self::CONSENT)
            ->assertJsonPath('connections.active', 0);

        $this->putJson('/api/admin/mcp-oauth', ['mode' => 'admins'])->assertOk()->assertJsonPath('mode', 'admins');
        $this->assertSame('admins', OAuthSwitch::mode());

        $this->putJson('/api/admin/mcp-oauth', ['mode' => 'maybe'])->assertStatus(422);
        $this->putJson('/api/admin/mcp-oauth', [])->assertStatus(422);
        $this->assertSame('admins', OAuthSwitch::mode());

        // On an address Claude cannot use, it says so: the switch is set, and still nothing answers.
        config(['fhmcp.public_url' => 'http://api.example.test']);
        $this->getJson('/api/admin/mcp-oauth')->assertOk()->assertJsonPath('mode', 'admins')->assertJsonPath('address_usable', false)->assertJsonPath('enabled', false);
    }

    public function test_the_connections_list_says_who_what_and_when_never_a_token_and_can_be_searched(): void
    {
        DB::table('users')->where('id', $this->id['u1'])->update(['name' => 'Maria Santos']);
        DB::table('users')->where('id', $this->id['u3'])->update(['name' => 'Pedro Reyes']);

        $maria = $this->connect($this->id['u1'])['tokens'];
        $this->connect($this->id['u3']);
        $this->mcp('tools/call', $maria['access_token'], ['name' => 'my_metric', 'arguments' => (object) ['metric' => 'listings.total']])->assertOk();

        $this->as($this->id['admin2']);
        $list = $this->getJson('/api/admin/mcp-connections')->assertOk();

        $rows = collect($list->json('data'));
        $this->assertSame(['Pedro Reyes', 'Maria Santos'], $rows->pluck('person.name')->all(), 'newest first');
        $this->assertSame(['id', 'person', 'role', 'client', 'state', 'needs_reconnect', 'created_at', 'last_used_at', 'expires_at', 'revoked_at', 'revoked_reason', 'calls_7d'], array_keys($rows->first()));
        $this->assertSame(['agent', 'agent'], $rows->pluck('role')->all());
        $this->assertSame(['Claude', 'Claude'], $rows->pluck('client')->all());
        $this->assertSame([0, 1], $rows->pluck('calls_7d')->all());
        $this->assertSame(['total' => 2, 'page' => 1, 'per_page' => 25, 'last_page' => 1], $list->json('meta'));
        $this->assertStringNotContainsString($maria['access_token'], $list->getContent());
        $this->assertStringNotContainsString($maria['refresh_token'], $list->getContent());
        $this->assertStringNotContainsString('hash', $list->getContent());

        $found = collect($this->getJson('/api/admin/mcp-connections?q=maria')->assertOk()->json('data'));
        $this->assertSame(['Maria Santos'], $found->pluck('person.name')->all());
        $this->assertCount(0, $this->getJson('/api/admin/mcp-connections?q=nobody')->json('data'));
        $this->assertCount(2, $this->getJson('/api/admin/mcp-connections?q=%25')->json('data') === [] ? [1, 2] : [], 'a percent sign is a character, not a wildcard');
    }

    public function test_an_admin_can_end_every_connection_at_once_and_it_is_not_a_side_effect_of_the_switch(): void
    {
        $made = [$this->connect($this->id['u1'])['tokens'], $this->connect($this->id['u3'])['tokens'], $this->connect($this->id['admin'])['tokens']];

        $this->as($this->id['admin2']);
        $this->putJson('/api/admin/mcp-oauth', ['mode' => 'off'])->assertOk();
        $this->putJson('/api/admin/mcp-oauth', ['mode' => 'on'])->assertOk();
        $this->assertSame(3, McpConnection::query()->active()->count(), 'turning it off and on ended nothing');

        $this->postJson('/api/admin/mcp-connections/disconnect-all')->assertOk()->assertJsonPath('ended', 3);

        foreach ($made as $tokens) {
            $this->mcp('tools/list', $tokens['access_token'])->assertStatus(401);
        }

        $this->assertSame(['admin'], McpConnection::query()->pluck('revoked_reason')->unique()->values()->all());
        $this->assertSame([$this->id['admin2']], McpConnection::query()->pluck('revoked_by')->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $this->as($this->id['admin2']); // the calls above signed the test's own user out
        $this->postJson('/api/admin/mcp-connections/disconnect-all')->assertOk()->assertJsonPath('ended', 0);
    }

    // ── A change of address ─────────────────────────────────────────────────

    public function test_a_change_of_address_keeps_old_connections_working_while_it_is_listed_asks_people_to_reconnect_and_can_tell_them_once(): void
    {
        $old = $this->connect($this->id['u1'])['tokens'];
        $key = McpKeys::issue(User::query()->findOrFail($this->id['u3']), 'laptop');
        $this->assertSame(self::SERVER, McpConnection::query()->firstOrFail()->resource);

        $new = 'https://new.example.test';
        config(['fhmcp.public_url' => $new, 'fhmcp.former_urls' => [self::API]]);

        // The old address is listed, so the connection works, and the person is told to reconnect.
        $this->mcp('tools/list', $old['access_token'])->assertOk();
        $this->refresh($old['refresh_token'])->assertOk();
        $this->as($this->id['u1']);
        $status = $this->getJson('/api/mcp/connect/status')->assertOk();
        $status->assertJsonPath('address', $new.'/mcp/filipinohomes')->assertJsonPath('connections.0.needs_reconnect', true);
        $this->assertStringContainsString(rawurlencode($new.'/mcp/filipinohomes'), $status->json('connect_link'));

        // Someone still adding the connector at the old address can finish, and one adding it at the new one can too.
        $verifier = $this->verifier();
        $this->authorize($verifier, ['resource' => self::SERVER])->assertStatus(302);
        $this->authorize($verifier, ['resource' => $new.'/mcp/filipinohomes'])->assertStatus(302);

        // The admin sees who is touched: the person with the old connection, and the key holder (they typed the old address into Claude).
        $this->as($this->id['admin']);
        $this->getJson('/api/admin/mcp-oauth')->assertOk()
            ->assertJsonPath('former_addresses', [self::API])
            ->assertJsonPath('connections.needing_reconnect', 1)
            ->assertJsonPath('affected_people', 2);

        $this->postJson('/api/admin/mcp-oauth/notify-address-change')->assertOk()->assertJsonPath('notified', 2)->assertJsonPath('already_notified', 0);
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->id['u1'])->where('title', 'Claude address changed')->count());
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->id['u3'])->where('title', 'Claude address changed')->count());
        $this->assertStringNotContainsString('Authorize', (string) DB::table('app_notifications')->where('title', 'Claude address changed')->value('body'));

        // Once for each person.
        $this->postJson('/api/admin/mcp-oauth/notify-address-change')->assertOk()->assertJsonPath('notified', 0)->assertJsonPath('already_notified', 2);
        $this->assertSame(2, DB::table('app_notifications')->where('title', 'Claude address changed')->count());

        // When the old address stops being listed, what was made for it stops, and Claude is asked to sign in again.
        config(['fhmcp.former_urls' => []]);
        $this->mcp('tools/list', $old['access_token'])->assertStatus(401);
        $this->assertNotEmpty($key['key']);
    }

    // ── What is left behind ─────────────────────────────────────────────────

    public function test_the_clean_up_deletes_only_what_is_dead_and_can_run_twice(): void
    {
        $live = $this->connect($this->id['u1'])['tokens'];
        $this->authorize($this->verifier())->assertStatus(302); // a request nobody answers

        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(0, Artisan::call('fhmcp:prune'));
        $this->assertSame(0, McpOauthRequest::query()->where('expires_at', '>', now()->subDay())->count());
        $this->assertSame(0, McpOauthRequest::query()->count(), 'requests that expired a day ago are gone');

        Carbon::setTestNow(now()->addDays(160)); // it ran out at sixty days: a hundred days ago
        $this->assertSame(0, Artisan::call('fhmcp:prune'));
        $this->assertSame(0, McpConnection::query()->count(), 'a connection that ran out three months ago is gone, with its tokens');
        $this->assertSame(0, McpOauthToken::query()->count());

        $this->assertSame(0, Artisan::call('fhmcp:prune'), 'twice is fine');
        $this->assertNotEmpty($live);
    }

    public function test_a_live_connection_is_not_touched_by_the_clean_up(): void
    {
        $live = $this->connect($this->id['u1'])['tokens'];

        Carbon::setTestNow(now()->addDays(3));
        Artisan::call('fhmcp:prune');

        $this->assertSame(1, McpConnection::query()->active()->count());
        $this->refresh($live['refresh_token'])->assertOk();
    }
}
