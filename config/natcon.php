<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invite link secret
    |--------------------------------------------------------------------------
    |
    | HMAC key for hashing awardee invite tokens. We store the hash and email the
    | raw token (Sanctum's model), so a DB backup or read replica never yields a
    | working link.
    |
    | Read through config() everywhere, NEVER env() — once `php artisan config:cache`
    | runs, env() returns null outside config files. That exact bug 401'd every
    | /listings request in production once; see VerifyGuestToken's docblock.
    |
    */
    'link_secret' => env('NATCON_LINK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Send mode
    |--------------------------------------------------------------------------
    |
    |   off       — nothing is sent. Rows are still claimed in natcon_outbox so a
    |               dry run exercises the real code path. THIS IS THE DEFAULT and
    |               what ships; flipping it is a deliberate act.
    |   whitelist — every message is redirected to `test_recipients` via
    |               Mail::alwaysTo(), with the real recipient in the subject.
    |   live      — real sends to real awardees.
    |
    */
    'send_mode'       => env('NATCON_SEND_MODE', 'off'),
    'test_recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('NATCON_TEST_RECIPIENTS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Cron-drain throughput
    |--------------------------------------------------------------------------
    |
    | Production has no queue:work worker — see the comment in
    | app/Mail/MessageNotificationMailer.php, where a previous ShouldQueue mailer
    | silently dropped every email into the `jobs` table. So sending is drained by
    | the scheduler that already runs, in small paced batches.
    |
    | 40/min is deliberately conservative: it stays under any plausible SMTP rate
    | cap, keeps each scheduler tick well short of the next one, and spreads a
    | 1,000-recipient campaign over ~25 minutes instead of hammering the provider.
    |
    */
    'drain_limit'  => (int) env('NATCON_DRAIN_LIMIT', 40),
    'max_attempts' => (int) env('NATCON_MAX_SEND_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Leuterio Realty awardee API
    |--------------------------------------------------------------------------
    |
    | Rate limited to 60 req/min from a single IP (x-ratelimit-limit: 60), shared
    | across everything api2 sends them. We self-throttle to half that to leave
    | headroom for other LR consumers on the same egress IP.
    |
    | ⚠️ This endpoint answers HTTP 200 with {"success":false} for an unknown
    | email — NOT a 404. See App\Natcon\Services\AwardeeService.
    |
    */
    'lr' => [
        'base_url'          => env('NATCON_LR_BASE_URL', 'https://api.leuteriorealty.com/natcon/v1/public/api/v2/get-awardee'),
        'timeout'           => (int) env('NATCON_LR_TIMEOUT', 15),
        'lookups_per_minute' => (int) env('NATCON_LR_RATE', 30),
        'cache_ttl_found'     => 3600,
        // Short, because LR may add someone after we first looked.
        'cache_ttl_not_found' => 300,
        // Errors are never cached — a transient LR outage must not poison an hour
        // of lookups into looking like "not an awardee".
    ],

    /*
    |--------------------------------------------------------------------------
    | Leuterio Realty qualifiers list
    |--------------------------------------------------------------------------
    |
    | The bulk awardee list — a different service from the get-awardee endpoint
    | above, on a different host. ~285 records, ~288KB, one request.
    |
    | ⚠️ The three dates define the QUALIFYING SALES WINDOW and will be different
    | for 2027. They live in env rather than as literals so the window can move
    | without a deploy. If NATCON keeps running, they arguably belong on
    | natcon_events beside the other per-year data.
    |
    | Note this list carries no photos — those still come from get-awardee, which
    | is why hydration is still needed after a sync.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Invite waves
    |--------------------------------------------------------------------------
    |
    | Invites go out in sales tiers — the top band first, the rest a few days
    | later. This is only the FALLBACK: the number actually in force is
    | natcon_events.sales_breakpoint, editable from the admin, because the
    | organizers pick it per convention and should not need a deploy to change
    | who gets emailed this week.
    |
    */
    'sales_breakpoint' => (float) env('NATCON_SALES_BREAKPOINT', 61000000),

    'qualifiers' => [
        'url'        => env('NATCON_LR_QUALIFIERS_URL', 'https://leuteriorealty.com/api/natcon-qualifiers-v2'),
        'from'       => env('NATCON_LR_QUALIFIERS_FROM', '2025-08-01'),
        'lastdate_x' => env('NATCON_LR_QUALIFIERS_LASTDATE_X', '2026-07-31'),
        'lastdate_y' => env('NATCON_LR_QUALIFIERS_LASTDATE_Y', '2026-08-05'),
        'timeout'    => (int) env('NATCON_LR_QUALIFIERS_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Photo storage
    |--------------------------------------------------------------------------
    |
    | Deliberately different from ImageUploadController's listing pipeline:
    |
    |   JPEG, not WebP — these files are handed to an events/print workflow for
    |     backdrops and ID cards, where WebP support is still poor.
    |   2000px, not 1200 — printed at size, not a listing thumbnail.
    |   600KB target, not 50KB — 50KB visibly destroys a face.
    |   No ImageVariantService — nobody builds a srcset from these, and skipping it
    |     avoids 5 extra S3 writes per upload on a PUBLIC endpoint.
    |
    */
    'photo' => [
        's3_prefix'     => env('NATCON_S3_PREFIX', 'filipinohomes-new/natcon-2026/photos'),
        'max_width'     => 2000,
        'target_bytes'  => 600 * 1024,
        'max_upload_kb' => 15 * 1024,   // 15MB; a headshot is under 5
        // Guards against decompression bombs: a flat-colour 12000x12000 PNG is
        // ~100KB on the wire and decodes to ~576MB in GD, which OOMs a PHP-FPM
        // worker. Checked with getimagesize() BEFORE Intervention touches it.
        'max_megapixels' => 40,
        'max_dimension'  => 8000,

        /*
         | How many photos an awardee sends.
         |
         | The organizers asked for three so they have something to choose from
         | rather than being stuck with whatever single file arrives.
         |
         | required_count is the MINIMUM that completes a submission; max_count is
         | the ceiling. They started life equal at 3, and the organizers moved to
         | "send 1-3" once it was clear how many agents have exactly one usable
         | headshot on their phone. Being env-driven is what made that a config
         | edit rather than a release.
         |
         | ⚠️ Copy is derived from BOTH. When they differ the wording becomes a
         |    range ("1-3 photos"); when they are equal it is a single number.
         |    Changing one without the other silently changes what every email
         |    and every page says.
         */
        'required_count' => (int) env('NATCON_PHOTO_REQUIRED_COUNT', 1),
        'max_count'      => (int) env('NATCON_PHOTO_MAX_COUNT', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public link behaviour
    |--------------------------------------------------------------------------
    |
    | Tokens outlive the deadline by a grace period so a late click gets a friendly
    | "the deadline has passed" screen instead of a dead link and a support call.
    |
    */
    'token_grace_days' => (int) env('NATCON_TOKEN_GRACE_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Email assets
    |--------------------------------------------------------------------------
    |
    | The banner must be an absolute, publicly reachable, stable URL: Gmail
    | proxies and caches remote images, so a path that moves breaks every message
    | already delivered. Point it at a pre-resized copy (<=80KB) rather than the
    | 3218px original — the source banner is 413KB and mail clients don't resize
    | before downloading.
    |
    */
    'email' => [
        'banner_url' => env(
            'NATCON_EMAIL_BANNER_URL',
            'https://filipinohomes.com/images/natcon-2026/natcon2026-email-1200.jpg',
        ),
    ],

];
