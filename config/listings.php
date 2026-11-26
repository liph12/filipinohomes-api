<?php

/*
|--------------------------------------------------------------------------
| How listings are ordered and how their prices are judged
|--------------------------------------------------------------------------
|
| Every value here is only the DEFAULT: the live switches are rows of the `settings` table
| (see App\Services\Listing\ListingFeedSwitch), so turning one is a database change that
| reaches every host within a minute, not a deploy. Each default reproduces how the site
| behaved before these switches existed.
*/

return [
    // 'legacy': newest `updated_at` first (as it always was).
    // 'groups': available listings with a real price first, then those shown as "Price on
    //           request", then sold / rented / leased; `updated_at` (then id) inside each.
    'feed_order' => env('LISTINGS_FEED_ORDER', 'legacy'),

    // Whether a price outside its category's band is judged suspect: reported as `price_status`
    // on the listing and left out of price filters. Off = every price is taken as typed.
    'price_guard' => env('LISTINGS_PRICE_GUARD', false),

    // What the three write paths do with an implausible price: 'off', 'log' (only record it),
    // or 'enforce' (refuse it unless the agent confirms).
    'price_validation' => env('LISTINGS_PRICE_VALIDATION', 'log'),

    // Whether the listing sitemaps carry the REAL last-change date (ListingDates) as `lastmod`.
    // Off = the sitemaps are exactly as they were: no `lastmod` key and no extra query.
    'sitemap_lastmod' => env('LISTINGS_SITEMAP_LASTMOD', false),

    // Most prices agents may have confirmed as right at once. The list is one settings value
    // that every price-judging query carries, so it is kept small.
    'max_approved' => 1000,

    // Which listings the hourly rotation (POST /listings/update-batch) stamps next:
    // 'id' (the next 100 ids, as built) or 'fair' (see ListingRotationPicker).
    'rotation_picker' => env('LISTINGS_ROTATION_PICKER', 'id'),

    'rotation' => [
        // Listings stamped per call.
        'batch' => 100,
        // A listing that is not complete gets a turn every Nth lap of the queue.
        'incomplete_every' => 2,
        // A listing held back (a copy, an unanswered "sold" claim, an agent who does not answer
        // or has not signed in for a long time) gets a turn every Nth lap.
        'heldback_every' => 4,
        // Days without signing in after which an agent's listings are held back.
        'agent_idle_days' => 45,
    ],
];
