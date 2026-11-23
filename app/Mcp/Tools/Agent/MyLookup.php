<?php

namespace App\Mcp\Tools\Agent;

use App\Mcp\Policies\AgentPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Tools\Kinds\LookupTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('my_lookup')]
#[Title('My Filipino Homes: find an id by name')]
#[Description('Find the id of a project, province, city or property type from part of its name, to use as a filter in your other tools. Read-only.')]
final class MyLookup extends LookupTool
{
    protected function policy(): McpPolicy
    {
        return new AgentPolicy;
    }
}
