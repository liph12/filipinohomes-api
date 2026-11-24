<?php

namespace App\Mcp\Tools\TeamLeader;

use App\Mcp\Policies\McpPolicy;
use App\Mcp\Policies\TeamLeaderPolicy;
use App\Mcp\Tools\Kinds\RosterTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('team_roster')]
#[Title('My team: who is in it')]
#[Description('The teams you lead and the people in each: their ids (to use as agent_id in the other team tools), their names, and whether the team\'s numbers count them. Read-only.')]
final class TeamRoster extends RosterTool
{
    protected function policy(): McpPolicy
    {
        return new TeamLeaderPolicy;
    }
}
