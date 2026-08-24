<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\GalleryAlbum;
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
    ) {}

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

        $rows = GalleryPhoto::with('album:id,parent_id,name,sort_order')
            ->where('natcon_event_id', $event->id)
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
        $rows = GalleryPhoto::with('album:id,parent_id,name,sort_order')
            ->where('natcon_event_id', $event->id)
            ->where('status', '!=', GalleryPhoto::STATUS_DELETED)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (GalleryPhoto $p) => $this->present($p, detailed: true))]);
    }

    public function storeGalleryPhoto(Request $request): JsonResponse
    {
        $data = $request->validate([
            'photo' => 'required|file|mimes:jpeg,jpg,png,webp|max:'.(int) config('natcon.gallery.max_upload_kb', 15360),
            'caption' => 'sometimes|nullable|string|max:255',
            // Required: the convention root holds albums only — every photo
            // lives inside one (per photographer / company).
            'album_id' => 'required|integer',
        ], [
            'photo.mimes' => 'Please upload a JPG, PNG or WEBP image.',
            'album_id.required' => 'Choose an album to upload into.',
            'photo.max' => 'That image is too large. Please keep it under 15MB.',
        ]);

        $event = $this->resolveEvent($request);
        $album = $this->resolveAlbum($event, $data['album_id']);

        try {
            $row = $this->gallery->store(
                $event,
                $request->file('photo'),
                $request->user()?->id,
                $data['caption'] ?? null,
                $album,
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
            // 'deleted' only via destroy — a PATCH must not be able to delete.
            'status' => 'sometimes|string|in:active,hidden',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
            // Not nullable: the root holds albums only, so a move must name a
            // destination album — and one of the SAME convention, or a move
            // could smuggle a photo across years.
            'album_id' => 'sometimes|integer',
        ]);

        if (array_key_exists('album_id', $data)) {
            $data['album_id'] = $this->resolveAlbum($photo->event, $data['album_id'])?->id;
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

    // ── Albums (secondary folders inside one convention's gallery) ──────────

    /**
     * Folders for one convention, with how many visible photos each holds.
     * The convention itself is the primary album; these are the one level of
     * folders under it (per photographer / company).
     */
    public function albums(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);

        // Alphabetical — albums always list A→Z, in the admin and on the
        // public page alike. The frontend builds the tree from parent_id.
        $rows = GalleryAlbum::where('natcon_event_id', $event->id)
            ->withCount(['photos' => fn ($q) => $q->where('status', '!=', GalleryPhoto::STATUS_DELETED)])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rows->map(fn (GalleryAlbum $a) => $this->presentAlbum($a))]);
    }

    public function storeAlbum(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            // NULL/absent = top level; an id must be an album of the SAME
            // convention, so nesting can never cross years.
            'parent_id' => 'sometimes|nullable|integer',
        ]);

        $event = $this->resolveEvent($request);
        $parent = $this->resolveAlbum($event, $data['parent_id'] ?? null);
        $name = trim($data['name']);

        // Friendly 422 instead of the unique index's 500 — the index stays as
        // the backstop against a concurrent double-submit.
        if (GalleryAlbum::where('natcon_event_id', $event->id)->where('name', $name)->exists()) {
            return response()->json(['message' => "An album called \"{$name}\" already exists for this convention."], 422);
        }

        $album = new GalleryAlbum([
            'natcon_event_id' => $event->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'created_by' => $request->user()?->id,
        ]);
        $album->auditSource = 'admin_natcon_gallery';
        $album->save();

        $this->purge($event->year);

        return response()->json(['data' => $this->presentAlbum($album, photoCount: 0)], 201);
    }

    public function updateAlbum(Request $request, GalleryAlbum $album): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
        ]);

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            $duplicate = GalleryAlbum::where('natcon_event_id', $album->natcon_event_id)
                ->where('name', $data['name'])
                ->where('id', '!=', $album->id)
                ->exists();
            if ($duplicate) {
                return response()->json(['message' => "An album called \"{$data['name']}\" already exists for this convention."], 422);
            }
        }

        $album->auditSource = 'admin_natcon_gallery';
        $album->fill($data)->save();

        $this->purge($album->event?->year);

        return response()->json(['data' => $this->presentAlbum($album->fresh())]);
    }

    public function destroyAlbum(GalleryAlbum $album): JsonResponse
    {
        $year = $album->event?->year;

        // The root no longer shows photos, so the FK's nullOnDelete fallback
        // would strand a photo where no view lists it. Refuse instead: move or
        // remove the contents, then delete the empty folder.
        $remaining = $album->photos()->where('status', '!=', GalleryPhoto::STATUS_DELETED)->count();
        if ($remaining > 0) {
            return response()->json([
                'message' => "This album still holds {$remaining} photo".($remaining === 1 ? '' : 's').'. Move or remove them first.',
            ], 422);
        }

        $subAlbums = $album->children()->count();
        if ($subAlbums > 0) {
            return response()->json([
                'message' => "This album still holds {$subAlbums} sub-album".($subAlbums === 1 ? '' : 's').'. Delete or empty them first.',
            ], 422);
        }

        // A real delete, unlike photos: an album owns no S3 object.
        $album->auditSource = 'admin_natcon_gallery';
        $album->delete();

        $this->purge($year);

        return response()->json(['message' => 'Album removed.']);
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

    private function presentAlbum(GalleryAlbum $a, ?int $photoCount = null): array
    {
        return [
            'id' => $a->id,
            'parent_id' => $a->parent_id,
            'name' => $a->name,
            'path' => $a->path(),
            'sort_order' => $a->sort_order,
            'photo_count' => $photoCount ?? (int) ($a->photos_count ?? 0),
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    /**
     * NULL stays NULL (the event root); an id must name a folder of the given
     * convention or the request is a 404 — an album id from another year must
     * never attach.
     */
    private function resolveAlbum(?NatconEvent $event, mixed $albumId): ?GalleryAlbum
    {
        if ($albumId === null || $albumId === '') {
            return null;
        }

        $album = GalleryAlbum::where('id', (int) $albumId)
            ->where('natcon_event_id', $event?->id)
            ->first();

        abort_unless($album, 404, 'Album not found for this convention.');

        return $album;
    }

    private function present(GalleryPhoto $p, bool $detailed = false): array
    {
        $base = [
            'id' => $p->id,
            'image_url' => $p->image_url,
            'thumb_url' => $p->thumb_url,
            'caption' => $p->caption,
            'sort_order' => $p->sort_order,
            'album' => $p->album ? [
                'id' => $p->album->id,
                'parent_id' => $p->album->parent_id,
                'name' => $p->album->name,
                'path' => $p->album->path(),
                'sort_order' => $p->album->sort_order,
            ] : null,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'album_id' => $p->album_id,
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
