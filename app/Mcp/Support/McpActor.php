<?php

namespace App\Mcp\Support;

use App\Models\McpConnection;
use App\Models\McpKey;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Who is calling a tool: the person behind the key, the role they act as, and
 * for an agent (or a team leader, who is one too) their own agent id, which
 * every number they ask for as themselves is forced to. For a team leader also
 * the teams they lead, which every team call is held to. The key or the
 * connection (a person's Claude, signed in) the call came through. Built from what
 * AuthenticateMcpKey established for this request, so a tool never decides for
 * itself who the caller is.
 */
final class McpActor
{
    /** @param  list<int>  $teamIds  the teams a team leader leads (empty for everyone else) */
    public function __construct(
        public readonly User $user,
        public readonly McpRole $role,
        public readonly ?McpKey $key,
        public readonly ?int $agentId,
        public readonly array $teamIds = [],
        public readonly ?McpConnection $connection = null,
    ) {}

    /** The caller of this request, or null when there is no one (or no one who may use the server). */
    public static function current(): ?self
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        $role = McpRole::of($user);

        if ($role === null) {
            return null;
        }

        $key = request()->attributes->get('mcp.key');
        $connection = request()->attributes->get('mcp.connection');

        return new self(
            $user,
            $role,
            $key instanceof McpKey ? $key : null,
            $role === McpRole::Admin ? null : $user->agent?->id,
            $role === McpRole::TeamLeader ? McpTeams::ledBy($user) : [],
            $connection instanceof McpConnection ? $connection : null,
        );
    }
}
