<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/**
 * Who may change teams and who is on them. A team's leader decides who sees the
 * team's numbers, listings and inquiries, so creating, renaming and deleting
 * teams and adding, moving or removing members (and naming the leader) is for
 * admins, like the pages that do it. Reading stays open to a signed-in agent.
 *
 * The shared fixture (seedExecutiveScenario()): team Alpha is a1 (leader) and
 * a2, Bravo is a3 alone, Charlie is a2 alone.
 */
class TeamWritesAdminOnlyTest extends TestCase
{
    use CreatesExecutiveReportTables;

    /** @var array<string, int> */
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->createExecutiveReportTables();

        $this->id = $this->seedExecutiveScenario();
        $this->id['admin'] = $this->makeUser(self::ADMIN, ['name' => 'The Boss', 'email' => 'boss@example.com']);
    }

    private function as(int $userId): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::query()->findOrFail($userId));
    }

    private function leaderOf(int $team): ?int
    {
        $agent = DB::table('team_agents')->where('team_id', $team)->where('is_leader', true)->value('agent_id');

        return $agent === null ? null : (int) $agent;
    }

    private function rowOf(int $agent, int $team): int
    {
        return (int) DB::table('team_agents')->where('team_id', $team)->where('agent_id', $agent)->value('id');
    }

    public function test_an_agent_cannot_change_who_is_on_a_team_or_who_leads_it(): void
    {
        $bravoRow = $this->rowOf($this->id['a3'], $this->id['bravo']);
        $leaderRow = $this->rowOf($this->id['a1'], $this->id['alpha']);

        // a3 is a plain agent on Bravo. None of these works, and the leader of Alpha is still a1.
        $this->as($this->id['u3']);
        $this->postJson('/api/team-agents', ['team_id' => $this->id['alpha'], 'agent_id' => $this->id['a3'], 'is_leader' => true])->assertForbidden();
        $this->patchJson('/api/team-agents/'.$bravoRow, ['team_id' => $this->id['alpha'], 'is_leader' => true])->assertForbidden();
        $this->deleteJson('/api/team-agents/'.$leaderRow)->assertForbidden();

        $this->assertSame($this->id['a1'], $this->leaderOf($this->id['alpha']));
        $this->assertSame($this->id['bravo'], (int) DB::table('team_agents')->where('id', $bravoRow)->value('team_id'));
        $this->assertSame(0, DB::table('team_agents')->where('agent_id', $this->id['a3'])->where('team_id', $this->id['alpha'])->count());
        $this->assertTrue(DB::table('team_agents')->where('id', $leaderRow)->exists());
    }

    public function test_an_agent_cannot_create_rename_or_delete_a_team(): void
    {
        $name = (string) DB::table('teams')->where('id', $this->id['alpha'])->value('name');
        $teams = DB::table('teams')->count();

        $this->as($this->id['u3']);
        $this->postJson('/api/teams', ['name' => 'Mine'])->assertForbidden();
        $this->patchJson('/api/teams/'.$this->id['alpha'], ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson('/api/teams/'.$this->id['alpha'])->assertForbidden();

        $this->assertSame($teams, DB::table('teams')->count());
        $this->assertSame($name, (string) DB::table('teams')->where('id', $this->id['alpha'])->value('name'));
    }

    public function test_a_team_leader_is_not_an_admin_either(): void
    {
        // a1 leads Alpha. Leading a team is not a say over who is on it.
        $this->as($this->id['u1']);
        $this->postJson('/api/team-agents', ['team_id' => $this->id['alpha'], 'agent_id' => $this->id['a3']])->assertForbidden();
        $this->deleteJson('/api/team-agents/'.$this->rowOf($this->id['a2'], $this->id['alpha']))->assertForbidden();

        $this->assertSame(2, DB::table('team_agents')->where('team_id', $this->id['alpha'])->count());
    }

    public function test_an_admin_can_still_do_all_of_it(): void
    {
        $this->as($this->id['admin']);

        $created = $this->postJson('/api/teams', ['name' => 'Delta'])->assertSuccessful()->json('data.id');
        $this->assertNotNull($created);
        $this->patchJson('/api/teams/'.$created, ['name' => 'Delta Force'])->assertSuccessful();
        $this->assertSame('Delta Force', DB::table('teams')->where('id', $created)->value('name'));

        $this->postJson('/api/team-agents', ['team_id' => $created, 'agent_id' => $this->id['a3'], 'is_leader' => true])->assertSuccessful();
        $this->assertSame($this->id['a3'], $this->leaderOf($created));

        $row = $this->rowOf($this->id['a3'], $created);
        $this->patchJson('/api/team-agents/'.$row, ['is_leader' => false])->assertSuccessful();
        $this->assertNull($this->leaderOf($created));

        $this->deleteJson('/api/team-agents/'.$row)->assertSuccessful();
        $this->deleteJson('/api/teams/'.$created)->assertSuccessful();
        $this->assertFalse(DB::table('teams')->where('id', $created)->exists());
    }

    public function test_reading_teams_stays_open_to_a_signed_in_agent(): void
    {
        $this->as($this->id['u3']);

        $this->getJson('/api/teams')->assertOk();
        $this->getJson('/api/teams/'.$this->id['alpha'])->assertOk();
        $this->getJson('/api/team-agents')->assertOk();
        $this->getJson('/api/team-agents/'.$this->rowOf($this->id['a1'], $this->id['alpha']))->assertOk();
    }

    public function test_nobody_signed_out_can_reach_any_of_it(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/teams')->assertUnauthorized();
        $this->postJson('/api/team-agents', ['team_id' => $this->id['alpha'], 'agent_id' => $this->id['a3']])->assertUnauthorized();
    }
}
