<?php

namespace App\Mcp\Support;

use App\Models\McpCall;
use Illuminate\Support\Str;
use Throwable;

/**
 * The record of what the MCP server was asked: one row for every tool a key
 * ran, with the person, the tool, what it was asked (without anything that
 * looks like a secret) and how it went.
 *
 * This is the only place the MCP code writes to the database, apart from a
 * key's "last used" stamp (AuthenticateMcpKey). A failure to write the record
 * is reported and never takes the answer down with it: the tools only read.
 */
final class McpAudit
{
    private const ARGUMENTS_MAX = 2000;

    /** @param  array<string, mixed>  $arguments  what the tool was asked */
    public static function record(McpActor $actor, string $tool, array $arguments, string $outcome, ?string $error, int $milliseconds, int $bytes): void
    {
        try {
            McpCall::query()->create([
                'mcp_key_id' => $actor->key?->id,
                'user_id' => $actor->user->id,
                'role' => $actor->role->value,
                'tool' => Str::limit($tool, 80, ''),
                'arguments' => self::compact($arguments),
                'outcome' => $outcome,
                'error' => $error === null ? null : Str::limit($error, 300, ''),
                'duration_ms' => $milliseconds,
                'bytes' => $bytes,
                'ip' => request()->ip(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * What was asked, as it is kept: plain values only, long ones cut, an email
     * or a mobile number typed into a search blanked (McpOutput), and an
     * enormous ask reduced to a note, so the record can't be used to stash
     * anything.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private static function compact(array $arguments): array
    {
        $kept = [];

        foreach ($arguments as $name => $value) {
            $kept[$name] = is_scalar($value) || $value === null
                ? (is_string($value) ? Str::limit($value, 200, '…') : $value)
                : '(not a plain value)';
        }

        $kept = McpOutput::clean($kept);

        return strlen((string) json_encode($kept)) > self::ARGUMENTS_MAX ? ['note' => 'arguments too long to keep'] : $kept;
    }
}
