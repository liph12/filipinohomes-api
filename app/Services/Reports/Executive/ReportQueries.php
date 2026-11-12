<?php

namespace App\Services\Reports\Executive;

use App\Services\Listing\ListingInsightsService;
use App\Support\OfficeRegionMap;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The listing-side query bases of the Executive Report — listings, deals,
 * inquiries, the inquiry lifecycle (who owes whom a reply) and sold claims —
 * plus the one filter vocabulary every number and every drill shares.
 *
 * Why this is the load-bearing class: the report promises that clicking any
 * number lists exactly the records behind it. That holds BY CONSTRUCTION, not
 * by care: a headline, a breakdown cell and the drill list are all the same
 * builder from here — counted, grouped by a dimension, or paged — and a drill
 * filter is the same expression the breakdown grouped on. Nothing re-states a
 * definition in a second place where it could drift.
 *
 * Extends ListingInsightsService for its base join chain and its location
 * expressions — the Listing Insights / Listings Heatmap chain (project first,
 * then the agent-picked barangay). ONE chain for everything here, inquiries
 * included, so a city row's listings, inquiries and deals all sit in the same
 * town, and its "listings added" equals the heatmap's for the same dates.
 * (Inquiry Analytics places inquiries by the map pin first, so its regional
 * split can differ slightly from this one; the report's notes say so.)
 *
 * The parent's own date/scope setters are never used: they compare against
 * UTC day literals, eight hours off the Manila day. Ranges come in as
 * {@see ReportRange} and bind its UTC instants.
 */
class ReportQueries extends ListingInsightsService
{
    /** Report filter → category name. */
    public const CATEGORIES = [
        'for_sale' => 'For Sale',
        'for_rent' => 'For Rent',
        'foreclosure' => 'Foreclosure',
    ];

    public const DEAL_STATUSES = ['sold', 'rented', 'leased'];

    /** Every filter a drill may carry; MetricRegistry narrows it per metric. */
    public const FILTERS = [
        'city_id', 'province_id', 'psa_region', 'island', 'office_region', 'team_id',
        'agent_id', 'type_id', 'project_id', 'category', 'status', 'listing_id',
    ];

    public function __construct(
        private readonly Geography $geo,
        private readonly TeamDirectory $teams,
    ) {}

    // ── Bases ───────────────────────────────────────────────────────────────

    /**
     * The parent's listing chain (standard categories, nothing deleted) plus
     * what the report groups and filters on: the property type, and the
     * listing owner's agent row, user and role. All LEFT joins on primary
     * keys — a listing missing a type lands in an "Unspecified" bucket rather
     * than vanishing from every total.
     */
    public function listingBase(): Builder
    {
        return $this->baseListingQuery()
            ->leftJoin('property_attributes as pa', 'pa.id', '=', 'properties.property_attribute_id')
            ->leftJoin('property_subtypes as pst', 'pst.id', '=', 'pa.property_subtype_id')
            ->leftJoin('property_types as pt', 'pt.id', '=', 'pst.property_type_id')
            ->leftJoin('agents as owner', 'owner.id', '=', 'listings.agent_id')
            ->leftJoin('users as owner_user', 'owner_user.id', '=', 'owner.user_id')
            ->leftJoin('roles as owner_role', 'owner_role.id', '=', 'owner_user.role_id');
    }

    /**
     * Listings ADDED in the range — `listings.created_at` inside the Manila
     * days, the Listings Heatmap's exact meaning of a dated range
     * (ListingHeatmapService::whereAddedDuring()).
     */
    public function listingsAdded(ReportRange $range): Builder
    {
        return $this->listingBase()
            ->where('listings.created_at', '>=', $range->fromUtc())
            ->where('listings.created_at', '<', $range->toUtc());
    }

    /** Listings live right now — public and active (the Business Pulse "shelf"). */
    public function liveListings(): Builder
    {
        return $this->listingBase()
            ->where('listings.visibility', 'public')
            ->where('properties.status', 'active');
    }

    /**
     * Deals = listings whose property is sold / rented / leased with a
     * `status_change_date` inside the range (the Business Pulse and the boss
     * email's definition). The date is the one the agent typed; FH records no
     * booking, reservation or closing price.
     */
    public function deals(ReportRange $range, ?string $status = null): Builder
    {
        return $this->listingBase()
            ->whereIn('properties.status', $status !== null ? [$status] : self::DEAL_STATUSES)
            ->where('properties.status_change_date', '>=', $range->start)
            ->where('properties.status_change_date', '<', $range->endExclusiveDay());
    }

    /**
     * Inquiries RECEIVED in the range: listing chats opened by a client, one
     * per client per listing (chats are unique on type + listing + client),
     * counted whatever their moderation status — Inquiry Analytics' rule.
     */
    public function inquiries(ReportRange $range): Builder
    {
        return $this->withInquiryChats($this->listingBase())
            ->where('chats.created_at', '>=', $range->fromUtc())
            ->where('chats.created_at', '<', $range->toUtc());
    }

    /**
     * The inquiry lifecycle: each inquiry received in the range, its LATEST
     * conversation (a re-inquiry opens a new one in the same chat), the shape
     * of its live messages, and the one bucket it hangs in right now — or none.
     *
     * One row per chat. Message facts come from a single grouped pass over the
     * range's messages (no per-row subquery), using the
     * messages(conversation_id, created_at) index. "Agent side" is anyone but
     * the client — the agent, a team leader or an admin answering for them —
     * as Client Insights counts a reply.
     *
     * Buckets, mutually exclusive, all measured at $now:
     *   pending_review — still waiting for moderation after 24 h;
     *   no_reply       — reached its agent, nobody has answered for 24 h;
     *   client_waiting — the client wrote last and has waited 24 h (the
     *                    "ghosted" rule of ReviewEligibilityService);
     *   stalled        — the agent side wrote last and the client has been
     *                    quiet 7 days. Not the agent's fault — kept apart.
     * A thread whose last agent-side message is a strong sold claim is in the
     * sold-claims list instead, never "waiting".
     *
     * Returned as a subquery-ready builder selecting chat_id, listing_id,
     * agent_id, office, client_id, received_at, conversation fields, the
     * message facts and `bucket`. Wrap it: DB::query()->fromSub($life, 't').
     */
    public function lifecycle(ReportRange $range, Carbon $now, array $filters = []): Builder
    {
        $from = $range->fromUtc();
        $to = $range->toUtc();

        $latest = DB::table('conversations as c')
            ->join('chats as ch', 'ch.id', '=', 'c.chat_id')
            ->whereNull('c.deleted_at')
            ->where('ch.type', 'listing')
            ->where('ch.created_at', '>=', $from)
            ->where('ch.created_at', '<', $to)
            ->groupBy('c.chat_id')
            ->select('c.chat_id', DB::raw('MAX(c.id) as cid'));

        $messages = DB::table('messages as m')
            ->join('conversations as c2', 'c2.id', '=', 'm.conversation_id')
            ->join('chats as ch2', 'ch2.id', '=', 'c2.chat_id')
            ->whereIn('m.status', ['active', 'updated'])
            ->where('ch2.type', 'listing')
            ->where('ch2.created_at', '>=', $from)
            ->where('ch2.created_at', '<', $to)
            ->groupBy('m.conversation_id')
            ->select(
                'm.conversation_id',
                DB::raw('MAX(CASE WHEN m.user_id = ch2.user_id THEN m.id END) as last_client_id'),
                DB::raw('MAX(CASE WHEN m.user_id = ch2.user_id THEN m.created_at END) as last_client_at'),
                DB::raw('MAX(CASE WHEN m.user_id <> ch2.user_id THEN m.id END) as last_side_id'),
                DB::raw('MAX(CASE WHEN m.user_id <> ch2.user_id THEN m.created_at END) as last_side_at'),
                DB::raw('MIN(CASE WHEN m.user_id = c2.agent_user_id THEN m.created_at END) as first_agent_at'),
            );

        $h24 = $now->copy()->subDay()->utc()->format('Y-m-d H:i:s');
        $d7 = $now->copy()->subDays(7)->utc()->format('Y-m-d H:i:s');

        $query = $this->inquiries($range)
            ->joinSub($latest, 'lc', 'lc.chat_id', '=', 'chats.id')
            ->join('conversations as cv', 'cv.id', '=', 'lc.cid')
            ->leftJoinSub($messages, 'agg', 'agg.conversation_id', '=', 'cv.id')
            ->leftJoin('listing_sold_claims as sc', function ($join) {
                $join->on('sc.message_id', '=', 'agg.last_side_id')->where('sc.tier', 'strong');
            });

        $this->applyFilters($query, $filters);

        return $query->select([
            'chats.id as chat_id',
            'listings.id as listing_id',
            'listings.agent_id as agent_id',
            // The `office_region` filter's column, so lifecycle rows fold by office exactly as it filters.
            'owner.region as office',
            'chats.user_id as client_id',
            'chats.created_at as received_at',
            'cv.id as conversation_id',
            'cv.status as conversation_status',
            'cv.created_at as conversation_created_at',
            'cv.reviewed_at as reviewed_at',
            'agg.last_client_at',
            'agg.last_side_at',
            'agg.first_agent_at',
        ])->selectRaw(
            "CASE
                WHEN cv.status = 'pending' AND cv.created_at <= ? THEN 'pending_review'
                WHEN cv.status = 'accepted' AND cv.agent_user_id IS NOT NULL AND agg.last_side_id IS NULL
                     AND COALESCE(cv.reviewed_at, cv.created_at) <= ? THEN 'no_reply'
                WHEN cv.status = 'accepted' AND agg.last_side_id IS NOT NULL AND agg.last_client_id > agg.last_side_id
                     AND agg.last_client_at <= ? AND sc.id IS NULL THEN 'client_waiting'
                WHEN cv.status = 'accepted' AND agg.last_side_id > COALESCE(agg.last_client_id, 0)
                     AND agg.last_side_at <= ? AND sc.id IS NULL THEN 'stalled'
                ELSE NULL
            END as bucket",
            [$h24, $h24, $h24, $d7]
        )->selectRaw(
            "CASE WHEN cv.status = 'accepted' AND cv.agent_user_id IS NOT NULL THEN 1 ELSE 0 END as reached_agent"
        );
    }

    /**
     * Every inquiry waiting for moderation right now, whatever its date — its
     * latest conversation is still `pending` (the Business Pulse's
     * pending_review, per inquiry rather than per conversation).
     */
    public function pendingInquiries(): Builder
    {
        $latest = DB::table('conversations')
            ->whereNull('deleted_at')
            ->groupBy('chat_id')
            ->select('chat_id', DB::raw('MAX(id) as cid'));

        return $this->withInquiryChats($this->listingBase())
            ->joinSub($latest, 'plc', 'plc.chat_id', '=', 'chats.id')
            ->join('conversations as pcv', 'pcv.id', '=', 'plc.cid')
            ->where('pcv.status', 'pending');
    }

    /**
     * Listings an agent-side reply called sold / rented / taken — tier
     * `strong`, or `medium` for "reserved" — that are STILL not marked
     * sold / rented / leased. The claim must have been said in the range, its
     * message must still be live (an unsent or deleted message withdraws it)
     * and its chat must still exist. One listing can carry several claims;
     * count DISTINCT listings.id.
     */
    public function claims(ReportRange $range, string $tier = 'strong'): Builder
    {
        return $this->listingBase()
            ->join('listing_sold_claims as sc', 'sc.listing_id', '=', 'listings.id')
            ->join('messages as scm', function ($join) {
                $join->on('scm.id', '=', 'sc.message_id')->whereIn('scm.status', ['active', 'updated']);
            })
            ->join('chats as scc', function ($join) {
                $join->on('scc.id', '=', 'sc.chat_id')->whereNull('scc.deleted_at');
            })
            ->where('sc.tier', $tier)
            ->where('sc.said_at', '>=', $range->fromUtc())
            ->where('sc.said_at', '<', $range->toUtc())
            ->whereNotIn('properties.status', self::DEAL_STATUSES);
    }

    /**
     * Inquiries that arrived AFTER the first strong claim on a listing that is
     * still unmarked — leads the site kept sending to something the agent had
     * already said was gone (a listing marked sold stops accepting inquiries,
     * so these are exactly the leads the missing status update cost).
     */
    public function inquiriesAfterClaims(ReportRange $range): Builder
    {
        $firstClaim = DB::table('listing_sold_claims')
            ->where('tier', 'strong')
            ->groupBy('listing_id')
            ->select('listing_id', DB::raw('MIN(said_at) as first_at'));

        $unmarked = $this->claims($range)->select('listings.id');

        return $this->withInquiryChats($this->listingBase())
            ->joinSub($firstClaim, 'fc', 'fc.listing_id', '=', 'listings.id')
            ->whereColumn('chats.created_at', '>', 'fc.first_at')
            ->whereIn('listings.id', $unmarked);
    }

    // ── Dimensions and filters ──────────────────────────────────────────────

    /**
     * SQL for a grouping dimension. Raw ids — provinces and cities are folded
     * in PHP by {@see Geography}, and filters expand back to whole groups, so
     * groups and drills agree whichever duplicate row a listing sits under.
     */
    public function dimExpr(string $dim): string
    {
        return match ($dim) {
            'city' => $this->cityIdExpr(),
            'province' => $this->provinceIdExpr(),
            'type' => 'pt.id',
            'project' => 'projects.id',
            'agent' => 'listings.agent_id',
            'office' => 'owner.region',
            'category' => 'categories.name',
            'status' => 'properties.status',
            'listing' => 'listings.id',
            default => throw new \InvalidArgumentException("Unknown dimension '{$dim}'."),
        };
    }

    /**
     * [raw dimension value => distinct count] for a base, in one grouped pass.
     * NULL groups come back under the key '' (PHP arrays can't hold null keys).
     */
    public function groupCount(Builder $base, string $dim, string $idColumn): array
    {
        $expr = $this->dimExpr($dim);

        return (clone $base)
            ->selectRaw("{$expr} as d, COUNT(DISTINCT {$idColumn}) as n")
            ->groupBy(DB::raw($expr))
            ->get()
            ->mapWithKeys(fn ($row) => [(string) ($row->d ?? '') => (int) $row->n])
            ->all();
    }

    /**
     * Apply drill/breakdown filters. Each one is the exact inverse of a
     * dimension above, so a breakdown cell and the list behind it count the
     * same rows. `0` / `unknown` / `unassigned` select the "none" bucket.
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        $province = $this->provinceIdExpr();
        $city = $this->cityIdExpr();

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            switch ($key) {
                case 'city_id':
                    (int) $value === 0
                        ? $query->whereRaw("({$city}) IS NULL")
                        : $query->whereIn(DB::raw($city), $this->geo->cityGroupIds((int) $value));
                    break;
                case 'province_id':
                    (int) $value === 0
                        ? $query->whereRaw("({$province}) IS NULL")
                        : $query->whereIn(DB::raw($province), $this->geo->provinceGroupIds((int) $value));
                    break;
                case 'psa_region':
                    if ($value === Geography::UNKNOWN_REGION) {
                        $unmapped = $this->geo->unmappedProvinceIds();
                        $query->where(function ($q) use ($province, $unmapped) {
                            $q->whereRaw("({$province}) IS NULL");
                            if ($unmapped !== []) {
                                $q->orWhereIn(DB::raw($province), $unmapped);
                            }
                        });
                    } else {
                        $query->whereIn(DB::raw($province), $this->geo->provinceIdsForRegion((string) $value) ?: [0]);
                    }
                    break;
                case 'island':
                    $query->whereIn(DB::raw($province), $this->geo->provinceIdsForIsland((string) $value) ?: [0]);
                    break;
                case 'office_region':
                    if ($value === 'unassigned') {
                        $query->where(function ($q) {
                            $q->whereNull('owner.region')
                                ->orWhere('owner.region', '')
                                ->orWhereNotIn('owner.region', OfficeRegionMap::REGIONS);
                        });
                    } else {
                        $query->where('owner.region', (string) $value);
                    }
                    break;
                case 'team_id':
                    (int) $value === 0
                        ? $query->whereNotIn('listings.agent_id', $this->teams->allMemberIds() ?: [0])
                        : $query->whereIn('listings.agent_id', $this->teams->members((int) $value) ?: [0]);
                    break;
                case 'agent_id':
                    $query->where('listings.agent_id', (int) $value);
                    break;
                case 'type_id':
                    (int) $value === 0 ? $query->whereNull('pt.id') : $query->where('pt.id', (int) $value);
                    break;
                case 'project_id':
                    (int) $value === 0 ? $query->whereNull('projects.id') : $query->where('projects.id', (int) $value);
                    break;
                case 'category':
                    $query->where('categories.name', self::CATEGORIES[$value] ?? (string) $value);
                    break;
                case 'status':
                    $query->where('properties.status', (string) $value);
                    break;
                case 'listing_id':
                    $query->where('listings.id', (int) $value);
                    break;
            }
        }

        return $query;
    }

    // ── Row details (drill hydration) ───────────────────────────────────────

    /**
     * Display facts for a page of listing ids — name, place, type, prices,
     * area, owner — keyed by listing id. Primary-key lookups only.
     */
    public function listingDetails(array $ids)
    {
        if ($ids === []) {
            return collect();
        }

        return $this->listingBase()
            ->whereIn('listings.id', $ids)
            ->get([
                'listings.id', 'listings.name', 'listings.slug', 'listings.code', 'listings.price',
                'listings.featured_photo', 'listings.created_at', 'listings.visibility', 'listings.agent_id',
                'properties.id as property_id', 'properties.status', 'properties.status_change_date',
                'properties.geo_coordinates', 'categories.name as category',
                'pt.name as type', 'pa.floor_area', 'pa.lot_area',
                'projects.id as project_id', 'projects.name as project',
                DB::raw($this->cityIdExpr().' as city_id'),
                DB::raw($this->cityNameExpr().' as city'),
                DB::raw($this->provinceNameExpr().' as province'),
                'owner.first_name as agent_first_name', 'owner.last_name as agent_last_name',
                'owner.avatar as agent_avatar', 'owner_user.name as agent_user_name', 'owner_user.avatar as agent_user_avatar',
            ])
            ->keyBy('id');
    }

    /** Display facts for a page of inquiry chat ids, keyed by chat id. */
    public function inquiryDetails(array $chatIds)
    {
        if ($chatIds === []) {
            return collect();
        }

        $latest = DB::table('conversations')
            ->whereIn('chat_id', $chatIds)
            ->whereNull('deleted_at')
            ->groupBy('chat_id')
            ->select('chat_id', DB::raw('MAX(id) as cid'));

        return $this->withInquiryChats($this->listingBase())
            ->leftJoinSub($latest, 'dlc', 'dlc.chat_id', '=', 'chats.id')
            ->leftJoin('conversations as dcv', 'dcv.id', '=', 'dlc.cid')
            ->whereIn('chats.id', $chatIds)
            ->get([
                'chats.id as chat_id', 'chats.created_at as received_at', 'chats.user_id as client_id',
                'inq.name as client_name', 'inq.avatar as client_avatar',
                'listings.id as listing_id', 'listings.name as listing_name', 'listings.slug as listing_slug',
                'listings.code as listing_code', 'listings.featured_photo', 'listings.agent_id',
                'owner.first_name as agent_first_name', 'owner.last_name as agent_last_name',
                'owner.avatar as agent_avatar', 'owner_user.name as agent_user_name', 'owner_user.avatar as agent_user_avatar',
                'dcv.status as conversation_status',
                DB::raw($this->cityNameExpr().' as city'),
            ])
            ->keyBy('chat_id');
    }

    /** Join a listing base to its client-opened inquiry chats. */
    private function withInquiryChats(Builder $query): Builder
    {
        return $query
            ->join('chats', function ($join) {
                $join->on('chats.type_id', '=', 'listings.id')
                    ->where('chats.type', 'listing')
                    ->whereNull('chats.deleted_at');
            })
            ->join('users as inq', 'inq.id', '=', 'chats.user_id')
            ->join('roles as inq_role', function ($join) {
                $join->on('inq_role.id', '=', 'inq.role_id')->where('inq_role.name', 'client');
            });
    }
}
