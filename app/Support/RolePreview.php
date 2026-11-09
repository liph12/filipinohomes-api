<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Single source of truth for the admin "Change Role" preview.
 *
 * An admin can preview another role's dashboard while staying signed in as
 * themselves. The frontend sends the previewed role in X-Preview-Role, and the
 * endpoints that shape their data by role use scopeRole() so the preview shows
 * that role's view of the ADMIN'S OWN account — the agent dashboard counts the
 * admin's own listings instead of handing back the site-wide admin payload.
 *
 * ⚠️ Scoping only — NEVER authorization. Do not call this from RoleMiddleware,
 *    from an `abort(403)` admin check, from a policy or from a write path.
 *    Those keep reading `$user->role` and the token, so a preview cannot grant
 *    anything: it is honoured only for a real admin, and only for roles that
 *    see a strict subset of what an admin sees, so it can only ever NARROW a
 *    response. An agent sending `X-Preview-Role: admin` gets their own role.
 *
 * Not impersonation: impersonation mints a token for another user (see
 * Impersonation). A preview keeps the admin's own token and own data.
 */
final class RolePreview
{
    public const HEADER = 'X-Preview-Role';

    /** Roles an admin may preview — every one narrower than admin. */
    public const PREVIEWABLE = ['agent', 'client', 'editor', 'secretary'];

    /**
     * The role to SCOPE this request's data as: the previewed role when a real
     * admin sends a valid one, otherwise the user's real role.
     */
    public static function scopeRole(Request $request): ?string
    {
        $real = $request->user()?->role?->name;

        if ($real !== 'admin') {
            return $real;
        }

        $asked = strtolower(trim((string) $request->header(self::HEADER, '')));

        return in_array($asked, self::PREVIEWABLE, true) ? $asked : $real;
    }

    /** Whether this request is a real admin previewing another role. */
    public static function isPreviewing(Request $request): bool
    {
        return self::scopeRole($request) !== $request->user()?->role?->name;
    }
}
