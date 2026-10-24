<?php

namespace App\Services\Listing;

use App\Models\Listing;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What the partner API hands to other websites — controlled from System
 * Settings → "Partner Listings Feed". Two endpoints, each with its own
 * switches:
 *   - `listings`     : GET /fh-agent/listings  (every shared listing)
 *   - `agent_lookup` : GET /fh-agent/{email}   (one agent's listings)
 *
 * Stored as one JSON blob in the `settings` KV table. Each endpoint's
 * defaults are its behaviour before this setting existed, so an unsaved
 * setting changes nothing: the feed sends fully verified + verified in any
 * status; the agent lookup sends every audit result, active listings only.
 */
class PartnerFeedService
{
    public const SETTINGS_KEY = 'partner_listings_feed';

    /** Audit results, as listings.verification_status — `unaudited` is NULL. */
    public const AUDIT = [
        'fully_verified' => 'Fully verified',
        'verified'       => 'Verified',
        'pending_review' => 'Pending review',
        'unaudited'      => 'Not audited yet',
        'flagged'        => 'Flagged',
    ];

    /** Property statuses: on the market, plus closed deals. */
    public const STATUSES = [
        'active' => 'Active',
        'sold'   => 'Sold',
        'rented' => 'Rented',
        'leased' => 'Leased',
    ];

    public const FEEDS = ['listings', 'agent_lookup'];

    public const DEFAULTS = [
        'listings' => [
            'enabled'  => true,
            'audit'    => ['fully_verified', 'verified'],
            'statuses' => ['active', 'sold', 'rented', 'leased'],
        ],
        'agent_lookup' => [
            'enabled'  => true,
            'audit'    => ['fully_verified', 'verified', 'pending_review', 'unaudited', 'flagged'],
            'statuses' => ['active'],
        ],
    ];

    /** @return array<string, array{enabled: bool, audit: string[], statuses: string[]}> */
    public function settings(): array
    {
        $raw = Setting::get(self::SETTINGS_KEY);
        $stored = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        // The first version stored the feed's switches flat, at the root.
        if (array_key_exists('enabled', $stored)) {
            $stored = ['listings' => $stored];
        }

        $out = [];
        foreach (self::FEEDS as $feed) {
            $s = (array) ($stored[$feed] ?? []);
            $d = self::DEFAULTS[$feed];
            $out[$feed] = [
                'enabled'  => (bool) ($s['enabled'] ?? $d['enabled']),
                'audit'    => array_values(array_intersect(array_keys(self::AUDIT), (array) ($s['audit'] ?? $d['audit']))),
                'statuses' => array_values(array_intersect(array_keys(self::STATUSES), (array) ($s['statuses'] ?? $d['statuses']))),
            ];
        }

        return $out;
    }

    /** @return array{enabled: bool, audit: string[], statuses: string[]} */
    public function feed(string $feed): array
    {
        return $this->settings()[$feed];
    }

    /** @param array<string, array{enabled: bool, audit: string[], statuses: string[]}> $settings */
    public function save(array $settings): void
    {
        $out = [];
        foreach (self::FEEDS as $feed) {
            $s = $settings[$feed];
            $out[$feed] = [
                'enabled'  => (bool) $s['enabled'],
                'audit'    => array_values(array_intersect(array_keys(self::AUDIT), $s['audit'])),
                'statuses' => array_values(array_intersect(array_keys(self::STATUSES), $s['statuses'])),
            ];
        }
        Setting::set(self::SETTINGS_KEY, json_encode($out));
    }

    /**
     * Narrow a listings query (a builder or an agent's listings() relation)
     * to the given audit results and property statuses. On the market the
     * ATS must be approved; a closed deal may also have an expired one (the
     * authority lapses after a sale). Rejected / pending ATS never qualify.
     *
     * @param  string[]  $audit
     * @param  string[]  $statuses
     */
    public function applyFilters($query, array $audit, array $statuses)
    {
        $codes = array_values(array_diff($audit, ['unaudited']));

        return $query
            ->where(function ($q) use ($codes, $audit) {
                $q->whereIn('verification_status', $codes ?: ['__none__']);
                if (in_array('unaudited', $audit, true)) {
                    $q->orWhereNull('verification_status');
                }
            })
            ->whereHas('property', fn ($q) => $q
                ->whereIn('status', $statuses ?: ['__none__'])
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('status', 'active')->where('ats_status', 'approve'))
                    ->orWhere(fn ($q) => $q->where('status', '!=', 'active')->whereIn('ats_status', ['approve', 'expired']))));
    }

    /**
     * The listings feed: public listings of active agents, filtered as above.
     * Deliberately NOT publiclyListed(): that scope drops flagged listings,
     * and whether flagged ones go out is the admin's call here.
     *
     * @param  string[]  $audit
     * @param  string[]  $statuses
     */
    public function query(array $audit, array $statuses): Builder
    {
        return $this->applyFilters(Listing::visibleOnAgentPage(), $audit, $statuses);
    }

    /**
     * Listing counts for every audit × status pair under the same rules, so
     * the settings dialog can say what each switch adds and the total sent —
     * summed client-side for any selection. The feed only serves active
     * agents' listings; the agent lookup serves whichever agent is asked
     * for, so its counts include every agent.
     *
     * @return array<string, array<string, int>>
     */
    public function counts(bool $activeAgentsOnly = true): array
    {
        $matrix = [];
        foreach (array_keys(self::AUDIT) as $a) {
            $matrix[$a] = array_fill_keys(array_keys(self::STATUSES), 0);
        }

        $rows = DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('agents', 'agents.id', '=', 'listings.agent_id')
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            ->whereNull('agents.deleted_at')
            ->where('listings.visibility', 'public')
            ->when($activeAgentsOnly, fn ($q) => $q->where('agents.status', 'active'))
            ->whereIn('properties.status', array_keys(self::STATUSES))
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('properties.status', 'active')->where('properties.ats_status', 'approve'))
                ->orWhere(fn ($q) => $q->where('properties.status', '!=', 'active')->whereIn('properties.ats_status', ['approve', 'expired'])))
            ->selectRaw("COALESCE(listings.verification_status, 'unaudited') as audit, properties.status as status, COUNT(*) as c")
            ->groupBy('audit', 'properties.status')
            ->get();

        foreach ($rows as $r) {
            if (isset($matrix[$r->audit][$r->status])) {
                $matrix[$r->audit][$r->status] = (int) $r->c;
            }
        }

        return $matrix;
    }
}
