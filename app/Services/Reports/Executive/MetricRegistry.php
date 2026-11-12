<?php

namespace App\Services\Reports\Executive;

use InvalidArgumentException;

/**
 * Every number the Executive Report shows, by key. One definition per key;
 * the SQL behind each lives in {@see MetricEngine}, and both the summary and
 * the drill resolve a key through it — so a headline and the list behind it
 * can't describe different things.
 *
 *   label    shown on the tile / column
 *   ai       how the AI analysis refers to it (lower case, a noun phrase)
 *   hint     one line under the tile: what exactly is counted
 *   unit     count | php_per_sqm | minutes
 *   entity   what a drill row is: listing | deal | inquiry | lead | user | agent | team
 *   family   which engine branch builds it (listing | lifecycle | lead | user | agent | team)
 *   as_of    range = measured over the dates; now = measured at this moment
 *            (previous-period values are only computed for `range`)
 *   kind     count | median
 *   better   up | down | neutral — which direction a delta is good news
 *   source   the table whose history bounds it (see DATA_SINCE) — sets `partial`.
 *            A feature's launch date is not a gap: there were no briefs before
 *            the Buyer & Seller Link existed, so its metrics carry no source.
 *   filters  (optional) the drill filters, when they differ from the family's
 */
final class MetricRegistry
{
    /** The first day each source has data — earlier ranges are flagged partial. */
    public const DATA_SINCE = [
        'chats' => '2026-03-22',
        'deals' => '2026-03-26',
        'login_logs' => '2026-04-16',
        'teams' => '2026-04-23',
        'audits' => '2026-05-21',
    ];

    public const DATA_SINCE_LABELS = [
        'chats' => 'Inquiries and chats',
        'deals' => 'Deal dates (sold / rented / leased)',
        'login_logs' => 'Sign-in history',
        'teams' => 'Teams',
        'audits' => 'Edit history (who changed what)',
    ];

    /** Filters per family; a drill carrying any other filter is refused (422). */
    public const FAMILY_FILTERS = [
        'listing' => ReportQueries::FILTERS,
        'lifecycle' => ReportQueries::FILTERS,
        'lead' => ['team_id', 'office_region', 'agent_id'],
        'agent' => ['team_id', 'office_region', 'agent_id'],
        'user' => ['role'],
        'team' => ['team_id'],
    ];

    /** The At-a-glance tiles, in order. */
    public const KPIS = [
        'usage.total_users',
        'usage.active_users',
        'listings.added',
        'inquiries.received',
        'deals.total',
        'agents.active',
        'agents.dormant_30d',
        'teams.active',
        'inquiries.hanging.total',
        'inquiries.first_reply_minutes',
        'claims.sold_unmarked',
    ];

