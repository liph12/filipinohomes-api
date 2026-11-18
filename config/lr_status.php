<?php

/*
|--------------------------------------------------------------------------
| Leuterio Realty → agent status sync
|--------------------------------------------------------------------------
|
| Read through config() everywhere, NEVER env() — once `php artisan
| config:cache` runs, env() returns null outside config files.
|
| Two commands, kept apart on purpose:
|
|   agents:sync-lr-status   asks LR about each agent and RECORDS what it said
|                           in agents.lr_employment_*. It never touches
|                           agents.status, so it is safe to leave running.
|   agents:apply-lr-status  the only thing that changes agents.status. A report
|                           unless --force, because a demotion pulls that
|                           agent's listings off the public site and sitemap.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Scheduled runs
    |--------------------------------------------------------------------------
    |
    | OFF by default. The schedule is live on the next scheduler deploy, which
    | can land before the migration has run, so flipping this is a deliberate
    | act (LR_STATUS_SYNC_ENABLED=true) once the migration is in. Evaluated when
    | the scheduler runs, not when the schedule is defined: `schedule:list` runs
    | without a database in CI.
    |
    */
    'enabled' => (bool) env('LR_STATUS_SYNC_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Pacing
    |--------------------------------------------------------------------------
    |
    | LR allows 60 requests a minute per IP, and that budget is shared with
    | natcon:hydrate-awardees, birthdays:backfill-birthdates and — the one that
    | matters — real sign-ins: when LR can't answer at a first sign-in, the
    | person is registered as a CLIENT, not an agent. So the sync paces itself,
    | reads LR's own X-RateLimit-Remaining, and gives way when it runs low.
    |
    | A hit takes 2–12 s, so even with no pause a sequential run stays far
    | under the limit; sleep_ms is the safety margin on top.
    |
    */
    'limit' => (int) env('LR_STATUS_SYNC_LIMIT', 300),
    'sleep_ms' => (int) env('LR_STATUS_SYNC_SLEEP_MS', 1000),
    'min_remaining' => (int) env('LR_STATUS_SYNC_MIN_REMAINING', 20),
    'backoff_seconds' => (int) env('LR_STATUS_SYNC_BACKOFF_SECONDS', 60),

    // A run stops on its own after this long. LR can take up to the 30 s
    // timeout per call when it is slow, so 300 lookups could otherwise outlast
    // the lock and run into the next hour's sync — two at once on a shared budget.
    'max_run_minutes' => (int) env('LR_STATUS_SYNC_MAX_RUN_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Apply: how big an unreviewed batch may be
    |--------------------------------------------------------------------------
    |
    | agents:apply-lr-status --force needs either the reviewed list (--from-csv)
    | or a --limit. A limit alone takes agents from the LIVE cohort — which can
    | include agents the sync recorded after anyone looked — so it is capped.
    | Anything bigger has to be a list somebody has actually reviewed.
    |
    */
    'max_unreviewed_batch' => (int) env('LR_STATUS_MAX_UNREVIEWED_BATCH', 50),

    /*
    |--------------------------------------------------------------------------
    | Re-check cadence (days)
    |--------------------------------------------------------------------------
    |
    | found      an agent LR knows — a termination should reach FH within a week.
    | not_found  LR doesn't know the address (~58% of FH's agents). Dead ends,
    |            so they are asked about rarely, like birthdays:backfill-birthdates.
    | error      a lookup that failed. Retried daily, never acted on; recorded so
    |            one agent that keeps failing can't starve the whole run.
    | blocked    agents FH has already switched off. The sync can't demote
    |            them, so they only matter for the "LR active again" report.
    |
    */
    'recheck_days' => [
        'found' => (int) env('LR_STATUS_RECHECK_FOUND_DAYS', 7),
        'not_found' => (int) env('LR_STATUS_RECHECK_NOT_FOUND_DAYS', 30),
        'error' => (int) env('LR_STATUS_RECHECK_ERROR_DAYS', 1),
        'blocked' => (int) env('LR_STATUS_RECHECK_BLOCKED_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lock
    |--------------------------------------------------------------------------
    |
    | The scheduler's own overlap lock is a file, so it is per host and two
    | hosts running the schedule can overlap. This one lives in the shared
    | database (as reports:write-analyses does) and holds for at most
    | lock_seconds if a run dies.
    |
    */
    'lock_store' => env('LR_STATUS_SYNC_LOCK_STORE', 'database'),
    'lock_seconds' => (int) env('LR_STATUS_SYNC_LOCK_SECONDS', 5400),

];
