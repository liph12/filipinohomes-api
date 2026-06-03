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
    'indexnow' => [
        'enabled' => env('INDEXNOW_ENABLED', false),
        'submit_endpoint' => env('INDEXNOW_SUBMIT_URL', 'https://filipinohomes.com/api/indexnow/submit'),
        'submit_secret' => env('INDEXNOW_SUBMIT_SECRET'),
        'site_url' => env('INDEXNOW_SITE_URL', env('FRONTEND_URL', 'https://filipinohomes.com')),
        'timeout' => (int) env('INDEXNOW_TIMEOUT', 8),
        'queue' => env('INDEXNOW_QUEUE', 'default'),
    ],

];
