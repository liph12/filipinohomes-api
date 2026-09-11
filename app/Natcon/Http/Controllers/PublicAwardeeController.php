<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\Recipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
            'page'     => 'nullable|integer|min:1|max:1000',
            'per_page' => 'nullable|integer|min:1|max:' . self::MAX_PER_PAGE,
            // `photo` adds photo_url; `facets` adds the province and team lists
            // so a caller can build filter dropdowns without a second endpoint.
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
        ]));

        $payload = Cache::remember($cacheKey, self::TTL, fn () => $this->build(
            $event,
            $data,
            $page,
            $perPage,
            $withPhoto,
            $withFacets,
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
        array $data,
        int $page,
        int $perPage,
        bool $withPhoto,
        bool $withFacets,
    ): array {
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

        $rows = collect($paginator->items())->map(function (Recipient $r) use ($withPhoto) {
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

    private function awardTitle(?string $segment): string
    {
        return match ($segment) {
            Recipient::SEGMENT_GLOBAL_PARTNER       => 'TOP GLOBAL PARTNER',
            Recipient::SEGMENT_FHI_GLOBAL           => 'TOP FHI GLOBAL AGENT',
            Recipient::SEGMENT_RENT_MANAGER         => 'TOP RENT MANAGER',
            Recipient::SEGMENT_RENT_MANAGER_RM_PRO  => 'TOP RENT MANAGER & RM PRO',
            default                                 => 'TOP AGENT',
        };
    }
}
