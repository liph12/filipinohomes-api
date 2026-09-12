<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\Recipient;
use App\Natcon\Services\NatconRegClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The published NATCON awardee roster — open, unauthenticated, read-only.
 *
 * ─── Privacy posture, which is the whole design ──────────────────────────────
 *
 * This is the ONLY natcon endpoint that hands out a list of people to anyone
 * who asks, so it is built as a whitelist and not as a resource with fields
 * removed. What ships is what already appears on the printed materials and the
 * public landing page: who was recognised, for which award, on whose team, from
 * which province.
 *
 * DELIBERATELY ABSENT, and none of it may be added without a decision that is
 * not a developer's to make:
 *   · email, phone, reg_id, seat number — 309 agents' contact details are a
 *     scraper's shopping list, and info@ carries this company's login OTPs.
 *   · total_sales and the LR qualifier payload — an agent's production figure
 *     is commercial information about them, not a fact about the event.
 *   · anything from the registration form — shirt sizes, birthdays, who is
 *     bringing whom. That lives in natcon-api-v2 and is nobody else's business.
 *   · attendance. "Is this person going?" is a movement question about a named
 *     individual, and it is not answered here at any price.
 *
 * The photo is opt-in through `include=photo` rather than on by default. It is
 * the same image the public materials carry, so it is a legitimate part of a
 * public roster — but 309 headshots keyed to names, downloadable in one call,
 * is a different exposure from a poster, and that should be somebody's explicit
 * choice rather than a side effect of adding pagination.
 *
 * ─── The registration layer ──────────────────────────────────────────────────
 *
 * `include=registration` adds who has registered, who is VVIP or Elite, how
 * many of the awardee's own party are attending, and how many guests they are
 * bringing; `registered`, `vvip`, `elite` and `guests` filter on it. That data
 * belongs to natcon-api-v2, so it is fetched server-to-server (NatconRegClient)
 * and joined by email — the only identifier the two services share.
 *
 * This was originally withheld, on the grounds that "is this person going?" is
 * a movement question about a named individual. It ships at the event owner's
 * explicit request: it is their convention, their awardees, and the roster of
 * who won is already public. What has NOT moved is everything in the list
 * above — no contact details, no sales figures, no form answers — and guests'
 * NAMES stay behind their own `include=guests`, because a guest never entered
 * a competition. Their count is a fact about the awardee and the seating;
 * their name is not.
 *
 * If v2 cannot be reached the roster still serves, `meta.registration.available`
 * says false, and a registration FILTER returns nothing rather than quietly
 * returning everyone — an unfilterable filter must not look like an answer.
 *
 * ─── Load ────────────────────────────────────────────────────────────────────
 *
 * api2 runs prefork with a hard worker ceiling, so a public list endpoint gets
 * a cache in front of it or it becomes the cheapest way to exhaust the pool.
 * Results are memoised per distinct query for five minutes and the response
 * carries a matching Cache-Control, so Cloudflare absorbs the repeats. Five
 * minutes also means the roster self-heals after an admin edit without anybody
 * having to purge anything.
 */
class PublicAwardeeController extends Controller
{
    /** Longer than a burst of refreshes, shorter than anyone would notice. */
    private const TTL = 300;

