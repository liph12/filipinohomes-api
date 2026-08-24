<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\GalleryPhoto;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Services\GalleryService;
use App\Natcon\Services\LandingCachePurger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The landing page's photo gallery — event photos, not awardee headshots.
 *
 * The public half serves the same INDEXABLE page as LandingController: every
 * field it returns is publication copy somebody chose to show, and the read
 * must never 401 or 404 — SSR and Googlebot are the only two consumers it
 * exists for, and neither carries a token. The admin half is the editing
 * surface, behind the same admin,editor gate as the rest of the landing
 * content.
 */
class GalleryController extends Controller
{
    public function __construct(
        private GalleryService $gallery,
    ) {
    }

    // ── Public ───────────────────────────────────────────────────────────────

    /**
     * Gallery photos for one convention year.
     *
     * Keyed by year for the same reason announcements are — the URL is
     * /natcon/2026 and the page should not resolve an id first. An unknown
     * year is an empty list, not a 404.
     */
    public function gallery(int $year): JsonResponse
    {
        $event = NatconEvent::forYear($year);

        if (! $event) {
            return response()->json(['data' => []]);
        }

        $rows = GalleryPhoto::where('natcon_event_id', $event->id)
            ->live()
            ->limit(100)
            ->get();

        return response()->json(['data' => $rows->map(fn (GalleryPhoto $p) => $this->present($p))]);
    }

    // ── Admin ────────────────────────────────────────────────────────────────

    public function adminGallery(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);

        // Hidden rows included — this is the editing surface. Deleted rows are
        // not: they exist to keep the S3 object findable, not to be relisted.
        $rows = GalleryPhoto::where('natcon_event_id', $event->id)
            ->where('status', '!=', GalleryPhoto::STATUS_DELETED)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (GalleryPhoto $p) => $this->present($p, detailed: true))]);
    }

    public function storeGalleryPhoto(Request $request): JsonResponse
    {
        $data = $request->validate([
            'photo' => 'required|file|mimes:jpeg,jpg,png,webp|max:' . (int) config('natcon.gallery.max_upload_kb', 15360),
            'caption' => 'sometimes|nullable|string|max:255',
            // Grouping label — the public gallery renders one section per
            // album. Null/blank = the general section.
            'album' => 'sometimes|nullable|string|max:120',
        ], [
            'photo.mimes' => 'Please upload a JPG, PNG or WEBP image.',
            'photo.max'   => 'That image is too large. Please keep it under 15MB.',
        ]);

        $event = $this->resolveEvent($request);

        $album = trim((string) ($data['album'] ?? ''));

        try {
            $row = $this->gallery->store(
                $event,
                $request->file('photo'),
                $request->user()?->id,
                $data['caption'] ?? null,
                $album !== '' ? $album : null,
            );
        } catch (RuntimeException $e) {
            // The megapixel / decode gate. A user-fixable problem, so 422 with
            // the reason rather than a 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->purge($event->year);

        return response()->json(['data' => $this->present($row, detailed: true)], 201);
    }

    public function updateGalleryPhoto(Request $request, GalleryPhoto $photo): JsonResponse
    {
        $data = $request->validate([
            'caption' => 'sometimes|nullable|string|max:255',
            // Photos can move between albums (or out of one) after upload.
            'album' => 'sometimes|nullable|string|max:120',
            // 'deleted' only via destroy — a PATCH must not be able to delete.
            'status' => 'sometimes|string|in:active,hidden',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
        ]);

        if (array_key_exists('album', $data)) {
            $album = trim((string) $data['album']);
            $data['album'] = $album !== '' ? $album : null;
        }

        $photo->auditSource = 'admin_natcon_gallery';
        $photo->fill($data)->save();

        $this->purge($photo->event?->year);

        return response()->json(['data' => $this->present($photo->fresh(), detailed: true)]);
    }

    public function destroyGalleryPhoto(GalleryPhoto $photo): JsonResponse
    {
        // Before the write: see destroyAnnouncement.
        $year = $photo->event?->year;

        // A status flip, not delete() — the row is the only pointer to the S3
        // object, and removing it would strand the file in the bucket for ever.
        $photo->auditSource = 'admin_natcon_gallery';
        $photo->forceFill(['status' => GalleryPhoto::STATUS_DELETED])->save();

        $this->purge($year);

        return response()->json(['message' => 'Photo removed.']);
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

    private function present(GalleryPhoto $p, bool $detailed = false): array
    {
        $base = [
            'id' => $p->id,
            'image_url' => $p->image_url,
            'thumb_url' => $p->thumb_url,
            'caption' => $p->caption,
            'album' => $p->album,
            'sort_order' => $p->sort_order,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'status' => $p->status,
            'width' => $p->width,
            'height' => $p->height,
            'byte_size' => $p->byte_size,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /** Mirrors LandingController::resolveEvent — explicit id, else the live event. */
    private function resolveEvent(Request $request): NatconEvent
    {
        $event = $request->filled('event_id')
            ? NatconEvent::find($request->integer('event_id'))
            : NatconEvent::active();

        abort_unless($event, 404, 'No NATCON event found.');

        return $event;
    }
}
