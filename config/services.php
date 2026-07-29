<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'google_maps' => [
        'geocoding_key' => env('GOOGLE_MAPS_API_KEY', env('NEXT_PUBLIC_GOOGLE_MAPS_API_KEY')),
    ],

    'homesphnews' => [
        'base_url' => env('HOMESPHNEWS_BASE_URL', 'https://homesphnews-api-394504332858.asia-southeast1.run.app/api'),
        'key' => env('HOMESPHNEWS_API_KEY'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
    ],

    /*
    | Expo Push Service. The access_token is optional — only required if the
    | Expo project has "Enhanced Security for Push Notifications" enabled.
    | Messages are sent to https://exp.host/--/api/v2/push/send.
    */
    'expo' => [
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

    /*
    | IndexNow integration. The Next.js frontend hosts the IndexNow
    | key file and exposes a POST /api/indexnow/submit endpoint
    | guarded by INDEXNOW_SUBMIT_SECRET. This service config tells
    | the Laravel side where to POST and what shared secret to send
    | when a listing / agent / blog post / project is created,
    | updated, or deleted.
    */
    /*
    | Automated YouTube listing videos. The youtube:process-uploads scheduled
    | command renders a slideshow MP4 per eligible listing (ffmpeg) and uploads
    | it to the brand channel via the YouTube Data API v3 using a long-lived
    | OAuth refresh token (single channel — env, not a token table).
    |
    | NOTE: until the Google Cloud project passes YouTube's API compliance
    | audit, API-uploaded videos are LOCKED PRIVATE by YouTube (non-appealable
    | per video). Build/test through that window; flip `enabled` once ready.
    */
    'youtube' => [
        'enabled' => env('YOUTUBE_UPLOADS_ENABLED', false),
        // Refresh token must be minted with BOTH scopes:
        //   youtube.upload    (videos.insert + thumbnails.set)
        //   youtube.force-ssl (captions.insert — the auto transcript)
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'refresh_token' => env('YOUTUBE_REFRESH_TOKEN'),
        // Hard daily ceiling. Under the historical quota pricing one full
        // upload costs ~2,050 units (insert 1600 + thumbnail 50 + caption
        // 400), so 4/day fits the default 10k allowance; raise only after
        // checking the real videos.insert allowance in the Cloud console.
        'daily_upload_cap' => (int) env('YOUTUBE_DAILY_UPLOAD_CAP', 4),
        // Where the intro-card / thumbnail / end-card PNGs are rendered
        // (the Next.js /og/listing-video route).
        'og_base' => env('YOUTUBE_OG_BASE', env('FRONTEND_URL', 'https://filipinohomes.com')),
        'site_url' => env('FRONTEND_URL', 'https://filipinohomes.com'),
        'ffmpeg' => env('YOUTUBE_FFMPEG_BIN', 'ffmpeg'),
        // Videos upload as 'public' — pre-audit YouTube force-locks them to
        // private anyway, so there's nothing to flip later per video.
        'privacy_status' => env('YOUTUBE_PRIVACY_STATUS', 'public'),
    ],

    'indexnow' => [
        'enabled' => env('INDEXNOW_ENABLED', false),
        'submit_endpoint' => env('INDEXNOW_SUBMIT_URL', 'https://filipinohomes.com/api/indexnow/submit'),
        'submit_secret' => env('INDEXNOW_SUBMIT_SECRET'),
        'site_url' => env('INDEXNOW_SITE_URL', env('FRONTEND_URL', 'https://filipinohomes.com')),
        'timeout' => (int) env('INDEXNOW_TIMEOUT', 8),
        'queue' => env('INDEXNOW_QUEUE', 'default'),
    ],

    // OpenStreetMap Overpass API — powers facilities:scan-candidates (the
    // nationwide facility discovery for "near {facility}" SEO pages).
    // Public instances allow ~10k queries/day; we run strictly sequential
    // with an identifying User-Agent per fair-use etiquette. The fallback
    // instance takes over after repeated primary failures.
    'overpass' => [
        'endpoint' => env('OVERPASS_ENDPOINT', 'https://overpass-api.de/api/interpreter'),
        'fallback_endpoint' => env('OVERPASS_FALLBACK_ENDPOINT', 'https://maps.mail.ru/osm/tools/overpass/api/interpreter'),
        'user_agent' => env('OVERPASS_USER_AGENT', 'filipinohomes-facility-scanner/1.0 (info@filipinohomes.com)'),
        'timeout' => (int) env('OVERPASS_TIMEOUT', 60),       // HTTP timeout; server budget is [timeout:25]
        'query_timeout' => (int) env('OVERPASS_QUERY_TIMEOUT', 25),
    ],

];
