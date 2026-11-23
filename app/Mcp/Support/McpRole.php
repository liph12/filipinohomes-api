<?php

namespace App\Mcp\Support;

use App\Models\User;

/**
 * Who an MCP key acts as. Only two kinds of person may use the MCP server at
 * all, and they get different tools (see FilipinoHomesServer):
 *
 *  - Admin: company-wide reports, no scope added.
 *  - Agent: their own numbers only. Every call is forced to their agent id.
 *
 * Anyone else (clients, editors, secretaries, an agent whose account is not
 * active, a user with no agent row) has no role here and gets no tools. The
 * role is read from the person every time a key is used, never kept on the
 * key, so demoting or switching off an account cuts its keys at once.
 */
enum McpRole: string
{
    case Admin = 'admin';
    case Agent = 'agent';

    public static function of(User $user): ?self
    {
        $role = $user->role?->name;

        if ($role === 'admin') {
            return self::Admin;
        }

        // An agent needs an agent row (their id is their scope) and it has to be an active one.
        if ($role === 'agent' && $user->agent?->status === 'active') {
            return self::Agent;
        }

        return null;
    }
}
