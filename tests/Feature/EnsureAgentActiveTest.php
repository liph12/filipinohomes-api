<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAgentActive;
use App\Models\Agent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Unit-level checks for the agent.active middleware: blocked statuses get a
 * 403 everywhere except the allowlist (profile reads, logout, impersonate
 * stop), active agents and admins pass, guests pass. Models are built
 * in-memory (no DB) so this runs without migrations.
 */
class EnsureAgentActiveTest extends TestCase
{
    private function requestAs(?User $user, string $method, string $uri): Request
    {
        $request = Request::create($uri, $method);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function agentUser(string $status, string $roleName = 'agent'): User
    {
        $role = (new Role())->forceFill(['name' => $roleName]);
        $agent = (new Agent())->forceFill(['status' => $status]);

        $user = new User();
        $user->setRelation('role', $role);
        $user->setRelation('agent', $agent);

        return $user;
    }

    private function run_(Request $request): int
    {
        $response = (new EnsureAgentActive())->handle($request, fn () => response()->json(['ok' => true]));

        return $response->getStatusCode();
    }

    public function test_resigned_agent_is_blocked_from_normal_routes(): void
    {
        $user = $this->agentUser('resigned');

        $this->assertSame(403, $this->run_($this->requestAs($user, 'POST', '/api/listings')));
        $this->assertSame(403, $this->run_($this->requestAs($user, 'GET', '/api/my-listings')));
        $this->assertSame(403, $this->run_($this->requestAs($user, 'GET', '/api/chats')));
    }

    public function test_inactive_agent_is_blocked_from_normal_routes(): void
    {
        $user = $this->agentUser('inactive');

        $this->assertSame(403, $this->run_($this->requestAs($user, 'POST', '/api/upload')));
    }

    public function test_blocked_agent_can_still_see_profile_and_logout(): void
    {
        $user = $this->agentUser('resigned');

        $this->assertSame(200, $this->run_($this->requestAs($user, 'GET', '/api/agent/profile')));
        $this->assertSame(200, $this->run_($this->requestAs($user, 'GET', '/api/user/profile')));
        $this->assertSame(200, $this->run_($this->requestAs($user, 'GET', '/api/authenticate')));
        $this->assertSame(200, $this->run_($this->requestAs($user, 'POST', '/api/logout')));
        $this->assertSame(200, $this->run_($this->requestAs($user, 'POST', '/api/logout-all')));
        $this->assertSame(200, $this->run_($this->requestAs($user, 'POST', '/api/admin/impersonate/stop')));
    }

    public function test_blocked_agent_cannot_post_to_profile_store_route(): void
    {
        // Only GET /api/agent/profile is allowlisted; the POST variant (store)
        // must stay blocked.
        $user = $this->agentUser('resigned');

        $this->assertSame(403, $this->run_($this->requestAs($user, 'POST', '/api/agent/profile')));
    }

    public function test_active_agent_passes(): void
    {
        $user = $this->agentUser('active');

        $this->assertSame(200, $this->run_($this->requestAs($user, 'POST', '/api/listings')));
    }

    public function test_admin_is_exempt_even_with_blocked_agent_row(): void
    {
        $user = $this->agentUser('resigned', 'admin');

        $this->assertSame(200, $this->run_($this->requestAs($user, 'POST', '/api/listings')));
    }

    public function test_guest_and_agentless_user_pass(): void
    {
        $this->assertSame(200, $this->run_($this->requestAs(null, 'GET', '/api/listings')));

        $noAgent = new User();
        $noAgent->setRelation('role', (new Role())->forceFill(['name' => 'agent']));
        $noAgent->setRelation('agent', null);
        $this->assertSame(200, $this->run_($this->requestAs($noAgent, 'GET', '/api/my-listings')));
    }

    public function test_blocked_response_carries_status_and_message(): void
    {
        $user = $this->agentUser('resigned');
        $response = (new EnsureAgentActive())->handle(
            $this->requestAs($user, 'POST', '/api/listings'),
            fn () => response()->json(['ok' => true]),
        );

        $this->assertSame('resigned', $response->getData(true)['account_status']);
        $this->assertStringContainsString('resigned', $response->getData(true)['message']);
    }
}
