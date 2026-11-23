<?php

namespace App\Mcp\Tools\Admin;

use App\Mcp\Policies\AdminPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Tools\Kinds\LookupTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('fh_lookup')]
#[Title('Filipino Homes: find an id by name')]
#[Description('Find the id of a team, project, office region, province, city or property type from part of its name, to use as a filter in the other tools. Read-only.')]
final class FhLookup extends LookupTool
{
    protected function policy(): McpPolicy
    {
        return new AdminPolicy;
    }
}
