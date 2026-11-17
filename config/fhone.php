<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FH One's API keys
    |--------------------------------------------------------------------------
    |
    | FH One (the group's AI platform) reads FH only through /api/fh-one, and
    | every call carries a key. Each entry here is
    |
    |     name:sha256-of-the-key:last-valid-day
    |
    | comma separated, so two keys can overlap while one is replaced. The last
    | valid day is a Manila calendar day (Y-m-d). Only the hash is stored: the
    | key itself lives in FH One's own settings. `php artisan fhone:make-key`
    | prints a new key once, with the entry to paste here.
    |
    */

    'keys' => env('FHONE_API_KEYS', ''),

    // Calls per minute for one key. Nothing lifts this limit, not even the
    // build or service tokens that lift the general API limit.
    'rate_limit' => (int) env('FHONE_RATE_LIMIT', 120),

    // How long a number FH One asked for is reused before it is recomputed.
    'cache_seconds' => (int) env('FHONE_CACHE_SECONDS', 300),

];
