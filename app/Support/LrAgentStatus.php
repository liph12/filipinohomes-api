<?php

namespace App\Support;

/**
 * Leuterio Realty's agent `status` — the top-level key of the login payload
 * (GET lr/v1/public/api/agent/{email}) — and what it means for an FH agent.
 *
 * Seen so far: `active`, `inactive`, `terminated`, and null (about one agent in
 * four). A value is only ever acted on when it appears here; anything else is
 * counted in the report and left alone, so a status LR invents tomorrow can't
 * demote anyone.
 *
 * `deactivated` is deliberately absent: in FH it is the dormancy status (45
 * days without activity, with its own guard and its own repair command), not
 * something LR owns.
 */
final class LrAgentStatus
{
    /**
     * The width of agents.lr_employment_status. LR's value is cut to this on
     * the way in: MySQL is in strict mode, so a longer one would throw — and an
     * agent that throws on every run sits first in the queue and starves
     * everyone behind it. A cut value is simply unrecognised, so it never acts.
     */
    public const MAX_LENGTH = 32;

    /** LR status => the agents.status it demotes an FH agent to. */
    private const DEMOTES_TO = [
        'inactive' => 'inactive',
        'resigned' => 'resigned',
        'terminated' => 'terminated',
    ];

    /**
     * How a status is stored and compared: trimmed, lowercased and capped to
     * MAX_LENGTH characters (not bytes, so a multibyte character is never cut
     * in half); nothing at all is ''.
     */
    public static function normalize(mixed $raw): string
    {
        return is_scalar($raw) ? mb_substr(mb_strtolower(trim((string) $raw)), 0, self::MAX_LENGTH) : '';
    }

    /** The FH status an agent should be demoted to for this LR status, or null for "do nothing". */
    public static function demotesTo(mixed $raw): ?string
    {
        return self::DEMOTES_TO[self::normalize($raw)] ?? null;
    }

    /** @return list<string> the LR statuses that demote an agent */
    public static function lrStatuses(): array
    {
        return array_keys(self::DEMOTES_TO);
    }

    /** A value we don't recognise: neither empty, `active`, nor one that demotes. */
    public static function isUnmapped(string $normalized): bool
    {
        return $normalized !== '' && $normalized !== 'active' && ! isset(self::DEMOTES_TO[$normalized]);
    }
}
