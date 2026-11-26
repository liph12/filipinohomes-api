<?php

namespace App\Services\Listing;

use App\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * The date a listing REALLY last changed, for Google (the sitemap's lastmod).
 *
 * `listings.updated_at` cannot be used for it: the hourly rotation sets it on a batch of listings that nobody touched,
 * so every listing looked changed every few days. The real date is the latest of
 *
 *   created_at, audited_at (an audit), re_submitted_at (the agent sent it back), and the last edit a PERSON made,
 *   from the audit history (an `updated` row with a user: the rotation, jobs and migrations leave no audit row, or
 *   one without a user).
 *
 * Only columns and tables that already exist. The audit history is recent, so an old listing nobody has edited since
 * falls back to its creation date, which is the honest answer.
 */
final class ListingDates
{
    /**
     * SQL for the real last-change timestamp of the row of `$table`, for `selectRaw($sql.' AS lastmod', $bindings)`.
     * A correlated subquery per row, read through the audits morph index; used for pages of 200-1000 rows.
     *
     * @return array{0: string, 1: list<string>} the SQL and its bindings (the class name is bound, not written
     *                                           as a literal: a backslash means different things to MySQL and SQLite)
     */
    public static function lastmodSql(string $table = 'listings'): array
    {
        $edit = '(SELECT MAX(a.created_at) FROM audits a WHERE a.auditable_type = ?'
            ." AND a.auditable_id = {$table}.id AND a.event = 'updated' AND a.user_id IS NOT NULL)";

        // A row with no creation date (the column allows it) starts from its last update instead of turning the
        // whole answer NULL: GREATEST returns NULL as soon as one argument is.
        $base = "COALESCE({$table}.created_at, {$table}.updated_at)";

        $parts = [
            $base,
            "COALESCE({$table}.audited_at, {$base})",
            "COALESCE({$table}.re_submitted_at, {$base})",
            "COALESCE({$edit}, {$base})",
        ];

        // MySQL: GREATEST(...). SQLite (the tests): the multi-argument MAX(...).
        $function = DB::connection()->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';

        return ["{$function}(".implode(', ', $parts).')', [Listing::class]];
    }
}
