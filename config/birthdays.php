<?php

/*
|--------------------------------------------------------------------------
| Agent birthday greetings
|--------------------------------------------------------------------------
|
| Read through config() everywhere, NEVER env() — once `php artisan
| config:cache` runs, env() returns null outside config files. That exact
| bug 401'd every /listings request in production once.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Send mode
    |--------------------------------------------------------------------------
    |
    |   off       — nothing is sent. birthdays:send-greetings still resolves
    |               today's celebrants and applies every gate, then reports
    |               what it WOULD do. THIS IS THE DEFAULT and what ships;
    |               flipping it is a deliberate act.
    |   whitelist — every greeting is redirected to `test_recipients`, with the
    |               real recipient named in the subject prefix.
    |   live      — real greetings to real agents.
    |
    | Deliberately mirrors natcon.send_mode: one fewer pattern to learn, and
    | the failure mode of a typo is "sends nothing", not "mails every agent".
    |
    */
    'send_mode' => env('BIRTHDAY_SEND_MODE', 'off'),

    'test_recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BIRTHDAY_TEST_RECIPIENTS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Backfill
    |--------------------------------------------------------------------------
    |
    | agents.birthdate arrives from Leuterio Realty, and only lazily — on
    | login. Coverage therefore tracks logins rather than headcount, so
    | birthdays:backfill-birthdates closes the gap for everyone else. LR rate
    | limits to 60 requests/minute from one IP, so the run is chunked and paced
    | rather than exhaustive.
    |
    | recheck_days: how long before we ask LR again about an agent it had no
    | birthday for. Without it every hourly run re-queries the same dead ends
    | and never reaches the agents further down the list.
    |
    */
    'backfill' => [
        'limit' => (int) env('BIRTHDAY_BACKFILL_LIMIT', 200),
        'recheck_days' => (int) env('BIRTHDAY_BACKFILL_RECHECK_DAYS', 90),
    ],

];
