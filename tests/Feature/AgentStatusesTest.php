<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Role;
use App\Models\User;
use App\Services\Listing\AgentInsightsService;
use Tests\TestCase;

/**
 * The one definition of which statuses an agent can have and which of them
 * switch the account off. Models are built in memory (no DB), like
 * EnsureAgentActiveTest.
 */
class AgentStatusesTest extends TestCase
{
    private function agentWith(string $status, string $roleName = 'agent'): Agent
    {
        $user = new User();
        $user->setRelation('role', (new Role())->forceFill(['name' => $roleName]));

        $agent = (new Agent())->forceFill(['status' => $status]);
        $agent->setRelation('user', $user);

        return $agent;
    }

    public function test_the_status_list_matches_the_latest_enum_migration(): void
    {
        // agents.status is a MySQL enum altered by raw SQL, so nothing else
        // ties it to Agent::STATUSES — without this, adding a status in one
        // place and not the other only shows up as a 500 in production.
        $files = glob(database_path('migrations/*agents_status_enum*.php'));
        sort($files);
        $this->assertNotEmpty($files);

        // The first ENUM(...) in the file is up(); down() restates the old list.
        $this->assertSame(1, preg_match('/ENUM\(([^)]*)\)/i', (string) file_get_contents(end($files)), $match));
        $enum = array_map(fn (string $v) => trim($v, " '\""), explode(',', $match[1]));

        $this->assertSame($enum, Agent::STATUSES);
    }

    public function test_only_an_active_status_is_unblocked(): void
    {
        foreach (Agent::STATUSES as $status) {
            $this->assertSame($status !== 'active', $this->agentWith($status)->isBlocked(), $status);
        }
    }

    public function test_terminated_is_a_known_blocked_status(): void
    {
        $this->assertContains('terminated', Agent::STATUSES);
        $this->assertTrue($this->agentWith('terminated')->isBlocked());
    }

    public function test_a_status_added_later_is_blocked_by_default(): void
    {
        $this->assertTrue($this->agentWith('on_leave')->isBlocked());
        $this->assertTrue(Agent::isBlockedStatus('on_leave'));
    }

    public function test_no_status_means_no_agent_row_and_is_not_blocked(): void
    {
        $this->assertFalse(Agent::isBlockedStatus(null));
    }

    public function test_an_admins_agent_row_is_never_blocked(): void
    {
        foreach (Agent::STATUSES as $status) {
            $this->assertFalse($this->agentWith($status, 'admin')->isBlocked(), $status);
        }
    }

    public function test_agent_insights_lists_terminated_and_invents_no_status(): void
    {
        $this->assertContains('terminated', AgentInsightsService::STATUSES);
        $this->assertSame([], array_diff(AgentInsightsService::STATUSES, Agent::STATUSES));
    }
}
