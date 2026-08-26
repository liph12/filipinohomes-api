<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GalleryAlbum;
use App\Models\GalleryPhoto;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Services\FaceRecognitionService;
use App\Natcon\Services\GalleryService;
use App\Natcon\Services\LandingCachePurger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Photo galleries: albums + photos, in TWO scopes served by one controller.
 *
 *   - Convention scope — natcon_event_id set. The public half is
 *     /natcon/{year}/gallery; the admin half is /admin/natcon/gallery/*,
 *     scoped by ?event_id exactly as the other NATCON landing content.
 *   - Public scope — natcon_event_id NULL. The public half is /albums and
 *     /albums/{slug}; the admin half is /admin/albums/*, whose routes carry
 *     the `scope=public` default so resolveEvent() yields null.
 *
 * Every read and write goes through forEvent($event) so the two scopes can
 * never see each other's rows. The public reads must never 401 or 404 for a
 * missing list — SSR and Googlebot are the only two consumers they exist for,
 * and neither carries a token. The admin half is the editing surface, behind
 * the same admin,editor gate as the rest of the landing content.
 */
class GalleryController extends Controller
{
    public function __construct(
        private GalleryService $gallery,
        private FaceRecognitionService $faces,
    ) {}

    // ── Public: convention gallery ───────────────────────────────────────────

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
            ->forEvent($event)
            ->live()
            ->limit(100)
            ->get();

