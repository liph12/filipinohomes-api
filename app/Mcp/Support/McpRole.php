<?php

namespace App\Mcp\Support;

use App\Models\User;

/**
 * Who an MCP key acts as. Three kinds of person may use the MCP server at all,
 * and they get different tools (see FilipinoHomesServer):
 *
 *  - Admin: company-wide reports, no scope added.
 *  - Team leader: their own team and the people in it, and their own numbers
 *    like any agent. Every team call is held to the teams they lead.
 *  - Agent: their own numbers only. Every call is forced to their agent id.
 *
 * Anyone else (clients, editors, secretaries, an agent whose account is not
 * active, a user with no agent row) has no role here and gets no tools. The
 * role is read from the person every time a key is used, never kept on the
 * key, so demoting or switching off an account cuts its keys at once. A team
 * leader is an agent who leads an active team (McpTeams): FH has no such role
 * of its own, so it comes and goes with the team data.
 */
enum McpRole: string
{
    case Admin = 'admin';
    case TeamLeader = 'team_leader';
    case Agent = 'agent';

    public static function of(User $user): ?self
    {
        $role = $user->role?->name;

        if ($role === 'admin') {
            return self::Admin;
        }

        // An agent needs an agent row (their id is their scope) and it has to be an active one.
        if ($role === 'agent' && $user->agent?->status === 'active') {
            return McpTeams::leads($user) ? self::TeamLeader : self::Agent;
        }

        return null;
    }

    /**
     * Whether this role is shown the tools made for $other. A team leader is
     * an agent too, so they keep the agent's own-numbers tools and gain the
     * team's; nobody else is shown more than their own role's.
     */
    public function covers(self $other): bool
    {
        return $this === $other || ($this === self::TeamLeader && $other === self::Agent);
    }
}
