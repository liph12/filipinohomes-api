<?php

namespace App\Mcp\Support;

use App\Models\McpKey;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Who is calling a tool: the person behind the key, the role they act as, and
 * for an agent their own agent id, which every number they ask for is forced
 * to. Built from what AuthenticateMcpKey established for this request, so a
 * tool never decides for itself who the caller is.
 */
final class McpActor
{
    public function __construct(
        public readonly User $user,
        public readonly McpRole $role,
        public readonly ?McpKey $key,
        public readonly ?int $agentId,
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

        return new self($user, $role, $key instanceof McpKey ? $key : null, $role === McpRole::Agent ? $user->agent?->id : null);
    }
}
