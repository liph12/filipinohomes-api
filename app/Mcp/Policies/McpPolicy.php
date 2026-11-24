<?php

namespace App\Mcp\Policies;

use App\Mcp\Support\McpActor;
use App\Mcp\Support\McpRole;

/**
 * Everything that differs between an admin, a team leader and an agent on the
 * MCP server, in one place: which numbers they may ask for, which filters and
 * splits they may name, which names they may look up, and what is forced onto
 * (or held around) every ask they make. The tools are the same code for all
 * of them; this is the whole difference, so it is the one thing to read to
 * know who can see what.
 */
interface McpPolicy
{
    public function role(): McpRole;

    /** Whether this person may ask for this metric at all. */
    public function allowsMetric(string $metric): bool;

    /**
     * The filters this person may name in a tool call. A filter that is not
     * here is not in the tool's schema and is dropped if sent anyway.
     *
     * @return list<string>
     */
    public function filters(): array;

    /**
     * Filters put on every ask whatever was sent: they win over the caller's
     * own, so an agent cannot widen a number past themselves.
     *
     * @return array<string, int|string>
     */
    public function forced(McpActor $actor): array;

    /**
     * The last word on one ask's filters, after the caller's own and the forced
     * ones are laid together: a policy that holds a person to a set (a team
     * leader's teams) fills in what they left out and refuses what is outside
     * it, by throwing InvalidArgumentException with a message the caller can act
     * on. The others return the filters as they are.
     *
     * @param  array<string, int|string>  $filters
     * @return array<string, int|string>
     *
     * @throws \InvalidArgumentException
     */
    public function constrain(array $filters, McpActor $actor): array;

    /**
     * The splits (breakdown dimensions) this person may ask for.
     *
     * @return list<string>
     */
    public function dimensions(): array;

    /**
     * The kinds of name this person may look up.
     *
     * @return list<string>
     */
    public function lookups(): array;
}