    private const METRICS = [
        // ── People ──
        'usage.total_users' => ['label' => 'Total users', 'ai' => 'people who have used FH', 'hint' => 'Everyone who has ever used FH — signed in, been online, messaged, inquired, saved a favourite, posted or edited something. Abandoned sign-ups and never-used synced accounts excluded.', 'unit' => 'count', 'entity' => 'user', 'family' => 'user', 'as_of' => 'now', 'kind' => 'count', 'better' => 'up', 'source' => null],
        'usage.active_users' => ['label' => 'Active users', 'ai' => 'users active in the period', 'hint' => 'Signed in, messaged, inquired, saved a favourite, posted or edited something in these dates.', 'unit' => 'count', 'entity' => 'user', 'family' => 'user', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => 'login_logs'],
        'usage.new_clients' => ['label' => 'New clients', 'ai' => 'new clients', 'hint' => 'Clients who signed up in these dates and actually used the account.', 'unit' => 'count', 'entity' => 'user', 'family' => 'user', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => null],
        'usage.unfinished_signups' => ['label' => 'Unfinished sign-ups', 'ai' => 'sign-ups never completed', 'hint' => 'Client accounts created in these dates that were never used — the login code was asked for, nothing after.', 'unit' => 'count', 'entity' => 'user', 'family' => 'user', 'as_of' => 'range', 'kind' => 'count', 'better' => 'down', 'source' => null],
        'usage.returning_clients' => ['label' => 'Returning clients', 'ai' => 'returning clients', 'hint' => 'Clients who joined earlier and signed in again in these dates.', 'unit' => 'count', 'entity' => 'user', 'family' => 'user', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => 'login_logs'],
        'usage.active_clients' => ['label' => 'Active clients', 'ai' => 'active clients', 'hint' => 'Clients who signed in, inquired, messaged or saved a favourite in these dates.', 'unit' => 'count', 'entity' => 'user', 'family' => 'user', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => 'login_logs'],

        'agents.headcount' => ['label' => 'Agents', 'ai' => 'agents on the roster', 'hint' => 'Agents whose status is active right now.', 'unit' => 'count', 'entity' => 'agent', 'family' => 'agent', 'as_of' => 'now', 'kind' => 'count', 'better' => 'up', 'source' => null],
        'agents.new' => ['label' => 'New agents', 'ai' => 'new agents', 'hint' => 'Agent accounts created in these dates (includes accounts the Leuterio Realty sync created).', 'unit' => 'count', 'entity' => 'agent', 'family' => 'agent', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => null],
        'agents.posting' => ['label' => 'Agents posting', 'ai' => 'agents who posted listings', 'hint' => 'Agents who posted at least one listing in these dates.', 'unit' => 'count', 'entity' => 'agent', 'family' => 'agent', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => null],
        'agents.logged_in' => ['label' => 'Agents signing in', 'ai' => 'agents who signed in', 'hint' => 'Agents with a sign-in in these dates. Stays-signed-in sessions don\'t log again, so this undercounts.', 'unit' => 'count', 'entity' => 'agent', 'family' => 'agent', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => 'login_logs'],
        'agents.active' => ['label' => 'Active agents', 'ai' => 'active agents', 'hint' => 'Agents on the roster who signed in, messaged, posted or edited something in these dates.', 'unit' => 'count', 'entity' => 'agent', 'family' => 'agent', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => 'login_logs'],
        'agents.idle' => ['label' => 'Idle agents', 'ai' => 'idle agents', 'hint' => 'Agents on the roster with no recorded action in these dates.', 'unit' => 'count', 'entity' => 'agent', 'family' => 'agent', 'as_of' => 'range', 'kind' => 'count', 'better' => 'down', 'source' => 'login_logs'],
        'agents.dormant_30d' => ['label' => 'Dormant 30+ days', 'ai' => 'agents who haven\'t opened FH in a month', 'hint' => 'Agents on the roster who haven\'t opened FH in 30 days — as of now.', 'unit' => 'count', 'entity' => 'agent', 'family' => 'agent', 'as_of' => 'now', 'kind' => 'count', 'better' => 'down', 'source' => null],
        'agents.never_used' => ['label' => 'Never used FH', 'ai' => 'quiet agents who have never used FH', 'hint' => 'Of the agents who haven\'t opened FH in 30 days: never signed in, messaged, posted or left any other trace — as of now. They need onboarding, not a reminder.', 'unit' => 'count', 'entity' => 'agent', 'family' => 'agent', 'as_of' => 'now', 'kind' => 'count', 'better' => 'down', 'source' => null],
        'agents.dormant_used' => ['label' => 'Used FH before', 'ai' => 'quiet agents who used FH before', 'hint' => 'Of the agents who haven\'t opened FH in 30 days: those who did use it before — as of now. The ones to win back.', 'unit' => 'count', 'entity' => 'agent', 'family' => 'agent', 'as_of' => 'now', 'kind' => 'count', 'better' => 'down', 'source' => null],
        'agents.gone_quiet' => ['label' => 'Stopped this period', 'ai' => 'agents who were active the period before and did nothing in this one', 'hint' => 'Agents on the roster who did something in the period before these dates, and nothing in these dates.', 'unit' => 'count', 'entity' => 'agent', 'family' => 'agent', 'as_of' => 'range', 'kind' => 'count', 'better' => 'down', 'source' => 'login_logs'],
        'agents.with_leads' => ['label' => 'Agents with leads', 'ai' => 'agents who got at least one lead', 'hint' => 'Agents (not staff accounts) with at least one lead from any source in these dates.', 'unit' => 'count', 'entity' => 'agent', 'family' => 'agent', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => 'chats', 'filters' => ['team_id', 'office_region', 'agent_id', 'leads']],
        'agents.unanswered' => ['label' => 'Agents with unanswered leads', 'ai' => 'agents holding a lead nobody answered', 'hint' => 'Agents (not staff accounts) with a chat lead from these dates that has had no reply for over 24 hours — as of now.', 'unit' => 'count', 'entity' => 'agent', 'family' => 'agent', 'as_of' => 'now', 'kind' => 'count', 'better' => 'down', 'source' => 'chats'],

        // ── Leads, from every source (LeadQueries) ──
        'leads.all' => ['label' => 'All leads', 'ai' => 'leads from every source', 'hint' => 'Every lead in these dates: clients who asked about a listing, messaged an agent directly, sent a Buyer & Seller Link brief or registered at an open house.', 'unit' => 'count', 'entity' => 'lead', 'family' => 'lead', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => 'chats'],
        'leads.reached' => ['label' => 'Chat leads', 'ai' => 'chat leads that reached their agent', 'hint' => 'Listing inquiries approved and passed to the agent, plus messages sent straight to an agent — the leads that could be answered in the FH chat. As of now.', 'unit' => 'count', 'entity' => 'lead', 'family' => 'lead', 'as_of' => 'now', 'kind' => 'count', 'better' => 'up', 'source' => 'chats'],
        'leads.answered' => ['label' => 'Answered', 'ai' => 'chat leads answered', 'hint' => 'Of the chat leads, those answered in the FH chat — a listing inquiry by anyone on the agent\'s side, a direct message by the agent. Calls and texts can\'t be seen.', 'unit' => 'count', 'entity' => 'lead', 'family' => 'lead', 'as_of' => 'now', 'kind' => 'count', 'better' => 'up', 'source' => 'chats'],
        'leads.waiting' => ['label' => 'Never answered', 'ai' => 'leads nobody answered for over a day', 'hint' => 'Chat leads with no reply on FH after 24 hours — as of now.', 'unit' => 'count', 'entity' => 'lead', 'family' => 'lead', 'as_of' => 'now', 'kind' => 'count', 'better' => 'down', 'source' => 'chats'],
        'leads.to_idle_agents' => ['label' => 'Leads to agents not on FH', 'ai' => 'leads that went to agents who did nothing on FH in the period', 'hint' => 'Leads in these dates whose agent didn\'t sign in, message, post or edit anything in the same dates — nobody was there to answer them.', 'unit' => 'count', 'entity' => 'lead', 'family' => 'lead', 'as_of' => 'range', 'kind' => 'count', 'better' => 'down', 'source' => 'login_logs'],
        'direct.received' => ['label' => 'Messaged the agent', 'ai' => 'messages clients sent straight to an agent', 'hint' => 'Clients who messaged an agent from the agent\'s profile or My Page in these dates.', 'unit' => 'count', 'entity' => 'lead', 'family' => 'lead', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => 'chats'],
        'direct.answered' => ['label' => 'Direct messages answered', 'ai' => 'direct messages the agent answered', 'hint' => 'Of those, the ones the agent replied to in the FH chat — as of now.', 'unit' => 'count', 'entity' => 'lead', 'family' => 'lead', 'as_of' => 'now', 'kind' => 'count', 'better' => 'up', 'source' => 'chats'],
        'direct.waiting' => ['label' => 'Direct messages not answered', 'ai' => 'direct messages with no reply after a day', 'hint' => 'Direct messages the agent hasn\'t answered after 24 hours — as of now.', 'unit' => 'count', 'entity' => 'lead', 'family' => 'lead', 'as_of' => 'now', 'kind' => 'count', 'better' => 'down', 'source' => 'chats'],
        'briefs.received' => ['label' => 'Buyer & Seller Link briefs', 'ai' => 'Buyer & Seller Link briefs', 'hint' => 'Briefs clients sent through an agent\'s Buyer & Seller Link (the link started Nov 4, 2026).', 'unit' => 'count', 'entity' => 'lead', 'family' => 'lead', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => null],
        'open_house.registrations' => ['label' => 'Open-house registrations', 'ai' => 'open-house registrations', 'hint' => 'Clients who registered through an agent\'s Buyer Form.', 'unit' => 'count', 'entity' => 'lead', 'family' => 'lead', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => null],

        'teams.total' => ['label' => 'Teams', 'ai' => 'teams', 'hint' => 'Active teams with at least one agent on the roster.', 'unit' => 'count', 'entity' => 'team', 'family' => 'team', 'as_of' => 'now', 'kind' => 'count', 'better' => 'up', 'source' => 'teams'],
        'teams.active' => ['label' => 'Active teams', 'ai' => 'active teams', 'hint' => 'Teams with at least one active agent in these dates.', 'unit' => 'count', 'entity' => 'team', 'family' => 'team', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => 'teams'],
        'teams.inactive' => ['label' => 'Quiet teams', 'ai' => 'teams with no activity', 'hint' => 'Teams where nobody did anything recorded in these dates.', 'unit' => 'count', 'entity' => 'team', 'family' => 'team', 'as_of' => 'range', 'kind' => 'count', 'better' => 'down', 'source' => 'teams'],
        'teams.no_leader' => ['label' => 'Teams without a leader', 'ai' => 'teams with no leader on record', 'hint' => 'Teams with agents but nobody marked as their leader — as of now.', 'unit' => 'count', 'entity' => 'team', 'family' => 'team', 'as_of' => 'now', 'kind' => 'count', 'better' => 'down', 'source' => 'teams'],

        // ── Listings & prices ──
        'listings.added' => ['label' => 'Listings added', 'ai' => 'listings added', 'hint' => 'Listings posted in these dates (For Sale, For Rent, Foreclosure) — the Listings Heatmap\'s count.', 'unit' => 'count', 'entity' => 'listing', 'family' => 'listing', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => null],
        'listings.added_by_staff' => ['label' => 'Added by staff', 'ai' => 'listings added by admins and staff', 'hint' => 'Listings in these dates posted under an admin, editor or secretary account.', 'unit' => 'count', 'entity' => 'listing', 'family' => 'listing', 'as_of' => 'range', 'kind' => 'count', 'better' => 'neutral', 'source' => null],
        'listings.live' => ['label' => 'Live listings', 'ai' => 'live listings', 'hint' => 'Public listings still available — as of now.', 'unit' => 'count', 'entity' => 'listing', 'family' => 'listing', 'as_of' => 'now', 'kind' => 'count', 'better' => 'up', 'source' => null],
        'listings.ppsqm_sale' => ['label' => 'Median ₱/sqm (for sale)', 'ai' => 'the median asking price per square meter for sale', 'hint' => 'Asking price ÷ floor area (lot area for land), listings added in these dates with a believable price and ≥ 10 sqm.', 'unit' => 'php_per_sqm', 'entity' => 'listing', 'family' => 'listing', 'as_of' => 'range', 'kind' => 'median', 'better' => 'neutral', 'source' => null],
        'listings.ppsqm_rent' => ['label' => 'Median ₱/sqm (for rent)', 'ai' => 'the median monthly rent per square meter', 'hint' => 'Monthly rent ÷ floor area (lot area for land), same rules as for sale.', 'unit' => 'php_per_sqm', 'entity' => 'listing', 'family' => 'listing', 'as_of' => 'range', 'kind' => 'median', 'better' => 'neutral', 'source' => null],

        // ── Deals ──
        'deals.total' => ['label' => 'Deals', 'ai' => 'deals marked', 'hint' => 'Listings marked sold, rented or leased with a deal date in these dates. FH records no bookings.', 'unit' => 'count', 'entity' => 'deal', 'family' => 'listing', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => 'deals'],
        'deals.sold' => ['label' => 'Sold', 'ai' => 'listings marked sold', 'hint' => 'Marked sold with a deal date in these dates.', 'unit' => 'count', 'entity' => 'deal', 'family' => 'listing', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => 'deals'],
        'deals.rented' => ['label' => 'Rented', 'ai' => 'listings marked rented', 'hint' => 'Marked rented with a deal date in these dates.', 'unit' => 'count', 'entity' => 'deal', 'family' => 'listing', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => 'deals'],
        'deals.leased' => ['label' => 'Leased', 'ai' => 'listings marked leased', 'hint' => 'Marked leased with a deal date in these dates.', 'unit' => 'count', 'entity' => 'deal', 'family' => 'listing', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => 'deals'],

        // ── Inquiries ──
        'inquiries.received' => ['label' => 'Inquiries', 'ai' => 'property inquiries', 'hint' => 'Clients who inquired on a listing in these dates (one per client per listing).', 'unit' => 'count', 'entity' => 'inquiry', 'family' => 'listing', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => 'chats'],
        'inquiries.unique_clients' => ['label' => 'Clients inquiring', 'ai' => 'clients who inquired', 'hint' => 'Distinct clients behind those inquiries.', 'unit' => 'count', 'entity' => 'user', 'family' => 'listing', 'as_of' => 'range', 'kind' => 'count', 'better' => 'up', 'source' => 'chats'],
        'inquiries.reached_agent' => ['label' => 'Reached the agent', 'ai' => 'inquiries that reached their agent', 'hint' => 'This period\'s inquiries approved and assigned to an agent — as of now.', 'unit' => 'count', 'entity' => 'inquiry', 'family' => 'lifecycle', 'as_of' => 'now', 'kind' => 'count', 'better' => 'up', 'source' => 'chats'],
        'inquiries.answered' => ['label' => 'Answered', 'ai' => 'inquiries answered', 'hint' => 'Of those, answered in the chat by the agent, a team leader or an admin. Phone / WhatsApp replies can\'t be seen.', 'unit' => 'count', 'entity' => 'inquiry', 'family' => 'lifecycle', 'as_of' => 'now', 'kind' => 'count', 'better' => 'up', 'source' => 'chats'],
        'inquiries.first_reply_minutes' => ['label' => 'Median first reply', 'ai' => 'the median time to the agent\'s first reply', 'hint' => 'From the inquiry reaching the agent to the agent\'s first message, for this period\'s answered inquiries.', 'unit' => 'minutes', 'entity' => 'inquiry', 'family' => 'lifecycle', 'as_of' => 'now', 'kind' => 'median', 'better' => 'down', 'source' => 'chats'],
        'inquiries.hanging.pending_review' => ['label' => 'Waiting for approval', 'ai' => 'inquiries waiting over a day for approval', 'hint' => 'Still pending moderation after 24 hours.', 'unit' => 'count', 'entity' => 'inquiry', 'family' => 'lifecycle', 'as_of' => 'now', 'kind' => 'count', 'better' => 'down', 'source' => 'chats'],
        'inquiries.hanging.no_reply' => ['label' => 'Never answered', 'ai' => 'inquiries the agent never answered', 'hint' => 'Reached the agent, and nobody has replied for over 24 hours.', 'unit' => 'count', 'entity' => 'inquiry', 'family' => 'lifecycle', 'as_of' => 'now', 'kind' => 'count', 'better' => 'down', 'source' => 'chats'],
        'inquiries.hanging.client_waiting' => ['label' => 'Client waiting', 'ai' => 'clients waiting over a day for a reply', 'hint' => 'The client wrote last and has waited over 24 hours.', 'unit' => 'count', 'entity' => 'inquiry', 'family' => 'lifecycle', 'as_of' => 'now', 'kind' => 'count', 'better' => 'down', 'source' => 'chats'],
        'inquiries.hanging.total' => ['label' => 'Hanging inquiries', 'ai' => 'hanging inquiries', 'hint' => 'Waiting for approval, never answered, or the client waiting — over 24 hours, as of now.', 'unit' => 'count', 'entity' => 'inquiry', 'family' => 'lifecycle', 'as_of' => 'now', 'kind' => 'count', 'better' => 'down', 'source' => 'chats'],
        'inquiries.stalled' => ['label' => 'Gone quiet', 'ai' => 'conversations where the client went quiet', 'hint' => 'The agent replied last and the client has been silent 7+ days — not the agent\'s fault.', 'unit' => 'count', 'entity' => 'inquiry', 'family' => 'lifecycle', 'as_of' => 'now', 'kind' => 'count', 'better' => 'neutral', 'source' => 'chats'],
        'inquiries.pending_now' => ['label' => 'Pending approval now', 'ai' => 'inquiries pending approval right now', 'hint' => 'Every inquiry waiting for moderation at this moment, whatever its date.', 'unit' => 'count', 'entity' => 'inquiry', 'family' => 'listing', 'as_of' => 'now', 'kind' => 'count', 'better' => 'down', 'source' => 'chats'],

        // ── Sold claims ──
        'claims.sold_unmarked' => ['label' => 'Said sold, still listed', 'ai' => 'listings an agent said were sold but never marked sold', 'hint' => 'An agent replied "sold" / "nahalin na" / "no longer available" in these dates, and the listing is still not marked sold, rented or leased.', 'unit' => 'count', 'entity' => 'listing', 'family' => 'listing', 'as_of' => 'range', 'kind' => 'count', 'better' => 'down', 'source' => 'chats'],
        'claims.reserved_unmarked' => ['label' => 'Said reserved, still listed', 'ai' => 'listings an agent said were reserved but left available', 'hint' => 'Replies like "reserved na" / "may buyer na" in these dates on listings still available.', 'unit' => 'count', 'entity' => 'listing', 'family' => 'listing', 'as_of' => 'range', 'kind' => 'count', 'better' => 'down', 'source' => 'chats'],
        'claims.inquiries_after' => ['label' => 'Leads after "sold"', 'ai' => 'inquiries received after the agent had said sold', 'hint' => 'Inquiries on those listings that arrived after the agent\'s first "sold" reply — leads the missing status update kept bringing in.', 'unit' => 'count', 'entity' => 'inquiry', 'family' => 'listing', 'as_of' => 'now', 'kind' => 'count', 'better' => 'down', 'source' => 'chats'],
    ];

    public static function has(string $key): bool
    {
        return isset(self::METRICS[$key]);
    }

    public static function get(string $key): array
    {
        if (! isset(self::METRICS[$key])) {
            throw new InvalidArgumentException("Unknown metric '{$key}'.");
        }

        return self::METRICS[$key] + ['key' => $key];
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::METRICS);
    }

    /** The filters a metric's drill may carry. */
    public static function filters(string $key): array
    {
        $def = self::get($key);

        return $def['filters'] ?? self::FAMILY_FILTERS[$def['family']];
    }

    /** True when the range starts before the metric's source has data. */
    public static function partial(string $key, ReportRange $range): bool
    {
        $source = self::get($key)['source'];

        return $source !== null && $range->start < self::DATA_SINCE[$source];
    }
}
