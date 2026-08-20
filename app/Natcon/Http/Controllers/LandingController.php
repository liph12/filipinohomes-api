<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\AnnouncementReaction;
use App\Natcon\Models\NatconAnnouncement;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\Recap;
use App\Natcon\Models\Sponsor;
use App\Natcon\Services\LandingCachePurger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Everything the public NATCON landing page shows that a person writes.
 *
 * The event itself — name, dates, venue, banner, deadline — comes from
 * natcon_events via PublicController. This is the changing content: the
 * announcements feed and the list of past conventions.
 *
 * ⚠️ The public methods here serve an INDEXABLE page, unlike the awardee
 *    surface. Nothing may leak a name, an email or an awardee photo — every
 *    field returned below is copy somebody wrote for publication.
 */
class LandingController extends Controller
{
    // ── Public ───────────────────────────────────────────────────────────────

    /**
     * Announcements for one convention year.
     *
     * Keyed by year rather than event id because the URL is /natcon/2026 and the
     * page should not have to resolve an id first. An unknown year returns an
     * empty list, not a 404 — the page itself already 404s on a year that has no
     * event, and a second failure mode here would only obscure that.
     */
    public function announcements(int $year): JsonResponse
    {
        $event = NatconEvent::forYear($year);

        if (! $event) {
            return response()->json(['data' => []]);
        }

        $rows = NatconAnnouncement::where('natcon_event_id', $event->id)
            ->live()
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows->map(fn ($a) => $this->presentAnnouncement($a, $event))]);
    }

    /** Every past convention that has a recording. */
    public function recaps(): JsonResponse
    {
        return response()->json([
            'data' => Recap::live()->get()->map(fn (Recap $r) => $this->presentRecap($r)),
        ]);
    }

    /**
     * Sponsor logos for one convention year — major, minor and star benefactor
     * tiers in one flat list; the page groups by tier. Keyed by year for the
     * same reason announcements are.
     */
    public function sponsors(int $year): JsonResponse
    {
        $event = NatconEvent::forYear($year);

        if (! $event) {
            return response()->json(['data' => []]);
        }

        $rows = Sponsor::where('natcon_event_id', $event->id)
            // The 'library' pool is admin-only — never on the public page.
            ->whereIn('tier', Sponsor::TIERS)
            ->live()
            ->get();

        return response()->json(['data' => $rows->map(fn (Sponsor $s) => $this->presentSponsor($s))]);
    }

    // ── Admin ────────────────────────────────────────────────────────────────

    public function adminAnnouncements(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);

        // Drafts included — this is the editing surface, and a draft you cannot
        // see is a draft you rewrite from scratch.
        $rows = NatconAnnouncement::where('natcon_event_id', $event->id)
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();

        // One aggregate for the whole list rather than a query per row. Admin
        // only and uncached, so the plain GROUP BY is fine here.
        $tally = AnnouncementReaction::tallyFor($rows->pluck('id')->all());

        return response()->json(['data' => $rows->map(
            fn ($a) => $this->presentAnnouncement($a, $event, detailed: true, reactionCounts: $tally[$a->id] ?? [])
        )]);
    }

    public function storeAnnouncement(Request $request): JsonResponse
    {
        $data = $this->validateAnnouncement($request);
        $event = $this->resolveEvent($request);

        $row = new NatconAnnouncement($data + ['natcon_event_id' => $event->id]);
        $row->created_by = $request->user()?->id;
        $row->published_at = $this->publishedAt($data, null, $event->timezone);
        $row->auditSource = 'admin_natcon_announcement';
        $row->save();

        $this->purge($event->year);

        return response()->json(['data' => $this->presentAnnouncement($row, $event, detailed: true)], 201);
    }

    public function updateAnnouncement(Request $request, NatconAnnouncement $announcement): JsonResponse
    {
        $data = $this->validateAnnouncement($request, partial: true);

        $announcement->fill($data);
        $announcement->published_at = $this->publishedAt($data, $announcement, $announcement->event?->timezone);
        $announcement->auditSource = 'admin_natcon_announcement';
        $announcement->save();

        $this->purge($announcement->event?->year);

        return response()->json([
            'data' => $this->presentAnnouncement($announcement->fresh(), $announcement->event, detailed: true),
        ]);
    }

    public function destroyAnnouncement(NatconAnnouncement $announcement): JsonResponse
    {
        // Read the year BEFORE deleting — the relation is unreachable afterwards.
        $year = $announcement->event?->year;

        // Soft delete: an announcement pulled in a hurry is often wanted back,
        // and the audit trail is worth more than the row.
        $announcement->auditSource = 'admin_natcon_announcement';
        $announcement->delete();

        $this->purge($year);

        return response()->json(['message' => 'Announcement removed.']);
    }

    public function adminRecaps(): JsonResponse
    {
        $rows = Recap::orderByDesc('sort_order')->orderByDesc('year')->get();

        return response()->json(['data' => $rows->map(fn (Recap $r) => $this->presentRecap($r, detailed: true))]);
    }

    public function storeRecap(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2000|max:2100|unique:natcon_recaps,year',
            'title' => 'required|string|max:191',
            'video_url' => 'required|url|max:2048',
            'thumbnail_url' => 'nullable|url|max:2048',
            'is_published' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
        ]);

        $row = new Recap($data);
        $row->created_by = $request->user()?->id;
        $row->auditSource = 'admin_natcon_recap';
        $row->save();

        return response()->json(['data' => $this->presentRecap($row, detailed: true)], 201);
    }

    public function updateRecap(Request $request, Recap $recap): JsonResponse
    {
        $data = $request->validate([
            // Ignores itself, so saving a row without changing the year works.
            'year' => 'sometimes|integer|min:2000|max:2100|unique:natcon_recaps,year,'.$recap->id,
            'title' => 'sometimes|string|max:191',
            'video_url' => 'sometimes|url|max:2048',
            'thumbnail_url' => 'sometimes|nullable|url|max:2048',
            'is_published' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
        ]);

        $recap->auditSource = 'admin_natcon_recap';
        $recap->fill($data)->save();

        return response()->json(['data' => $this->presentRecap($recap->fresh(), detailed: true)]);
    }

    public function destroyRecap(Recap $recap): JsonResponse
    {
        $recap->auditSource = 'admin_natcon_recap';
        $recap->delete();

        return response()->json(['message' => 'Recap removed.']);
    }

    public function adminSponsors(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);

        // Hidden rows included — this is the editing surface.
        $rows = Sponsor::where('natcon_event_id', $event->id)
            ->orderBy('tier')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (Sponsor $s) => $this->presentSponsor($s, detailed: true))]);
    }

    public function storeSponsor(Request $request): JsonResponse
    {
        $data = $this->validateSponsor($request);
        $event = $this->resolveEvent($request);

        $row = new Sponsor($data + ['natcon_event_id' => $event->id]);
        $row->created_by = $request->user()?->id;
        $row->auditSource = 'admin_natcon_sponsor';
        $row->save();

        $this->purge($event->year);

        return response()->json(['data' => $this->presentSponsor($row, detailed: true)], 201);
    }

    public function updateSponsor(Request $request, Sponsor $sponsor): JsonResponse
    {
        $data = $this->validateSponsor($request, partial: true);

        $sponsor->auditSource = 'admin_natcon_sponsor';
        $sponsor->fill($data)->save();

        $this->purge($sponsor->event?->year);

        return response()->json(['data' => $this->presentSponsor($sponsor->fresh(), detailed: true)]);
    }

    public function destroySponsor(Sponsor $sponsor): JsonResponse
    {
        // Before the delete: see destroyAnnouncement.
        $year = $sponsor->event?->year;

        $sponsor->auditSource = 'admin_natcon_sponsor';
        $sponsor->delete();

        $this->purge($year);

        return response()->json(['message' => 'Sponsor removed.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Drop the public page's cached copy so an edit is visible immediately
     * instead of whenever the ISR window happens to expire.
     *
     * Deliberately AFTER the save and never guarded by its result: the content
     * is already committed, and a frontend that is redeploying or a secret that
     * is not set must not turn a successful edit into an error. See
     * LandingCachePurger.
     */
    private function purge(?int $year): void
    {
        app(LandingCachePurger::class)->purgeYear($year);
    }

    private function validateAnnouncement(Request $request, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => "{$rule}|string|max:191",
            'body' => "{$rule}|string|max:5000",
            'image_url' => 'sometimes|nullable|url|max:2048',
            'is_published' => 'sometimes|boolean',
            'is_pinned' => 'sometimes|boolean',
            // Optional. Omitted means "now, when it is first published".
            'published_at' => 'sometimes|nullable|date',
        ]);
    }

    /**
     * When this went live.
     *
     * Stamped the first time it is published and then left alone, so editing a
     * typo three days later does not shove the post back to the top of the feed.
     * An explicit value always wins, which is how a post gets scheduled.
     *
     * ⚠️ $tz is not optional in spirit. The admin form sends a WALL CLOCK
     *    ("2026-08-20T09:00") because that is what <input type="datetime-local">
     *    produces, and config('app.timezone') is UTC — so a bare Carbon::parse()
     *    reads 9am Manila as 9am UTC and the post appears eight hours late. That
     *    is the same drift that used to walk the photo deadline backwards on
     *    every save; see AdminController::update().
     */
    private function publishedAt(array $data, ?NatconAnnouncement $existing, ?string $tz = null): ?Carbon
    {
        if (array_key_exists('published_at', $data)) {
            return $data['published_at']
                ? Carbon::parse($data['published_at'], $tz ?: 'Asia/Manila')->utc()
                : null;
        }

        if ($existing?->published_at) {
            return $existing->published_at;
        }

        $publishing = $data['is_published'] ?? $existing?->is_published ?? false;

        return $publishing ? Carbon::now() : null;
    }

    /**
     * @param  array<string,int>  $reactionCounts  Admin views only — the public
     *                                             feed never carries counts, see
     *                                             AnnouncementReactionController.
     */
    private function presentAnnouncement(NatconAnnouncement $a, ?NatconEvent $event, bool $detailed = false, array $reactionCounts = []): array
    {
        $tz = $event?->timezone ?: 'Asia/Manila';

        $base = [
            'id' => $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'image_url' => $a->image_url,
            'is_pinned' => (bool) $a->is_pinned,
            'published_at' => $a->published_at?->copy()->setTimezone($tz)->toIso8601String(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'is_published' => (bool) $a->is_published,
            // Form-ready wall clock, for the same reason the event deadline is —
            // a datetime-local input fed a UTC string shows the wrong time and
            // then saves it back as if it were local.
            'published_local' => $a->published_at?->copy()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'author' => $a->author?->only(['id', 'name']),
            'created_at' => $a->created_at?->copy()->setTimezone($tz)->toIso8601String(),
            // Engagement, admin side only. Keys absent from the tally are zero;
            // the client fills them in from its own reaction catalogue rather
            // than us shipping five zeroes per row.
            'reaction_counts' => $reactionCounts,
            'reaction_total' => array_sum($reactionCounts),
        ];
    }

    private function validateSponsor(Request $request, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'tier' => "{$rule}|string|in:".implode(',', Sponsor::ALL_TIERS),
            'image_url' => "{$rule}|url|max:2048",
            'name' => 'sometimes|nullable|string|max:191',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
        ]);
    }

    private function presentSponsor(Sponsor $s, bool $detailed = false): array
    {
        $base = [
            'id' => $s->id,
            'tier' => $s->tier,
            'name' => $s->name,
            'image_url' => $s->image_url,
        ];

        return $detailed
            ? $base + ['sort_order' => $s->sort_order]
            : $base;
    }

    private function presentRecap(Recap $r, bool $detailed = false): array
    {
        $base = [
            'id' => $r->id,
            'year' => $r->year,
            'title' => $r->title,
            'thumbnail_url' => $r->thumbnail_url,
            // Normalised server-side so the page never has to guess what an
            // editor pasted — see Recap::embedUrl(). Null for a direct MP4,
            // which the page plays in a <video> instead.
            'embed_url' => $r->embedUrl(),
            'video_url' => $r->video_url,
        ];

        return $detailed
            ? $base + ['is_published' => (bool) $r->is_published, 'sort_order' => $r->sort_order]
            : $base;
    }

    /** Mirrors AdminController::resolveEvent — explicit id, else the live event. */
    private function resolveEvent(Request $request): NatconEvent
    {
        $event = $request->filled('event_id')
            ? NatconEvent::find($request->integer('event_id'))
            : NatconEvent::active();

        abort_unless($event, 404, 'No NATCON event found.');

        return $event;
    }
}