        return response()->json(['data' => $rows->map(fn (GalleryPhoto $p) => $this->present($p))]);
    }

    // ── Public: /albums ──────────────────────────────────────────────────────

    /**
     * Top-level public albums with a cover and a recursive live-photo count.
     * Empty albums (no live photo anywhere underneath) are left out: an empty
     * folder is admin scaffolding, not publication content.
     */
    public function publicAlbums(): JsonResponse
    {
        $albums = GalleryAlbum::forEvent(null)->orderBy('sort_order')->orderBy('name')->get();
        $stats = $this->albumStats($albums);

        $data = $albums
            ->filter(fn (GalleryAlbum $a) => $a->parent_id === null && ($stats[$a->id]['count'] ?? 0) > 0)
            ->values()
            ->map(fn (GalleryAlbum $a) => $this->presentPublicAlbum($a, $albums, $stats));

        return response()->json(['data' => $data]);
    }

    /**
     * One public album: breadcrumb, its sub-albums (with covers), its own
     * live photos. Only public albums resolve — a convention album's id or
     * name is never reachable here, even by guessing.
     */
    public function publicAlbum(string $slug): JsonResponse
    {
        $album = GalleryAlbum::forEvent(null)->where('slug', $slug)->first();

        if (! $album) {
            return response()->json(['message' => 'Album not found.'], 404);
        }

        $albums = GalleryAlbum::forEvent(null)->orderBy('sort_order')->orderBy('name')->get();
        $stats = $this->albumStats($albums);

        $children = $albums
            ->filter(fn (GalleryAlbum $a) => $a->parent_id === $album->id && ($stats[$a->id]['count'] ?? 0) > 0)
            ->values()
            ->map(fn (GalleryAlbum $a) => $this->presentPublicAlbum($a, $albums, $stats));

        $photos = GalleryPhoto::where('album_id', $album->id)->live()->get();

        return response()->json([
            'data' => $this->presentPublicAlbum($album, $albums, $stats) + [
                'ancestors' => array_map(
                    fn (GalleryAlbum $a) => ['id' => $a->id, 'slug' => $a->slug, 'name' => $a->name],
                    $album->ancestors(),
                ),
                'children' => $children,
                'photos' => $photos->map(fn (GalleryPhoto $p) => $this->present($p)),
            ],
        ]);
    }

    // ── Admin ────────────────────────────────────────────────────────────────

    public function adminGallery(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);

        // Hidden rows included — this is the editing surface. Deleted rows are
        // not: they exist to keep the S3 object findable, not to be relisted.
        $rows = GalleryPhoto::with('album:id,parent_id,name,sort_order')
            ->forEvent($event)
            ->where('status', '!=', GalleryPhoto::STATUS_DELETED)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (GalleryPhoto $p) => $this->present($p, detailed: true))]);
    }

    /** Admin face search: selfies in, this scope's photos (hidden included) out. See probeFaces(). */
    public function faceSearch(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);
        $result = $this->probeFaces($request, $event);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        [$matches, $mode, $total] = $result;

        // whereIn loses the similarity ordering; reassemble in match order.
        // Deleted rows stay out (their vectors are evicted lazily); hidden
        // ones show — this is the editing surface, and finding a hidden photo
        // by face is precisely how it gets un-hidden. forEvent() is a
        // backstop: the collection is per scope already.
        $photos = GalleryPhoto::with('album:id,parent_id,name,sort_order')
            ->forEvent($event)
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

    /**
     * The visitor-facing "find my photos" on /albums: the same probe as the
     * admin search, over the PUBLIC collection only, returning LIVE photos
     * only — a hidden photo must never surface through a selfie, and each
     * match carries its album's slug so the page can link back into it.
     * Behind the guest token + a throttle: every hit is N Rekognition calls.
     *
     * `album` (a public album slug) narrows the results to that album AND its
     * sub-albums. Narrowing happens AFTER Rekognition, on the returned ids —
     * there is one collection for the whole public gallery, and the price is
     * per probe image, not per face compared, so one search costs the same
     * either way. natcon.gallery.max_matches is sized so the filter is
     * applied to the full hit list, not a truncated one.
     */
    public function publicFaceSearch(Request $request): JsonResponse
    {
        $request->validate(['album' => 'sometimes|nullable|string|max:160|regex:/^[a-z0-9-]+$/']);

        $albumIds = null;
        if ($request->filled('album')) {
            $scope = GalleryAlbum::forEvent(null)->where('slug', $request->input('album'))->first();
            if (! $scope) {
                return response()->json(['message' => 'Album not found.'], 404);
            }
            $albumIds = $this->subtreeIds($scope);
        }

        $result = $this->probeFaces($request, null);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        [$matches, $mode, $total] = $result;

        $photos = GalleryPhoto::with('album:id,parent_id,slug,name,sort_order')
            ->forEvent(null)
            ->whereIn('id', array_keys($matches))
            ->when($albumIds !== null, fn ($q) => $q->whereIn('album_id', $albumIds))
            ->where('status', GalleryPhoto::STATUS_ACTIVE)
            ->get()
            ->keyBy('id');

        $data = [];
        foreach ($matches as $photoId => $similarity) {
            $photo = $photos->get($photoId);
            if (! $photo || ! $photo->album) {
                continue;
            }
            $data[] = $this->present($photo) + [
                'similarity' => round($similarity, 1),
                'album' => [
                    'id' => $photo->album->id,
                    'slug' => $photo->album->slug,
                    'name' => $photo->album->name,
                    'path' => $photo->album->path(),
                ],
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'matched' => count($data),
                'threshold' => (float) config('natcon.gallery.match_threshold', 90),
                'mode' => $mode,
                'faces_searched' => $total,
                'album' => $request->input('album'),
            ],
        ]);
    }

    /**
     * The album's id plus every descendant's, from one query over the scope's
     * albums — public albums are tens of rows, and walking parent_id in PHP
     * beats a recursive CTE for that size.
     *
     * @return array<int, int>
     */
    private function subtreeIds(GalleryAlbum $root): array
    {
        $byParent = GalleryAlbum::forEvent(null)
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');

        $ids = [];
        $queue = [$root->id];
        while ($queue !== [] && count($ids) < 10_000) {
            $id = array_shift($queue);
            $ids[] = $id;
            foreach ($byParent->get($id, collect()) as $child) {
                $queue[] = $child->id;
            }
        }

        return $ids;
    }

    /**
     * Selfies in, photo-id => similarity out. One or several faces:
     *
     *   selfies[] (1-5 files) + mode:
     *     all — photos where EVERY searched face appears together (default).
     *     any — photos containing ANY of the faces.
     *
     * Rekognition matches one face per probe (the largest), so N faces cost N
     * SearchFacesByImage calls; combineMatches folds them. Each probe is
     * re-encoded server-side (1500px q85) before Rekognition — the Image.Bytes
     * API caps at 5MB and a phone original is routinely bigger. Selfies are
     * never stored. Returns a ready 422 for a user-fixable problem.
     *
     * @return JsonResponse|array{0: array<int, float>, 1: string, 2: int}
     */
    private function probeFaces(Request $request, ?NatconEvent $event): JsonResponse|array
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

        return [$this->faces->combineMatches($perFace, $mode), $mode, $total];
    }

    public function storeGalleryPhoto(Request $request): JsonResponse
    {
        $data = $request->validate([
            'photo' => 'required|file|mimes:jpeg,jpg,png,webp|max:'.(int) config('natcon.gallery.max_upload_kb', 15360),
            'caption' => 'sometimes|nullable|string|max:255',
            // Required: the root holds albums only — every photo lives inside
            // one (per photographer / company).
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

        $this->purge($event, $album);

        return response()->json(['data' => $this->present($row, detailed: true)], 201);
    }

    public function updateGalleryPhoto(Request $request, GalleryPhoto $photo): JsonResponse
    {
        $this->guardScope($request, $photo->event);

        $data = $request->validate([
            'caption' => 'sometimes|nullable|string|max:255',
            // 'deleted' only via destroy — a PATCH must not be able to delete.
            'status' => 'sometimes|string|in:active,hidden',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
            // Not nullable: the root holds albums only, so a move must name a
            // destination album — and one of the SAME scope, or a move could
            // smuggle a photo across years or into the public gallery.
            'album_id' => 'sometimes|integer',
        ]);

        if (array_key_exists('album_id', $data)) {
            $data['album_id'] = $this->resolveAlbum($photo->event, $data['album_id'])?->id;
        }

        $photo->auditSource = $this->auditSource($photo->event);
        $photo->fill($data)->save();

        $this->purge($photo->event, $photo->album);

        return response()->json(['data' => $this->present($photo->fresh(), detailed: true)]);
    }

    public function destroyGalleryPhoto(Request $request, GalleryPhoto $photo): JsonResponse
    {
        $this->guardScope($request, $photo->event);

        // Before the write: see destroyAnnouncement.
        $event = $photo->event;
        $album = $photo->album;

        // A status flip, not delete() — the row is the only pointer to the S3
        // object, and removing it would strand the file in the bucket for ever.
        $photo->auditSource = $this->auditSource($event);
        $photo->forceFill(['status' => GalleryPhoto::STATUS_DELETED])->save();

        $this->forgetFaces($photo);

        $this->purge($event, $album);

        return response()->json(['message' => 'Photo removed.']);
    }

    // ── Albums (folders inside one scope's gallery) ──────────────────────────

    /**
     * Folders of one scope, with how many visible photos each holds. The
     * frontend builds the tree from parent_id.
     */
    public function albums(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);

        // Alphabetical — albums always list A→Z, in the admin and on the
        // public page alike.
        $rows = GalleryAlbum::forEvent($event)
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
            // scope, so nesting can never cross years or scopes.
            'parent_id' => 'sometimes|nullable|integer',
        ]);

        $event = $this->resolveEvent($request);
        $parent = $this->resolveAlbum($event, $data['parent_id'] ?? null);
        $name = trim($data['name']);

        // Friendly 422 instead of the unique index's 500 — the index stays as
        // the backstop against a concurrent double-submit. For public albums
        // (NULL event) the index does not fire, so this IS the guard.
        if (GalleryAlbum::forEvent($event)->where('name', $name)->exists()) {
            return response()->json(['message' => "An album called \"{$name}\" already exists".($event ? ' for this convention.' : '.')], 422);
        }

        $album = new GalleryAlbum([
            'natcon_event_id' => $event?->id,
            'parent_id' => $parent?->id,
            // Public albums are URL-addressable (/albums/{slug}); convention
            // albums are only ever reached through their year's page.
            'slug' => $event ? null : GalleryAlbum::uniqueSlug($name),
            'name' => $name,
            'created_by' => $request->user()?->id,
        ]);
        $album->auditSource = $this->auditSource($event);
        $album->save();

        $this->purge($event, $album);

        return response()->json(['data' => $this->presentAlbum($album, photoCount: 0)], 201);
    }

    public function updateAlbum(Request $request, GalleryAlbum $album): JsonResponse
    {
        $this->guardScope($request, $album->event);

        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
        ]);

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            $duplicate = GalleryAlbum::forEvent($album->event)
                ->where('name', $data['name'])
                ->where('id', '!=', $album->id)
                ->exists();
            if ($duplicate) {
                return response()->json(['message' => "An album called \"{$data['name']}\" already exists".($album->event ? ' for this convention.' : '.')], 422);
            }
        }

        // The slug is NOT regenerated on rename — the URL is what shared links
        // and search engines hold. A public album created before slugs
        // existed gets one lazily here.
        if ($album->isPublic() && ! $album->slug) {
            $data['slug'] = GalleryAlbum::uniqueSlug($data['name'] ?? $album->name, $album->id);
        }

        $album->auditSource = $this->auditSource($album->event);
        $album->fill($data)->save();

        $this->purge($album->event, $album);

        return response()->json(['data' => $this->presentAlbum($album->fresh())]);
    }

    public function destroyAlbum(Request $request, GalleryAlbum $album): JsonResponse
    {
        $this->guardScope($request, $album->event);

        $event = $album->event;

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
            ->each(function (GalleryPhoto $photo) use ($event) {
                $photo->auditSource = $this->auditSource($event);
                $photo->forceFill(['status' => GalleryPhoto::STATUS_DELETED])->save();
                $this->forgetFaces($photo);
            });

        // A real delete, unlike photos: an album owns no S3 object.
        $album->auditSource = $this->auditSource($event);
        $album->delete();

        $this->purge($event, $album);

        return response()->json(['message' => 'Album removed.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Drop the public page's cached copy so an edit is visible immediately
     * instead of whenever the ISR window happens to expire — the convention
     * year's page, or the /albums pages for the public scope.
     *
     * Deliberately AFTER the save and never guarded by its result: the content
     * is already committed, and a frontend that is redeploying or a secret that
     * is not set must not turn a successful edit into an error. See
     * LandingCachePurger.
     */
    private function purge(?NatconEvent $event, ?GalleryAlbum $album = null): void
    {
        $purger = app(LandingCachePurger::class);

        if ($event) {
            $purger->purgeYear($event->year);

            return;
        }

        $purger->purgeAlbums($album?->slug);
    }

    private function auditSource(?NatconEvent $event): string
    {
        return $event ? 'admin_natcon_gallery' : 'admin_gallery';
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
            Log::warning('gallery face eviction failed', [
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
            'slug' => $a->slug,
            'name' => $a->name,
            'path' => $a->path(),
            'sort_order' => $a->sort_order,
            'photo_count' => $photoCount ?? (int) ($a->photos_count ?? 0),
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    /**
     * Per-album LIVE photo count and cover, rolled up through the tree — a
     * parent counts (and may borrow a cover from) everything beneath it. One
     * grouped query plus one cover query for the whole scope, so the public
     * list never goes N+1.
     *
     * @param  Collection<int, GalleryAlbum>  $albums
     * @return array<int, array{count: int, cover: ?GalleryPhoto}>
     */
    private function albumStats(Collection $albums): array
    {
        $ids = $albums->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $own = GalleryPhoto::whereIn('album_id', $ids)
            ->where('status', GalleryPhoto::STATUS_ACTIVE)
            ->selectRaw('album_id, count(*) as n, min(id) as any_id')
            ->groupBy('album_id')
            ->get()
            ->keyBy('album_id');

        // The album's own first photo in public order (sort_order, then id),
        // so the cover is the one the admin put first.
        $covers = GalleryPhoto::whereIn('album_id', $ids)
            ->where('status', GalleryPhoto::STATUS_ACTIVE)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'album_id', 'image_url', 'thumb_url', 'caption', 'sort_order'])
            ->unique('album_id')
            ->keyBy('album_id');

        $stats = [];
        foreach ($albums as $a) {
            $stats[$a->id] = [
                'count' => (int) ($own[$a->id]->n ?? 0),
                'cover' => $covers->get($a->id),
            ];
        }

        // Roll each album's total into every ancestor. Children before parents
        // is not guaranteed by the list order, so walk up per album instead.
        $byId = $albums->keyBy('id');
        foreach ($albums as $a) {
            $ownCount = (int) ($own[$a->id]->n ?? 0);
            $ownCover = $covers->get($a->id);
            $node = $a;
            for ($i = 0; $i < 20 && $node->parent_id && $byId->has($node->parent_id); $i++) {
                $node = $byId[$node->parent_id];
                $stats[$node->id]['count'] += $ownCount;
                if (! $stats[$node->id]['cover'] && $ownCover) {
                    $stats[$node->id]['cover'] = $ownCover;
                }
            }
        }

        return $stats;
    }

    /**
     * @param  Collection<int, GalleryAlbum>  $albums
     * @param  array<int, array{count: int, cover: ?GalleryPhoto}>  $stats
     */
    private function presentPublicAlbum(GalleryAlbum $a, Collection $albums, array $stats): array
    {
        $cover = $stats[$a->id]['cover'] ?? null;

        return [
            'id' => $a->id,
            'slug' => $a->slug,
            'name' => $a->name,
            'parent_id' => $a->parent_id,
            'photo_count' => (int) ($stats[$a->id]['count'] ?? 0),
            'album_count' => $albums->where('parent_id', $a->id)->count(),
            'cover' => $cover ? [
                'image_url' => $cover->image_url,
                'thumb_url' => $cover->thumb_url,
                'caption' => $cover->caption,
            ] : null,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    /**
     * NULL stays NULL (the scope root); an id must name a folder of the given
     * scope or the request is a 404 — an album id from another year, or from
     * the public gallery, must never attach.
     */
    private function resolveAlbum(?NatconEvent $event, mixed $albumId): ?GalleryAlbum
    {
        if ($albumId === null || $albumId === '') {
            return null;
        }

        $album = GalleryAlbum::forEvent($event)->where('id', (int) $albumId)->first();

        abort_unless($album, 404, $event ? 'Album not found for this convention.' : 'Album not found.');

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
            'width' => $p->width,
            'height' => $p->height,
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
            'byte_size' => $p->byte_size,
            // Indexing state for the admin grid's face badge: NULL
            // faces_indexed_at = still pending (the sweep will retry).
            'face_count' => $p->face_count,
            'faces_indexed_at' => $p->faces_indexed_at?->toIso8601String(),
            'index_error' => $p->index_error,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /** True when the request came in through an /admin/albums/* route. */
    private function isPublicScope(Request $request): bool
    {
        return $request->route()?->parameter('scope') === 'public';
    }

    /**
     * Which scope this admin request edits. /admin/albums/* routes carry the
     * `scope=public` default → null (the public gallery). Everything else
     * mirrors LandingController::resolveEvent — explicit id, else the live
     * convention.
     */
    private function resolveEvent(Request $request): ?NatconEvent
    {
        if ($this->isPublicScope($request)) {
            return null;
        }

        $event = $request->filled('event_id')
            ? NatconEvent::find($request->integer('event_id'))
            : NatconEvent::active();

        abort_unless($event, 404, 'No NATCON event found.');

        return $event;
    }

    /**
     * A row reached by id must belong to the scope of the route it came in
     * on: /admin/albums/* edits public rows only, /admin/natcon/gallery/*
     * convention rows only. Otherwise a public-album editor could reach into
     * a convention's gallery by guessing an id.
     */
    private function guardScope(Request $request, ?NatconEvent $rowEvent): void
    {
        $public = $this->isPublicScope($request);

        abort_if($public && $rowEvent !== null, 404, 'Album not found.');
        abort_if(! $public && $rowEvent === null, 404, 'Not part of this convention.');
    }
}
