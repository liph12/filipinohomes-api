<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\GalleryAlbum;
use App\Natcon\Models\GalleryPhoto;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Services\FaceRecognitionService;
use App\Natcon\Services\GalleryService;
use App\Natcon\Services\LandingCachePurger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
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
        private FaceRecognitionService $faces,
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

    /**
     * Selfies in, GALLERY photos out. One or several faces:
     *
     *   selfies[] (1-5 files) + mode:
     *     all — photos where EVERY searched face appears together (default).
     *     any — photos containing ANY of the faces.
     *
     * Rekognition matches one face per probe (the largest), so N faces cost N
     * SearchFacesByImage calls; combineMatches folds them. Each probe is
     * re-encoded server-side (1500px q85) before Rekognition — the Image.Bytes
     * API caps at 5MB and a phone original is routinely bigger. Selfies are
     * never stored.
     */
    public function faceSearch(Request $request): JsonResponse
    {
        $files = $request->file('selfies') ?? [];

        validator(
            ['selfies' => $files, 'mode' => $request->input('mode')],
            [
                'selfies' => 'required|array|min:1|max:5',
                'selfies.*' => 'required|image|mimes:jpeg,jpg,png,webp|max:15360',
                'mode' => 'sometimes|nullable|in:all,any',
            ],
        )->validate();

        $event = $this->resolveEvent($request);
        $mode = (string) $request->input('mode', 'all');
        $total = count($files);

        $manager = new ImageManager(new Driver);

        /** @var array<int, array<int, float>> $perFace one photoId=>similarity map per probe */
        $perFace = [];
        foreach (array_values($files) as $i => $file) {
            $label = $total > 1 ? 'photo '.($i + 1).' of '.$total : 'that photo';

            $info = @getimagesize($file->getRealPath());
            if ($info === false || ($info[0] * $info[1]) > 40_000_000) {
                return response()->json(['message' => ucfirst($label).' does not look like a usable photo.'], 422);
            }

            $probe = (string) $manager->read($file->getRealPath())
                ->scaleDown(width: 1500, height: 1500)
                ->toJpeg(85);

            try {
                $perFace[] = $this->faces->searchByImage($event, $probe);
            } catch (RuntimeException $e) {
                return response()->json([
                    'message' => $total > 1
                        ? 'No face could be detected in '.$label.'. Please use a clear, well-lit photo of that person.'
                        : $e->getMessage(),
                ], 422);
            }
        }

        $matches = $this->faces->combineMatches($perFace, $mode);

        // whereIn loses the similarity ordering; reassemble in match order.
        // Deleted rows stay out (their vectors are evicted lazily); hidden
        // ones show — this is the editing surface, and finding a hidden photo
        // by face is precisely how it gets un-hidden.
        $photos = GalleryPhoto::with('album:id,parent_id,name,sort_order')
            ->whereIn('id', array_keys($matches))
            ->where('status', '!=', GalleryPhoto::STATUS_DELETED)
            ->get()
            ->keyBy('id');

        $data = [];
        foreach ($matches as $photoId => $similarity) {
            $photo = $photos->get($photoId);
            if (! $photo) {
                continue;
            }
            $data[] = $this->present($photo, detailed: true) + ['similarity' => round($similarity, 1)];
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'matched' => count($data),
                'threshold' => (float) config('natcon.gallery.match_threshold', 90),
                'mode' => $mode,
                'faces_searched' => $total,
            ],
        ]);
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

        $this->forgetFaces($photo);

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

        // Sub-albums still block the delete: cascading a whole subtree from
        // one button is too much blast radius, and the admin can delete the
        // leaves first. Photos alone do NOT block any more — they are
        // soft-removed with the album (same status flip as a single photo
        // delete, so the S3 objects stay findable and support can restore).
        $subAlbums = $album->children()->count();
        if ($subAlbums > 0) {
            return response()->json([
                'message' => "This album still holds {$subAlbums} sub-album".($subAlbums === 1 ? '' : 's').'. Delete or empty them first.',
            ], 422);
        }

        // Per-row saves, not a mass update, so each photo keeps its audit
        // trail. The FK's nullOnDelete would otherwise strand them at root,
        // where the admin view only shows them under a warning.
        $album->photos()
            ->where('status', '!=', GalleryPhoto::STATUS_DELETED)
            ->get()
            ->each(function (GalleryPhoto $photo) {
                $photo->auditSource = 'admin_natcon_gallery';
                $photo->forceFill(['status' => GalleryPhoto::STATUS_DELETED])->save();
                $this->forgetFaces($photo);
            });

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

    /**
     * Evict a removed photo's face vectors so it can never match again.
     * Best-effort AFTER the status flip: a Rekognition blip must not turn a
     * successful delete into an error — the faceSearch read filters deleted
     * rows anyway, so a leftover vector is invisible until this is retried
     * manually or the collection is cleaned.
     */
    private function forgetFaces(GalleryPhoto $photo): void
    {
        try {
            $this->faces->forgetPhoto($photo);
        } catch (\Throwable $e) {
            Log::warning('natcon gallery face eviction failed', [
                'photo_id' => $photo->id,
                'error' => $e->getMessage(),
            ]);
        }
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
            // Indexing state for the admin grid's face badge: NULL
            // faces_indexed_at = still pending (the sweep will retry).
            'face_count' => $p->face_count,
            'faces_indexed_at' => $p->faces_indexed_at?->toIso8601String(),
            'index_error' => $p->index_error,
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