    private const PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year'     => 'nullable|integer|min:2000|max:2100',
            'q'        => 'nullable|string|max:120',
            'province' => 'nullable|string|max:120',
            'team'     => 'nullable|string|max:120',
            // top_agent is the default and is stored as NULL, so it is listed
            // here but filtered as "no segment" below.
            'award'    => 'nullable|string|in:top_agent,' . implode(',', Recipient::SEGMENTS),
            'sort'     => 'nullable|string|in:name,-name,team,-team,province,-province',
            /*
             * The registration filters. Every one of them is answered by
             * natcon-api-v2, so asking for any of them fetches that layer —
             * see registrationFor() and the note on what it does when v2 is
             * unreachable.
             */
            'registered' => 'nullable|boolean',
            'confirmed'  => 'nullable|boolean',
            'vvip'       => 'nullable|boolean',
            'elite'      => 'nullable|boolean',
            'guests'     => 'nullable|boolean',
            'page'     => 'nullable|integer|min:1|max:1000',
            'per_page' => 'nullable|integer|min:1|max:' . self::MAX_PER_PAGE,
            // `photo` adds photo_url; `facets` adds the province and team
            // lists so a caller can build filter dropdowns without a second
            // endpoint; `registration` adds the v2 status block; `guests` adds
            // the guests' names inside it.
            'include'  => 'nullable|string|max:64',
        ]);

        $event = isset($data['year'])
            ? NatconEvent::forYear((int) $data['year'])
            : NatconEvent::active();

        if (! $event) {
            return response()->json([
                'message' => isset($data['year'])
                    ? "No NATCON event for {$data['year']}."
                    : 'No NATCON event is currently live.',
            ], 404);
        }

        $include  = array_filter(array_map('trim', explode(',', (string) ($data['include'] ?? ''))));
        $withPhoto = in_array('photo', $include, true);
        $withFacets = in_array('facets', $include, true);
        $withGuestNames = in_array('guests', $include, true);

        /**
         * ─── include=tickets is KEYED, and everything else here is not ──────
         *
         * A ticket code is the credential the door scans. Published openly it
         * is a list of ways to walk in as somebody else — and to burn their
         * scan before they arrive, which is worse, because the real person is
         * then the one turned away.
         *
         * So this one include asks for a header. An unset key refuses it
         * outright rather than waving it through: a missing gate must never
         * read as "no gate needed".
         */
        $withTickets = in_array('tickets', $include, true);

        if ($withTickets) {
            $expected = (string) config('natcon.ticket_key', '');
            $given    = (string) $request->header('X-NATCON-Ticket-Key', '');

            if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
                return response()->json([
                    'message' => 'include=tickets needs the X-NATCON-Ticket-Key header. '
                        .'Ticket codes are what the door scans, so they are not part of the open roster.',
                ], 403);
            }
        }

        /*
         * Filtering on a registration fact needs the layer whether or not the
         * caller asked to SEE it — "who has registered" is a question about
         * data this service does not hold.
         */
        $filtersRegistration = $request->filled('registered')
            || $request->filled('confirmed')
            || $request->filled('vvip')
            || $request->filled('elite')
            || $request->filled('guests');

        $withRegistration = in_array('registration', $include, true)
            || $withGuestNames
            || $withTickets
            || $filtersRegistration;

        $page    = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? self::PER_PAGE);

        // Keyed on everything that changes the answer, so two callers asking
        // different questions cannot be served each other's page.
        $cacheKey = 'natcon:public-awardees:' . md5(json_encode([
            $event->id,
            mb_strtolower(trim((string) ($data['q'] ?? ''))),
            mb_strtolower(trim((string) ($data['province'] ?? ''))),
            mb_strtolower(trim((string) ($data['team'] ?? ''))),
            $data['award'] ?? '',
            $data['sort'] ?? 'name',
            $page,
            $perPage,
            $withPhoto,
            $withFacets,
            $withRegistration,
            $withGuestNames,
            // ⚠️ In the key, so a response carrying codes can never be handed
            //    back to a caller who did not present the header.
            $withTickets,
            $request->filled('registered') ? $request->boolean('registered') : null,
            $request->filled('confirmed') ? $request->boolean('confirmed') : null,
            $request->filled('vvip') ? $request->boolean('vvip') : null,
            $request->filled('elite') ? $request->boolean('elite') : null,
            $request->filled('guests') ? $request->boolean('guests') : null,
        ]));

        $payload = Cache::remember($cacheKey, self::TTL, fn () => $this->build(
            $event,
            $request,
            $data,
            $page,
            $perPage,
            $withPhoto,
            $withFacets,
            $withRegistration,
            $withGuestNames,
            $withTickets,
        ));

        return response()
            ->json($payload)
            ->header('Cache-Control', 'public, max-age=' . self::TTL);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function build(
        NatconEvent $event,
        Request $request,
        array $data,
        int $page,
        int $perPage,
        bool $withPhoto,
        bool $withFacets,
        bool $withRegistration,
        bool $withGuestNames,
        bool $withTickets = false,
    ): array {
        /*
         * The registration layer, fetched once for the whole year and joined by
         * email — the only identifier this service and v2 share.
         *
         * null means v2 could not be answered. The roster still ships, the
         * registration block is omitted, and meta says so: a public endpoint
         * that 500s because another service is down fails the people who came
         * for the list of awardees, which this service holds by itself.
         */
        $registration = $withRegistration
            ? app(NatconRegClient::class)->byEmail((int) $event->year, $withTickets)
            : null;
        $query = Recipient::query()
            ->where('natcon_event_id', $event->id)
            // An excluded recipient is an admin saying "not this person". That
            // decision has to reach a public list by omission, or the list
            // contradicts the materials.
            ->where('status', '!=', Recipient::STATUS_EXCLUDED);

        // Word by word, every word somewhere — the same rule the admin search
        // uses, because a roster is searched by surname ("josue") far more often
        // than by a whole name, and display_name is where an imported couple's
        // whole name lives.
        if ($term = trim((string) ($data['q'] ?? ''))) {
            $columns = ['display_name', 'first_name', 'last_name', 'team', 'state'];

            foreach (array_slice(preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6) as $word) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word) . '%';

                $query->where(function ($q) use ($columns, $like) {
                    foreach ($columns as $i => $column) {
                        $i === 0
                            ? $q->where($column, 'like', $like)
                            : $q->orWhere($column, 'like', $like);
                    }
                });
            }
        }

        if ($province = trim((string) ($data['province'] ?? ''))) {
            $query->where('state', $province);
        }

        if ($team = trim((string) ($data['team'] ?? ''))) {
            $query->where('team', $team);
        }

        if ($award = $data['award'] ?? null) {
            // top_agent is the default award and is stored as NULL, so it is a
            // "no segment" filter rather than a value match.
            $award === 'top_agent'
                ? $query->whereNull('award_segment')
                : $query->where('award_segment', $award);
        }

        /*
         * A registration filter is applied as a whereIn on EMAIL rather than in
         * PHP after the fact, so pagination still counts the right total. The
         * email set comes from v2's answer; if v2 is unreachable the filter
         * cannot be honoured, and returning the unfiltered roster would be a
         * lie — so it yields an empty page and meta explains why.
         */
        $wants = fn (string $key, callable $test) => $request->filled($key)
            ? [$request->boolean($key), $test]
            : null;

        $predicates = array_filter([
            $wants('registered', fn (array $r) => (bool) ($r['registered'] ?? false)),
            $wants('confirmed', fn (array $r) => (bool) ($r['confirmed'] ?? false)),
            $wants('vvip', fn (array $r) => (bool) ($r['is_vvip'] ?? false)),
            $wants('elite', fn (array $r) => (bool) ($r['is_elite'] ?? false)),
            $wants('guests', fn (array $r) => count($r['guests'] ?? []) > 0),
        ]);

        if ($predicates !== []) {
            $emails = [];

            foreach (($registration ?? []) as $email => $row) {
                $keep = true;

                foreach ($predicates as [$expected, $test]) {
                    if ($test($row) !== $expected) {
                        $keep = false;
                        break;
                    }
                }

                if ($keep) {
                    $emails[] = $email;
                }
            }

            // whereRaw LOWER(): the roster stores the address as typed, and v2
            // keys on the lowercased form.
            $query->whereIn(DB::raw('LOWER(email)'), $emails ?: ['\x00-no-match']);
        }

        [$column, $direction] = match ($data['sort'] ?? 'name') {
            '-name'     => ['display_name', 'desc'],
            'team'      => ['team', 'asc'],
            '-team'     => ['team', 'desc'],
            'province'  => ['state', 'asc'],
            '-province' => ['state', 'desc'],
            default     => ['display_name', 'asc'],
        };

        // Blanks last in both directions: a roster whose first page is the rows
        // with no team is not sorted, it is obstructed. `id` breaks ties so
        // page 2 cannot repeat a row from page 1.
        $paginator = $query
            ->orderByRaw("CASE WHEN {$column} IS NULL OR {$column} = '' THEN 1 ELSE 0 END")
            ->orderBy($column, $direction)
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginator->items())->map(function (Recipient $r) use (
            $withPhoto,
            $withRegistration,
            $withGuestNames,
            $withTickets,
            $registration,
        ) {
            $row = [
                'id'        => $r->id,
                'name'      => $r->displayName(),
                // A couple registered on one login is two people. Split
                // server-side because personNames() owns that rule.
                'people'    => $r->personNames(),
                'team'      => $r->team,
                'team_logo' => data_get($r->qualifier_payload, 'sales_team_member.sales_team.teamlogo') ?: null,
                'province'  => $r->state,
                'award'     => [
                    'segment' => $r->award_segment ?? 'top_agent',
                    'title'   => $this->awardTitle($r->award_segment),
                ],
            ];

            if ($withRegistration) {
                $row['registration'] = $this->registrationFor($r, $registration, $withGuestNames, $withTickets);
            }

            if ($withPhoto) {
                // finalPhotoUrl(), not the raw upload: it is null while a
                // reviewer has the photo on file ruled unusable, which is the
                // honest answer rather than publishing a rejected image.
                $row['photo_url'] = $r->finalPhotoUrl();
            }

            return $row;
        })->values();

        $meta = [
            'event' => [
                'year'  => $event->year,
                'name'  => $event->name,
                'slug'  => $event->slug,
                'venue' => $event->venue,
                'dates' => $event->dateLabel(),
            ],
            'page'      => $paginator->currentPage(),
            'per_page'  => $paginator->perPage(),
            'total'     => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'from'      => $paginator->firstItem(),
            'to'        => $paginator->lastItem(),
        ];

        if ($withRegistration) {
            // Said plainly rather than left to be inferred from nulls: a caller
            // filtering on "registered" needs to know whether the answer is
            // "nobody matched" or "we could not ask".
            $meta['registration'] = $registration === null
                ? ['available' => false, 'note' => 'Registration data could not be reached; the roster is unfiltered by it.']
                : ['available' => true, 'known' => count($registration)];
        }

        if ($withFacets) {
            $base = fn () => Recipient::where('natcon_event_id', $event->id)
                ->where('status', '!=', Recipient::STATUS_EXCLUDED);

            $meta['filters'] = [
                'provinces' => $base()->whereNotNull('state')->where('state', '!=', '')
                    ->distinct()->orderBy('state')->pluck('state')->values(),
                'teams' => $base()->whereNotNull('team')->where('team', '!=', '')
                    ->distinct()->orderBy('team')->pluck('team')->values(),
                'awards' => array_merge(['top_agent'], Recipient::SEGMENTS),
            ];
        }

        return ['data' => $rows, 'meta' => $meta];
    }

    /**
     * One awardee's registration facts, or nulls when v2 has never heard of
     * them (added to the roster after the last sync, say).
     *
     * ⚠️ Guests' NAMES are behind `include=guests`; the count is not. A guest
     *    is a private individual who never entered a competition — the awardee
     *    did — so naming them publicly is a deliberate request, while "this
     *    awardee is bringing two people" is a fact about the awardee and the
     *    seating.
     *
     * @param  array<string, array<string, mixed>>|null  $registration
     * @return array<string, mixed>
     */
    private function registrationFor(
        Recipient $r,
        ?array $registration,
        bool $withGuestNames,
        bool $withTickets = false,
    ): array
    {
        if ($registration === null) {
            return ['available' => false];
        }

        $row = $registration[mb_strtolower(trim((string) $r->email))] ?? null;

        if ($row === null) {
            // Known to the roster, unknown to registrations — not the same as
            // "has not registered", and worth saying so.
            return ['available' => true, 'known' => false];
        }

        $guests = is_array($row['guests'] ?? null) ? $row['guests'] : [];

        $block = [
            'available'  => true,
            'known'      => true,
            'status'     => $row['status'] ?? null,
            'registered' => (bool) ($row['registered'] ?? false),
            'confirmed'  => (bool) ($row['confirmed'] ?? false),
            'is_vvip'    => (bool) ($row['is_vvip'] ?? false),
            'is_elite'   => (bool) ($row['is_elite'] ?? false),
            // Heads on the awardee's own registration who said they are coming
            // — a couple where one attends is 1, not 2.
            'attending'  => (int) ($row['attending'] ?? 0),
            'guest_count' => count($guests),
            /**
             * ⚠️ Counts, never codes. A ticket code is what the door scans —
             *    a public list of them is a list of ways to walk in as
             *    somebody else. `issued` answers "has their QR gone out yet",
             *    which is the question worth asking in public.
             */
            'tickets' => [
                'eligible' => (int) data_get($row, 'tickets.eligible', 0),
                'issued'   => (int) data_get($row, 'tickets.issued', 0),
            ],
        ];

        if ($withTickets) {
            // One entry per PERSON: a couple is two tickets, and whoever is
            // holding the list needs to know whose is whose.
            $block['ticket_people'] = array_values($row['ticket_people'] ?? []);
        }

        if ($withGuestNames || $withTickets) {
            $block['guests'] = array_values(array_map(function (array $g) use ($withTickets) {
                $entry = [
                    'name'          => $g['name'] ?? null,
                    'registered'    => (bool) ($g['registered'] ?? false),
                    'ticket_issued' => (bool) ($g['ticket_issued'] ?? false),
                ];

                if ($withTickets) {
                    $entry['ticket_people'] = array_values($g['ticket_people'] ?? []);
                }

                return $entry;
            }, $guests));
        }

        return $block;
    }

    private function awardTitle(?string $segment): string
    {
        return match ($segment) {
            Recipient::SEGMENT_GLOBAL_PARTNER       => 'TOP GLOBAL PARTNER',
            Recipient::SEGMENT_FHI_GLOBAL           => 'TOP FHI GLOBAL AGENT',
            Recipient::SEGMENT_RENT_MANAGER         => 'TOP RENT MANAGER PRO',
            Recipient::SEGMENT_RENT_MANAGER_RM_PRO  => 'TOP RENT MANAGER & RM PRO',
            default                                 => 'TOP AGENT',
        };
    }
}
