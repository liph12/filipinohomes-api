<?php

namespace App\Mcp\Tools\Admin;

use App\Mcp\Policies\AdminPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Tools\Kinds\GuideTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('fh_guide')]
#[Title('Filipino Homes: what can I ask?')]
#[Description('Everything an admin can ask about Filipino Homes, by topic (website traffic, AI visibility, client journey and missed replies, inquiries, leads, listings and prices, deals, audit and Authority to Sell, agents, teams), with what each covers and example questions. Call it whenever someone asks what they can ask, what data there is, or what you can do with Filipino Homes, and show all of it. Read-only.')]
final class FhGuide extends GuideTool
{
    protected function policy(): McpPolicy
    {
        return new AdminPolicy;
    }
}
