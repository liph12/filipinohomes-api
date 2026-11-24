<?php

namespace App\Mcp\Tools\Agent;

use App\Mcp\Policies\AgentPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Support\McpRole;
use App\Mcp\Tools\Kinds\GuideTool;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('my_guide')]
#[Title('My numbers: what can I ask?')]
#[Description('Everything an agent can ask about their own work on Filipino Homes, by topic, with what each covers and example questions. Call it whenever someone asks what they can ask, what data there is, or what you can do with Filipino Homes, and show all of it. Read-only.')]
final class MyGuide extends GuideTool
{
    protected function policy(): McpPolicy
    {
        return new AgentPolicy;
    }

    /** A team leader is an agent too, but has team_guide, which already covers their own numbers: one guide each. */
    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return parent::shouldRegister($request)
            && ! ($user instanceof User && McpRole::of($user) === McpRole::TeamLeader);
    }
}
