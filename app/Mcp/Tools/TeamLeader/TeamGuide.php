<?php

namespace App\Mcp\Tools\TeamLeader;

use App\Mcp\Policies\McpPolicy;
use App\Mcp\Policies\TeamLeaderPolicy;
use App\Mcp\Tools\Kinds\GuideTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('team_guide')]
#[Title('My team: what can I ask?')]
#[Description('Everything a team leader can ask about their team and its members (and their own numbers), by topic, with what each covers and example questions. Call it whenever someone asks what they can ask, what data there is, or what you can do with Filipino Homes, and show all of it. Read-only.')]
final class TeamGuide extends GuideTool
{
    protected function policy(): McpPolicy
    {
        return new TeamLeaderPolicy;
    }
}
