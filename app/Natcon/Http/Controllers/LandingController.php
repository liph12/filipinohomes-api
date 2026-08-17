<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\NatconAnnouncement;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\Recap;
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

        return response()->json(['data' => $rows->map(fn ($a) => $this->presentAnnouncement($a, $event, detailed: true))]);
    }

    public function storeAnnouncement(Request $request): JsonResponse
    {
        $data  = $this->validateAnnouncement($request);
        $event = $this->resolveEvent($request);

        $row = new NatconAnnouncement($data + ['natcon_event_id' => $event->id]);
        $row->created_by      = $request->user()?->id;
        $row->published_at    = $this->publishedAt($data, null, $event->timezone);
        $row->auditSource     = 'admin_natcon_announcement';
        $row->save();

        return response()->json(['data' => $this->presentAnnouncement($row, $event, detailed: true)], 201);
    }

    public function updateAnnouncement(Request $request, NatconAnnouncement $announcement): JsonResponse
    {
        $data = $this->validateAnnouncement($request, partial: true);

        $announcement->fill($data);
        $announcement->published_at = $this->publishedAt($data, $announcement, $announcement->event?->timezone);
        $announcement->auditSource  = 'admin_natcon_announcement';
        $announcement->save();

        return response()->json([
            'data' => $this->presentAnnouncement($announcement->fresh(), $announcement->event, detailed: true),
        ]);
    }

    public function destroyAnnouncement(NatconAnnouncement $announcement): JsonResponse
    {
        // Soft delete: an announcement pulled in a hurry is often wanted back,
        // and the audit trail is worth more than the row.
        $announcement->auditSource = 'admin_natcon_announcement';
        $announcement->delete();

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
            'year'          => 'required|integer|min:2000|max:2100|unique:natcon_recaps,year',
            'title'         => 'required|string|max:191',
            'video_url'     => 'required|url|max:2048',
            'thumbnail_url' => 'nullable|url|max:2048',
            'is_published'  => 'sometimes|boolean',
            'sort_order'    => 'sometimes|integer|min:0|max:9999',
        ]);

        $row = new Recap($data);
        $row->created_by  = $request->user()?->id;
        $row->auditSource = 'admin_natcon_recap';
        $row->save();

        return response()->json(['data' => $this->presentRecap($row, detailed: true)], 201);
    }

    public function updateRecap(Request $request, Recap $recap): JsonResponse
    {
        $data = $request->validate([
            // Ignores itself, so saving a row without changing the year works.
            'year'          => 'sometimes|integer|min:2000|max:2100|unique:natcon_recaps,year,' . $recap->id,
            'title'         => 'sometimes|string|max:191',
            'video_url'     => 'sometimes|url|max:2048',
            'thumbnail_url' => 'sometimes|nullable|url|max:2048',
            'is_published'  => 'sometimes|boolean',
            'sort_order'    => 'sometimes|integer|min:0|max:9999',
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

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function validateAnnouncement(Request $request, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title'        => "{$rule}|string|max:191",
            'body'         => "{$rule}|string|max:5000",
            'image_url'    => 'sometimes|nullable|url|max:2048',
            'is_published' => 'sometimes|boolean',
            'is_pinned'    => 'sometimes|boolean',
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

    private function presentAnnouncement(NatconAnnouncement $a, ?NatconEvent $event, bool $detailed = false): array
    {
        $tz = $event?->timezone ?: 'Asia/Manila';

        $base = [
            'id'           => $a->id,
            'title'        => $a->title,
            'body'         => $a->body,
            'image_url'    => $a->image_url,
            'is_pinned'    => (bool) $a->is_pinned,
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
            'author'       => $a->author?->only(['id', 'name']),
            'created_at'   => $a->created_at?->copy()->setTimezone($tz)->toIso8601String(),
        ];
    }

    private function presentRecap(Recap $r, bool $detailed = false): array
    {
        $base = [
            'id'            => $r->id,
            'year'          => $r->year,
            'title'         => $r->title,
            'thumbnail_url' => $r->thumbnail_url,
            // Normalised server-side so the page never has to guess what an
            // editor pasted — see Recap::youtubeEmbedUrl().
            'embed_url'     => $r->youtubeEmbedUrl(),
            'video_url'     => $r->video_url,
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
