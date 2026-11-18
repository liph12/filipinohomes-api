<?php

namespace App\Services\LeuterioreRealty;

use Illuminate\Support\Facades\DB;

/**
 * Shared by agents:sync-lr-status and agents:apply-lr-status: which address to
 * ask Leuterio Realty about for an agent, and how an answer is written down.
 */
class LrEmploymentRecorder
{
    /**
     * The address LR knows this agent by: their lr_email when they have linked
     * one, else their login email — trimmed, lowercased, and null when it
     * isn't a usable address (LrApiService::fetchAgentByEmail() does neither
     * clean-up, and LR keys a lookup on the exact string).
     */
    public static function lookupKey(?string $lrEmail, ?string $loginEmail): ?string
    {
        $lr = strtolower(trim((string) $lrEmail));
        $key = $lr !== '' ? $lr : strtolower(trim((string) $loginEmail));

        return filter_var($key, FILTER_VALIDATE_EMAIL) ? $key : null;
    }

    /**
     * Write down what a lookup found.
     *
     * Straight to the table, not through the model: a check happens for every
     * agent every week, and none of it should write an audit row or touch
     * updated_at (which agent pages and sitemaps read).
     *
     * `lr_employment_changed_at` moves only when LR's value differs from the one
     * IN THE ROW at that moment. The very first observation leaves it NULL, so
     * any admin decision made before the sync existed still counts as an
     * override (see agents:apply-lr-status). A not_found or error keeps the last
     * good status — an outage must not erase what we knew — and only restamps
     * the lookup, so it comes round again on its own schedule.
     *
     * The comparison is made by the database, not by the caller against a value
     * it read earlier. The sync and the apply command both record, and a
     * caller working from a snapshot that is hours old would re-advance
     * changed_at after another run had already recorded the same value — past
     * an admin's override, un-holding the agent.
     *
     * @param  array{result: string, status: ?string}  $lookup  from LrApiService::lookupAgentStatus()
     */
    public function record(int $agentId, array $lookup): void
    {
        $now = now();

        if ($lookup['result'] !== LrApiService::LOOKUP_FOUND) {
            DB::table('agents')->where('id', $agentId)->update([
                'lr_employment_lookup' => $lookup['result'],
                'lr_employment_checked_at' => $now,
            ]);

            return;
        }

        $status = (string) $lookup['status'];

        // ⚠️ The changed_at assignment MUST come before the status assignment.
        // MySQL evaluates a single-table UPDATE's assignments left to right and a
        // later one sees the NEW value of an earlier one, so reading
        // lr_employment_status after it has been assigned would always compare
        // equal and changed_at would never move. (SQLite evaluates against the old
        // row either way, so the tests can't catch this ordering — it is verified
        // on MySQL by hand.)
        DB::update(
            'UPDATE agents SET lr_employment_lookup = ?, lr_employment_checked_at = ?, '
            .'lr_employment_changed_at = CASE WHEN lr_employment_status IS NOT NULL AND lr_employment_status <> ? THEN ? ELSE lr_employment_changed_at END, '
            .'lr_employment_status = ? WHERE id = ?',
            [$lookup['result'], $now, $status, $now, $status, $agentId],
        );
    }
}
