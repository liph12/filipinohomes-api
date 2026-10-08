<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\GalleryAlbum;
use App\Models\GalleryAlbumFrame;
use App\Models\GalleryPhoto;
use App\Natcon\Models\GalleryUploadInvite;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Services\FaceRecognitionService;
use App\Natcon\Services\GalleryInviteService;
use App\Natcon\Services\GalleryService;
use App\Natcon\Services\LandingCachePurger;
use App\Natcon\Services\PhotoService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        private GalleryInviteService $invites,
        private PhotoService $photos,
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

        $rows = GalleryPhoto::with('album:id,parent_id,slug,name,sort_order')
            ->forEvent($event)
            ->live()
            ->limit(100)
            ->get();

        return response()->json(['data' => $rows->map(fn (GalleryPhoto $p) => $this->present($p))]);
    }

    /**
     * Public: a few of the year's live photos by id (`ids=1,2,3`, up to 50) — how the awardee frame
     * studio gets its picked photos back after a page reload, from the ids it kept in the URL.
     */
    public function galleryPhotosByIds(Request $request, int $year): JsonResponse
    {
        $event = NatconEvent::forYear($year);
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $request->query('ids', ''))), fn ($n) => $n > 0))), 0, 50);

        if (! $event || $ids === []) {
            return response()->json(['data' => []]);
        }

        $rows = GalleryPhoto::with('album:id,parent_id,slug,name,sort_order')
            ->forEvent($event)
            ->live()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (GalleryPhoto $p) => array_search($p->id, $ids, true))
            ->values();

        return response()->json(['data' => $rows->map(fn (GalleryPhoto $p) => $this->present($p))]);
    }

    /**
     * Which conventions have a public gallery — the year switcher's whole
     * source of truth, and the answer to "can I still see the 2025 photos?"
     *
     * Every year lives at its own indexable /natcon/{year}/gallery now, the
     * same way every year has its own /natcon/{year}. Before this, the public
     * gallery served the ACTIVE convention and nothing else: a finished year's
     * albums stayed online and permanently reachable by direct link, but
     * nothing on the site pointed at them, so the only people who could find
     * 2025's photos were the ones who already had the URL.
     *
     * ⚠️ A year qualifies on LIVE PHOTOS, not on albums. "Day 1" and "Day 2"
     *    exist in the admin before a single photo lands — natconAlbums() serves
     *    empty albums deliberately — so listing by album would offer a tab that
     *    opens on an empty room.
     *
     * The ACTIVE year is always listed, even with nothing in it: the current
     * convention has to be a tab from the day it opens, and its empty state
     * ("The collection is being curated") is the right thing for a visitor to
     * see rather than the year simply not existing.
     *
     * Token-less like every other public gallery read — SSR is the consumer.
     */
    public function natconGalleryYears(): JsonResponse
    {
        $active = NatconEvent::active();

        // One grouped count over the photos, rather than a query per event:
        // this is read on every gallery page render.
        $photoCounts = GalleryPhoto::query()
            ->whereNotNull('natcon_event_id')
            ->where('status', GalleryPhoto::STATUS_ACTIVE)
            ->selectRaw('natcon_event_id, COUNT(*) AS n')
            ->groupBy('natcon_event_id')
            ->pluck('n', 'natcon_event_id');

        $albumCounts = GalleryAlbum::query()
            ->whereNotNull('natcon_event_id')
            ->selectRaw('natcon_event_id, COUNT(*) AS n')
            ->groupBy('natcon_event_id')
            ->pluck('n', 'natcon_event_id');

        $years = NatconEvent::orderByDesc('year')->get()
            ->filter(fn (NatconEvent $e) => ($photoCounts[$e->id] ?? 0) > 0 || $e->id === $active?->id)
            ->map(fn (NatconEvent $e) => [
                'year' => (int) $e->year,
                'short_name' => $e->short_name ?: "NATCON {$e->year}",
                'photo_count' => (int) ($photoCounts[$e->id] ?? 0),
                'album_count' => (int) ($albumCounts[$e->id] ?? 0),
                'is_active' => $e->id === $active?->id,
            ])
            ->values();

        return response()->json(['data' => $years]);
    }

    /**
     * Top-level albums of one convention, for /natcon/gallery.
     *
     * The page used to rebuild its album tree from the flat photo list above,
     * which had two consequences worth remembering: it inherited that read's
     * 100-photo ceiling, and an album holding no photos YET could not be
     * reconstructed at all — "Day 1" and "Day 2" existed in the admin and were
     * invisible to the public right up until the first photo landed.
     *
     * So this lists albums as albums, empty ones included, exactly as
     * publicAlbums() does for /albums. Unknown year → empty list, never a 404:
     * SSR is the consumer.
     */
    public function natconAlbums(int $year): JsonResponse
    {
        $event = NatconEvent::forYear($year);

        if (! $event) {
            return response()->json(['data' => []]);
        }

        $albums = GalleryAlbum::forEvent($event)->orderBy('sort_order')->orderBy('name')->get();
        $stats = $this->albumStats($albums);

        $data = $albums
            ->filter(fn (GalleryAlbum $a) => $a->parent_id === null)
            ->values()
            ->map(fn (GalleryAlbum $a) => $this->presentPublicAlbum($a, $albums, $stats));

        return response()->json(['data' => $data]);
    }

    /**
     * One convention album: breadcrumb, sub-albums, its own live photos,
     * frames — the same shape publicAlbum() serves, for /natcon/gallery/{slug}.
     *
     * ⚠️ Deliberately NOT keyed by year, unlike every other public NATCON read.
     *    Slugs are unique across the whole table, so the slug alone resolves
     *    the album AND tells us its convention. Keying by year would mean a
     *    link shared during NATCON 2026 breaking the moment 2027 goes live —
     *    and these URLs are pasted into Messenger threads that outlive the
     *    event by years. The year rides back in `event` for the page's chrome.
     *
     * No limit() on the photos: this endpoint exists partly to lift the
     * gallery read's 100-photo ceiling, and an album IS the unit of paging
     * here.
     */
    public function natconAlbum(string $slug): JsonResponse
    {
        $album = GalleryAlbum::with('event')
            ->whereNotNull('natcon_event_id')
            ->where('slug', $slug)
            ->first();

        // A public album's slug must not resolve here either — /albums is its
        // own doorway, with its own chrome.
        if (! $album || ! $album->event) {
            return response()->json(['message' => 'Album not found.'], 404);
        }

        $albums = GalleryAlbum::forEvent($album->event)->orderBy('sort_order')->orderBy('name')->get();
        $stats = $this->albumStats($albums);

        $children = $albums
            ->filter(fn (GalleryAlbum $a) => $a->parent_id === $album->id)
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
                // Own + inherited + the convention's "{year} Frames", so the
                // photo studio needs no second request. Same as publicAlbum().
                'frames' => $this->framesFor($album)->map(fn (GalleryAlbumFrame $f) => $this->presentFrame($f))->values(),
                // Which convention this album belongs to — the page titles
                // itself with it and scopes "Find my photos" by its year.
                'event' => [
                    'year' => $album->event->year,
                    // displayShortName(), not the raw column: short_name is
                    // nullable and the page prints this verbatim.
                    'short_name' => $album->event->displayShortName(),
                ],
            ],
        ]);
    }

    // ── Public: /albums ──────────────────────────────────────────────────────

    /**
     * Top-level public albums with a cover and a recursive live-photo count.
     * Empty albums ARE listed (owner's call, 2026-08-27): an album tree like
     * Vietnam › 2026 › 1st Day is set up before the photos land, and the
     * public must be able to browse into it — an empty album shows a
     * placeholder card and "no photos yet" instead of vanishing.
     */
    public function publicAlbums(): JsonResponse
    {
        $albums = GalleryAlbum::forEvent(null)->orderBy('sort_order')->orderBy('name')->get();
        $stats = $this->albumStats($albums);

        $data = $albums
            ->filter(fn (GalleryAlbum $a) => $a->parent_id === null)
            ->values()
            ->map(fn (GalleryAlbum $a) => $this->presentPublicAlbum($a, $albums, $stats));

        return response()->json(['data' => $data]);
    }

    /**
     * One public album: breadcrumb, ALL its sub-albums (with covers where
     * they have photos), its own live photos. Only public albums resolve — a convention album's id or
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
            ->filter(fn (GalleryAlbum $a) => $a->parent_id === $album->id)
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
                // Frames offered on this album's photos — its own plus its
                // ancestors' (see framesFor). Rides the one fetch the page
                // already makes, so the frame button needs no extra request.
                'frames' => $this->framesFor($album)->map(fn (GalleryAlbumFrame $f) => $this->presentFrame($f))->values(),
            ],
        ]);
    }

    // ── Admin ────────────────────────────────────────────────────────────────

    public function adminGallery(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);

        // Hidden rows included for admins — this is the editing surface. Agents
        // (the /agent/natcon/gallery reader) get live rows only, exactly what
        // the public page shows. Deleted rows are never listed: they exist to
        // keep the S3 object findable, not to be relisted.
        $rows = GalleryPhoto::with('album:id,parent_id,slug,name,sort_order')
            ->forEvent($event)
            ->where('status', '!=', GalleryPhoto::STATUS_DELETED)
            ->when(! $this->viewerIsAdmin($request), fn ($q) => $q->where('status', GalleryPhoto::STATUS_ACTIVE))
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
        $photos = GalleryPhoto::with('album:id,parent_id,slug,name,sort_order')
            ->forEvent($event)
            ->whereIn('id', array_keys($matches))
            ->where('status', '!=', GalleryPhoto::STATUS_DELETED)
            // Agents: a hidden photo must not surface through a selfie either.
            ->when(! $this->viewerIsAdmin($request), fn ($q) => $q->where('status', GalleryPhoto::STATUS_ACTIVE))
            ->get()
            ->keyBy('id');

        $data = [];
        foreach ($matches as $photoId => $m) {
            $photo = $photos->get($photoId);
            if (! $photo) {
                continue;
            }
            $data[] = $this->present($photo, detailed: true) + [
                'similarity' => round($m['similarity'], 1),
                // Matched face's share of the frame (0-1) — see searchByImage.
                'face_area' => round($m['face_area'], 4),
            ];
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

        // Whether each matched album offers frames (own or inherited) — one
        // pass up front, so every tile knows to show its frame button
        // without a per-photo round trip.
        $frameFlags = $this->frameFlagsFor(
            $photos->pluck('album.id')->filter()->unique()->values()->all(),
        );

        $data = [];
        foreach ($matches as $photoId => $m) {
            $photo = $photos->get($photoId);
            if (! $photo || ! $photo->album) {
                continue;
            }
            // ⚠️ array_merge, NOT `+`. present() already returns an `album`
            //    key, and the union operator keeps the LEFT operand's version —
            //    so this richer album (the one carrying `slug` and
            //    `has_frames`) was silently thrown away on every match.
            //    `similarity` survived because nothing collides with it, which
            //    is exactly why the bug read as "frames are broken in face
            //    search" rather than as a malformed response: the tiles showed
            //    their match percentage and no frame button, and the grid could
            //    neither gate on has_frames nor fetch by slug.
            $data[] = array_merge($this->present($photo), [
                'similarity' => round($m['similarity'], 1),
                'face_area' => round($m['face_area'], 4),
                'album' => [
                    'id' => $photo->album->id,
                    'slug' => $photo->album->slug,
                    'name' => $photo->album->name,
                    'path' => $photo->album->path(),
                    'has_frames' => (bool) ($frameFlags[$photo->album->id] ?? false),
                ],
            ]);
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
     * The visitor-facing "find my photos" on /natcon/gallery: the admin
     * search's probe over ONE convention's collection, returning that year's
     * LIVE photos only — a hidden photo must never surface through a selfie.
     * Behind the guest token + a throttle, like the /albums search: every hit
     * is up to five Rekognition calls.
     *
     * Keyed by year, like every public NATCON read, so the page never has to
     * resolve an event id first. A year with no event is a 404 here rather
     * than an empty list: the selfies were uploaded for nothing, and the page
     * should say so instead of "no matches".
     */
    public function publicNatconFaceSearch(Request $request, int $year): JsonResponse
    {
        $event = NatconEvent::forYear($year);
        if (! $event) {
            return response()->json(['message' => 'No NATCON gallery for that year.'], 404);
        }

        $result = $this->probeFaces($request, $event);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        [$matches, $mode, $total] = $result;

        // whereIn loses the similarity ordering; reassemble in match order.
        $photos = GalleryPhoto::with('album:id,parent_id,slug,name,sort_order')
            ->forEvent($event)
            ->whereIn('id', array_keys($matches))
            ->where('status', GalleryPhoto::STATUS_ACTIVE)
            ->get()
            ->keyBy('id');

        $data = [];
        foreach ($matches as $photoId => $m) {
            $photo = $photos->get($photoId);
            if (! $photo) {
                continue;
            }
            $data[] = $this->present($photo) + [
                'similarity' => round($m['similarity'], 1),
                'face_area' => round($m['face_area'], 4),
                // How many faces the photo holds, for the results' "fewest
                // faces first" order (solo shots lead, group photos trail) —
                // a count only, nothing about whose faces they are.
                'face_count' => $photo->face_count,
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'matched' => count($data),
                'threshold' => (float) config('natcon.gallery.match_threshold', 90),
                'mode' => $mode,
                'faces_searched' => $total,
                'year' => $event->year,
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
        // Scoped to the root's own gallery (a convention's or the public one) —
        // parent_id chains never cross scopes, so this is the whole tree.
        $byParent = GalleryAlbum::forEvent($root->event)
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

    /** Most probe faces per search, across every uploaded photo. */
    private const MAX_PROBE_FACES = 5;

    /**
     * Turn the request's selfies into one Rekognition result set per FACE and
     * fold them by `mode`. Probes are compared only, never stored. Returns a
     * ready 422 for a user-fixable problem.
     *
     * "Face" here is not "uploaded photo": Rekognition's search only ever
     * looks at the LARGEST face in the bytes it is handed, so a photo of two
     * people is first cut into one crop per detected face, and each crop is
     * searched on its own. One group selfie therefore behaves exactly like
     * two separate selfies — and the either-face / in-one-photo mode applies
     * to it. A photo where detection finds one face (or nothing it trusts) is
     * searched whole, as before, so a plain selfie costs one search call.
     *
     * The third element of the tuple is the number of FACES searched — the
     * frontend shows the mode switch from two up.
     *
     * @return JsonResponse|array{0: array<int, array{similarity: float, face_area: float}>, 1: string, 2: int}
     */
    private function probeFaces(Request $request, ?NatconEvent $event): JsonResponse|array
    {
        $files = $request->file('selfies') ?? [];

        validator(
            ['selfies' => $files, 'mode' => $request->input('mode')],
            [
                'selfies' => 'required|array|min:1|max:'.self::MAX_PROBE_FACES,
                'selfies.*' => 'required|image|mimes:jpeg,jpg,png,webp|max:15360',
                'mode' => 'sometimes|nullable|in:all,any',
            ],
        )->validate();

        $mode = (string) $request->input('mode', 'all');
        $total = count($files);

        $manager = new ImageManager(new Driver);

        /** @var array<int, array<int, array{similarity: float, face_area: float}>> $perFace one photoId=>match map per probe FACE */
        $perFace = [];
        foreach (array_values($files) as $i => $file) {
            $label = $total > 1 ? 'photo '.($i + 1).' of '.$total : 'that photo';

            $info = @getimagesize($file->getRealPath());
            if ($info === false || ($info[0] * $info[1]) > 40_000_000) {
                return response()->json(['message' => ucfirst($label).' does not look like a usable photo.'], 422);
            }

            $image = $manager->read($file->getRealPath())->scaleDown(width: 1500, height: 1500);
            $probe = (string) $image->toJpeg(85);

            // Room left under the cap for this photo's faces (always ≥ 1, so
            // every uploaded photo contributes at least its largest face).
            $room = max(1, self::MAX_PROBE_FACES - count($perFace));
            $boxes = $room > 1 ? $this->faces->detectFaceBoxes($probe, $room) : [];

            $crops = [];
            if (count($boxes) >= 2) {
                $w = $image->width();
                $h = $image->height();
                foreach ($boxes as $b) {
                    // Pad the box by 60% each side: Rekognition wants the
                    // whole head plus some context, not a tight face cut.
                    $bw = $b['width'] * $w;
                    $bh = $b['height'] * $h;
                    $x0 = (int) max(0, floor($b['left'] * $w - $bw * 0.6));
                    $y0 = (int) max(0, floor($b['top'] * $h - $bh * 0.6));
                    $x1 = (int) min($w, ceil($b['left'] * $w + $bw * 1.6));
                    $y1 = (int) min($h, ceil($b['top'] * $h + $bh * 1.6));
                    if ($x1 - $x0 < 40 || $y1 - $y0 < 40) {
                        continue;
                    }
                    $crop = (clone $image)->crop($x1 - $x0, $y1 - $y0, $x0, $y0);
                    // A face a step behind the others can come out as a ~150px
                    // crop. Rekognition wants at least 80px and matches better
                    // with more; scale small crops up to 320px on the short side
                    // (no new detail, but no "image too small" either).
                    $short = min($crop->width(), $crop->height());
                    if ($short < 320) {
                        $crop = $crop->scale(width: (int) round($crop->width() * 320 / $short));
                    }
                    $crops[] = (string) $crop->toJpeg(85);
                }
            }
            if (count($crops) < 2) {
                $crops = [$probe];
            }

            foreach ($crops as $crop) {
                try {
                    $perFace[] = $this->faces->searchByImage($event, $crop);
                } catch (RuntimeException $e) {
                    // A crop that Rekognition then cannot read is skipped, not
                    // fatal — detection already vouched for the other faces.
                    if (count($crops) > 1) {
                        continue;
                    }

                    return response()->json([
                        'message' => $total > 1
                            ? 'No face could be detected in '.$label.'. Please use a clear, well-lit photo of that person.'
                            : $e->getMessage(),
                    ], 422);
                }
            }
        }

        if ($perFace === []) {
            return response()->json(['message' => 'No face could be detected. Please use a clear, well-lit photo.'], 422);
        }

        return [$this->faces->combineMatches($perFace, $mode), $mode, count($perFace)];
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

    /**
     * Re-run Rekognition IndexFaces on one photo, inline (one call, ~1s), and
     * return the fresh row so the tile's badge updates at once. Same AUTO
     * quality filter as the upload — this is a retry, not a different scan.
     */
    public function reindexGalleryPhoto(Request $request, GalleryPhoto $photo): JsonResponse
    {
        $this->guardScope($request, $photo->event);
        abort_if($photo->status === GalleryPhoto::STATUS_DELETED, 404, 'Photo not found.');

        try {
            $this->faces->indexPhoto($photo);
        } catch (\Throwable $e) {
            $photo->forceFill(['index_error' => mb_substr($e->getMessage(), 0, 512)])->save();

            return response()->json(['message' => 'Re-index failed: '.$e->getMessage()], 502);
        }

        return response()->json(['data' => $this->present($photo->fresh(), detailed: true)]);
    }

    // ── Trash (deleted photos: restore, or remove for real) ──────────────────

    /**
     * What has been removed from this scope and can still be got back.
     *
     * Every delete in this module is a status flip, so "deleted" photos have
     * always been sitting in the table — findable only by an admin with SQL.
     * Photographers delete their own work through the portal and an event-day
     * mistake was unrecoverable in practice. This is that drawer, opened.
     *
     * `restores_as` and `deleted_by` are reconstructed from the audit trail,
     * not stored: the status column holds one value and the row it replaced
     * (active vs hidden) is only recorded in the audit. Shown on the tile so
     * the admin knows whether restoring republishes a photo or returns it to
     * the review queue BEFORE they press it.
     */
    public function trash(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);

        $rows = GalleryPhoto::with(['album:id,parent_id,slug,name,sort_order', 'uploadInvite:id,label'])
            ->forEvent($event)
            ->where('status', GalleryPhoto::STATUS_DELETED)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $meta = $this->deletionMeta($rows->pluck('id')->all());

        return response()->json(['data' => $rows->map(function (GalleryPhoto $p) use ($meta, $event) {
            $m = $meta[$p->id] ?? [];

            return $this->present($p, detailed: true) + [
                'restores_as' => $m['restores_as'] ?? GalleryPhoto::STATUS_HIDDEN,
                'deleted_at' => ($m['deleted_at'] ?? $p->updated_at)?->toIso8601String(),
                'deleted_by' => $m['deleted_by'] ?? null,
                // False for the imported rows whose file belongs to another
                // folder: deleting those removes the row only.
                's3_owned' => $this->ownedS3Keys($p, $event) !== [],
                'invite' => $p->uploadInvite ? [
                    'id' => $p->uploadInvite->id,
                    'label' => $p->uploadInvite->label,
                ] : null,
            ];
        })]);
    }

    /**
     * Put photos back where they were.
     *
     * Restores to the status the audit says they held — a photo hidden pending
     * review returns to the review queue, not to the public page. Fallback is
     * hidden, which is also what a row whose audit has aged out gets: showing
     * something publicly again is the admin's call, never a guess of ours.
     *
     * ⚠️ The face columns must be nulled. forgetFaces() evicted the vectors
     *    from Rekognition on delete but left face_ids/faces_indexed_at on the
     *    row, and the re-index sweep's work-list is "faces_indexed_at IS NULL"
     *    — so without this a restored photo would never be findable by face
     *    again. Nulling hands it to natcon:index-gallery-faces, which runs
     *    every five minutes; indexing inline would be a Rekognition round trip
     *    per photo with a whole multi-select waiting on it.
     */
    public function restorePhotos(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);
        $ids = $this->trashIds($request, max: 200);

        $rows = GalleryPhoto::with('album')
            ->forEvent($event)
            ->whereIn('id', $ids)
            ->where('status', GalleryPhoto::STATUS_DELETED)
            ->get();

        $meta = $this->deletionMeta($rows->pluck('id')->all());
        $restored = [];
        $purgeAlbums = [];

        foreach ($rows as $photo) {
            $status = $meta[$photo->id]['restores_as'] ?? GalleryPhoto::STATUS_HIDDEN;

            $photo->auditSource = $this->auditSource($event);
            $photo->auditDescription = 'Restored from trash';
            $photo->forceFill([
                'status' => $status,
                'face_ids' => null,
                // ⚠️ 0, not null: gallery_photos.face_count is NOT NULL
                //    DEFAULT 0 (the sibling natcon_album_photos table is
                //    nullable, which is the trap). `faces_indexed_at` is the
                //    sweep's work-list and IS nullable — that one is what
                //    actually re-queues the photo.
                'face_count' => 0,
                'faces_indexed_at' => null,
                'index_error' => null,
            ])->save();

            $restored[] = $photo;

            // Only a photo that is public again changes a public page.
            if ($status === GalleryPhoto::STATUS_ACTIVE) {
                $purgeAlbums[$photo->album_id] = $photo->album;
            }
        }

        foreach ($purgeAlbums as $album) {
            $this->purge($event, $album);
        }

        return response()->json(['data' => [
            'restored' => array_map(fn (GalleryPhoto $p) => $this->present($p->fresh(), detailed: true), $restored),
            'skipped' => array_values(array_diff($ids, array_map(fn (GalleryPhoto $p) => $p->id, $restored))),
        ]]);
    }

    /**
     * Delete photos for real — the row AND the file in the bucket.
     *
     * The only place in this module that does. Everything else flips a status
     * precisely because the row is the only pointer to the S3 object; here the
     * admin has said the photo should not exist, so the pointer and the object
     * go together and the audit row is what survives.
     */
    public function purgePhotos(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);
        $ids = $this->trashIds($request, max: 100);

        $rows = GalleryPhoto::forEvent($event)
            ->whereIn('id', $ids)
            ->where('status', GalleryPhoto::STATUS_DELETED)
            ->get();

        $purged = [];
        $failed = [];

        foreach ($rows as $photo) {
            $result = $this->purgeOne($photo, $event);
            if ($result['ok']) {
                $purged[] = ['id' => $result['id'], 's3_removed' => $result['s3_removed']];
            } else {
                $failed[] = ['id' => $result['id'], 'reason' => $result['reason']];
            }
        }

        return response()->json(['data' => [
            'purged' => $purged,
            'failed' => $failed,
        ]]);
    }

    /**
     * Empty the whole trash for this scope, in capped batches.
     *
     * `expected_count` is the count the admin was looking at when they typed
     * the confirmation. A mismatch means somebody else restored or removed
     * something in between, and the answer is 409 — this is the one action in
     * the module that destroys files, and it must not run against a drawer
     * whose contents changed under it.
     *
     * Capped at 100 rows a call because each one is an S3 delete on a request
     * thread (no queue in production); the UI loops on `remaining`.
     */
    public function emptyTrash(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);

        $request->validate([
            'confirm' => 'required|string|in:EMPTY TRASH',
            'expected_count' => 'required|integer|min:0',
        ]);

        $query = fn () => GalleryPhoto::forEvent($event)->where('status', GalleryPhoto::STATUS_DELETED);

        $total = $query()->count();
        if ($total !== (int) $request->input('expected_count')) {
            return response()->json([
                'message' => 'The trash changed since you looked — reopen it and try again.',
                'count' => $total,
            ], 409);
        }

        $purged = [];
        $failed = [];

        foreach ($query()->orderBy('id')->limit(100)->get() as $photo) {
            $result = $this->purgeOne($photo, $event);
            if ($result['ok']) {
                $purged[] = ['id' => $result['id'], 's3_removed' => $result['s3_removed']];
            } else {
                $failed[] = ['id' => $result['id'], 'reason' => $result['reason']];
            }
        }

        return response()->json(['data' => [
            'purged' => $purged,
            'failed' => $failed,
            'remaining' => $query()->count(),
        ]]);
    }

    /** Shared validation for the trash's id-list bodies. */
    private function trashIds(Request $request, int $max): array
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:'.$max,
            'ids.*' => 'integer',
        ]);

        return array_values(array_unique(array_map('intval', $data['ids'])));
    }

    /**
     * Who removed each photo and what status it held before — read back from
     * the audit rows, which are the only record of either.
     *
     * One indexed query over the morph index for the whole batch. The newest
     * `updated` row whose new status is `deleted` is the delete; its old status
     * is what a restore should return to.
     *
     * @return array<int, array{restores_as: string, deleted_at: ?\Illuminate\Support\Carbon, deleted_by: ?string}>
     */
    private function deletionMeta(array $photoIds): array
    {
        if ($photoIds === []) {
            return [];
        }

        $audits = Audit::query()
            ->where('auditable_type', GalleryPhoto::class)
            ->whereIn('auditable_id', $photoIds)
            ->where('event', 'updated')
            ->orderByDesc('id')
            ->get(['auditable_id', 'old_values', 'new_values', 'user_name', 'source', 'created_at']);

        $out = [];
        foreach ($audits as $audit) {
            $id = (int) $audit->auditable_id;
            if (isset($out[$id])) {
                continue; // Newest wins — this is an older flip of the same row.
            }
            if (($audit->new_values['status'] ?? null) !== GalleryPhoto::STATUS_DELETED) {
                continue;
            }

            $prior = $audit->old_values['status'] ?? null;

            $out[$id] = [
                'restores_as' => in_array($prior, [GalleryPhoto::STATUS_ACTIVE, GalleryPhoto::STATUS_HIDDEN], true)
                    ? $prior
                    : GalleryPhoto::STATUS_HIDDEN,
                'deleted_at' => $audit->created_at,
                'deleted_by' => $audit->user_name
                    ?: ($audit->source === 'photographer_invite' ? 'Photographer' : null),
            ];
        }

        return $out;
    }

    /**
     * The S3 keys this photo's scope actually OWNS.
     *
     * Not every row's file is ours to delete: natcon:import-gallery rows point
     * at folders that belong elsewhere (and carry no thumb), s3_key is
     * nullable, and two rows can legitimately reference the same object. So a
     * key is removable only when it sits under this scope's own prefix and no
     * other row still points at it — otherwise the answer is an empty list and
     * the row is dropped on its own.
     *
     * The thumb comes from thumb_url, never from guessing a suffix on s3_key:
     * the naming is GalleryService's business and an imported row has none.
     *
     * @return array<int, string>
     */
    private function ownedS3Keys(GalleryPhoto $photo, ?NatconEvent $event): array
    {
        $prefix = trim(
            $event
                ? $event->s3Prefix('gallery')
                : (string) config('natcon.gallery.public_s3_prefix', 'filipinohomes-new/gallery'),
            '/'
        ).'/';

        $base = rtrim((string) config('filesystems.disks.s3.url'), '/').'/';

        $keys = [];

        if ($photo->s3_key && Str::startsWith($photo->s3_key, $prefix)) {
            $shared = GalleryPhoto::where('s3_key', $photo->s3_key)
                ->where('id', '!=', $photo->id)
                ->exists();
            if (! $shared) {
                $keys[] = $photo->s3_key;
            }
        }

        if ($photo->thumb_url && Str::startsWith($photo->thumb_url, $base)) {
            $thumbKey = Str::after($photo->thumb_url, $base);
            if (Str::startsWith($thumbKey, $prefix)) {
                $shared = GalleryPhoto::where('thumb_url', $photo->thumb_url)
                    ->where('id', '!=', $photo->id)
                    ->exists();
                if (! $shared) {
                    $keys[] = $thumbKey;
                }
            }
        }

        return $keys;
    }

    /**
     * Remove one trash row for good: vectors, then files, then the row.
     *
     * ⚠️ S3 BEFORE the row, always. The row is the only record of the key, so
     *    deleting it first and failing on the bucket leaves an object nobody
     *    can ever find again to clean up — the exact thing the soft-delete rule
     *    exists to prevent. The disk is configured with throw=false, so the
     *    boolean is the error channel; a missing key answers true, which makes
     *    a retry after a half-failure converge.
     *
     * The audit row written by delete() carries the full old_values (s3_key,
     * caption, upload_invite_id) and the invite tag, so the photographer's
     * history still shows this photo after the row is gone.
     *
     * @return array{ok: bool, id: int, s3_removed: bool, reason: ?string}
     */
    private function purgeOne(GalleryPhoto $photo, ?NatconEvent $event): array
    {
        $this->forgetFaces($photo);

        $keys = $this->ownedS3Keys($photo, $event);

        if ($keys !== []) {
            try {
                $ok = Storage::disk('s3')->delete($keys);
            } catch (\Throwable $e) {
                Log::warning('gallery purge: s3 delete threw', [
                    'photo_id' => $photo->id,
                    'error' => $e->getMessage(),
                ]);
                $ok = false;
            }

            if (! $ok) {
                return ['ok' => false, 'id' => $photo->id, 's3_removed' => false, 'reason' => 'storage'];
            }
        }

        $id = $photo->id;
        $photo->auditSource = $this->auditSource($event);
        $photo->auditDescription = $keys === []
            ? 'Permanently deleted (file kept — not this gallery\'s)'
            : 'Permanently deleted with '.count($keys).' file(s)';
        $photo->delete();

        return ['ok' => true, 'id' => $id, 's3_removed' => $keys !== [], 'reason' => null];
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
            ->withCount(['photos' => fn ($q) => $this->viewerIsAdmin($request)
                ? $q->where('status', '!=', GalleryPhoto::STATUS_DELETED)
                : $q->where('status', GalleryPhoto::STATUS_ACTIVE), ])
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
            // Convention albums only: which half of the public gallery this
            // belongs to. Public albums carry the default and ignore it.
            'section' => 'sometimes|in:'.implode(',', GalleryAlbum::SECTIONS),
            'album_date' => 'sometimes|nullable|date',
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
            // Every album is URL-addressable: /albums/{slug} for the public
            // ones, /natcon/gallery/{slug} for a convention's.
            'slug' => GalleryAlbum::uniqueSlug($name),
            'name' => $name,
            'section' => $data['section'] ?? GalleryAlbum::SECTION_EVENT,
            'album_date' => $data['album_date'] ?? null,
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
            'section' => 'sometimes|in:'.implode(',', GalleryAlbum::SECTIONS),
            'album_date' => 'sometimes|nullable|date',
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
        // and search engines hold. An album created before its scope had
        // slugs gets one lazily here; the migration backfilled the rest.
        if (! $album->slug) {
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

        // Frames too, and BEFORE the delete: their FK cascades, so the audit
        // row written by this flip is what preserves each frame's s3_key.
        $album->frames()
            ->where('status', '!=', GalleryAlbumFrame::STATUS_DELETED)
            ->get()
            ->each(function (GalleryAlbumFrame $frame) use ($event) {
                $frame->auditSource = $this->auditSource($event);
                $frame->forceFill(['status' => GalleryAlbumFrame::STATUS_DELETED])->save();
            });

        // A real delete, unlike photos: an album owns no S3 object.
        $album->auditSource = $this->auditSource($event);
        $album->delete();

        $this->purge($event, $album);

        return response()->json(['message' => 'Album removed.']);
    }

    // ── Album frames (decorative PNG overlays on public AND convention albums) ─
    //
    // Both scopes share these methods; guardScope() pins each route family to
    // its own rows. Public visitors read frames by slug (public albums only);
    // convention frames are used from the admin gallery's frame tool.

    /**
     * Product switch: do a parent album's frames apply to photos in its
     * sub-albums? True per the owner's call — upload the "Vietnam" frames
     * once on the trip album and every sub-album's photos can wear them.
     */
    private const FRAMES_INHERIT = true;

    /** The audience key for an awardee with no special award (the roster's NULL segment). */
    private const REGULAR_AWARDEE = 'top_agent';

    /**
     * Stored in award_segments when nothing is ticked in the first column ("Segment"): the frame is still an
     * awardee frame (an empty list would make it a public one), it just isn't for anyone in that column.
     */
    private const NO_ONE = 'no_one';

    /** What an awardee frame can be reserved for: the five awards, plus "regular awardee" and the nobody marker. */
    private const FRAME_AUDIENCES = [
        \App\Natcon\Models\Recipient::SEGMENT_GLOBAL_PARTNER,
        \App\Natcon\Models\Recipient::SEGMENT_FHI_GLOBAL,
        \App\Natcon\Models\Recipient::SEGMENT_RENT_MANAGER,
        \App\Natcon\Models\Recipient::SEGMENT_RENT_MANAGER_RM_PRO,
        \App\Natcon\Models\Recipient::SEGMENT_ELITE_TEAM_LEADER,
        self::REGULAR_AWARDEE,
        self::NO_ONE,
    ];

    /** Public: frames offered for one album's photos. Token-less, never 401. */
    public function publicAlbumFrames(string $slug): JsonResponse
    {
        $album = GalleryAlbum::forEvent(null)->where('slug', $slug)->first();

        if (! $album) {
            return response()->json(['message' => 'Album not found.'], 404);
        }

        return response()->json([
            'data' => $this->framesFor($album)->map(fn (GalleryAlbumFrame $f) => $this->presentFrame($f))->values(),
        ]);
    }

    /**
     * Public: frames offered for one CONVENTION album's photos — own,
     * inherited and the convention's "{year} Frames", the same framesFor()
     * set the admin "Use in a frame" tool gets. Token-less, never 401: the
     * public /natcon/gallery opens a photo into the frame studio. The album
     * must belong to the year's convention, so a public album's frames are
     * never reachable here (and a convention album's never through
     * /albums/{slug}/frames). Unknown year or album → 404.
     */
    public function publicNatconAlbumFrames(int $year, int $album): JsonResponse
    {
        $event = NatconEvent::forYear($year);
        $row = $event ? GalleryAlbum::forEvent($event)->whereKey($album)->first() : null;

        if (! $row) {
            return response()->json(['message' => 'Album not found.'], 404);
        }

        return response()->json([
            'data' => $this->framesFor($row)->map(fn (GalleryAlbumFrame $f) => $this->presentFrame($f))->values(),
        ]);
    }

    /**
     * Admin: the album's OWN live frames (inheritance is presentation only) —
     * or, with ?inherit=1, the full set a photo in this album can wear (own +
     * ancestors'), which is what the admin "Use in a frame" tool needs.
     */
    public function albumFrames(Request $request, GalleryAlbum $album): JsonResponse
    {
        $this->guardScope($request, $album->event);

        $rows = $request->boolean('inherit')
            ? $this->framesFor($album, $this->viewerIsAdmin())
            : $album->frames()->live()->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $rows->map(fn (GalleryAlbumFrame $f) => $this->presentFrame($f))]);
    }

    public function storeAlbumFrame(Request $request, GalleryAlbum $album): JsonResponse
    {
        $this->guardScope($request, $album->event);

        return $this->saveFrame($request, $album, null);
    }

    /**
     * Validate + store one frame PNG for either owner: an album (public
     * albums, per-album frames) or a whole convention (the NATCON root's
     * "{year} Frames"). Exactly one of the two is set.
     */
    private function saveFrame(Request $request, ?GalleryAlbum $album, ?NatconEvent $event): JsonResponse
    {
        $data = $request->validate([
            'frame' => 'required|file|mimes:png|max:'.(int) config('natcon.gallery.max_upload_kb', 15360),
            'name' => 'required|string|max:120',
            // The transparent photo window, as fractions of the frame's own
            // dimensions — detected client-side from the PNG's alpha channel.
            'window_x' => 'required|numeric|min:0|max:1',
            'window_y' => 'required|numeric|min:0|max:1',
            'window_w' => 'required|numeric|min:0.05|max:1',
            'window_h' => 'required|numeric|min:0.05|max:1',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
            // Reserve the frame for one award segment (null/absent = everyone).
            'award_segments' => ['nullable', 'array'],
            'award_segments.*' => ['string', \Illuminate\Validation\Rule::in(self::FRAME_AUDIENCES)],
            // Within those segments: only for awardees flagged Elite.
            'elite_only' => 'sometimes|boolean',
            // A VVIP frame: category (+ optional rank). Mutually exclusive with award segments.
            'vvip_category' => 'nullable|string|max:120',
            'vvip_rank' => 'nullable|integer|min:1|max:999',
            'vvip' => 'sometimes|boolean',
            'require_vvip' => 'sometimes|boolean',
            // The VVIP column of Who can use it (shown when the VVIP box is ticked): its own awards / Elite; null = copy the first column.
            'either_vvip' => 'sometimes|boolean',
            'vvip_award_segments' => ['sometimes', 'nullable', 'array'],
            'vvip_award_segments.*' => ['string', \Illuminate\Validation\Rule::in(self::FRAME_AUDIENCES)],
            'vvip_elite_only' => 'sometimes|boolean',
            // The Elite Circle column (its own box, then its own awards; null = copy the first column).
            'either_elite' => 'sometimes|boolean',
            'elite_award_segments' => ['sometimes', 'nullable', 'array'],
            'elite_award_segments.*' => ['string', \Illuminate\Validation\Rule::in(self::FRAME_AUDIENCES)],
            'vvip_types' => ['nullable', 'array'],
            'vvip_types.*' => ['string', \Illuminate\Validation\Rule::in(array_keys(\App\Natcon\Models\VvipEntry::TYPES))],
        ], [
            'frame.mimes' => 'Please upload a PNG with a transparent photo window.',
            'frame.max' => 'That frame is too large. Please keep it under 15MB.',
        ]);

        if ($data['window_x'] + $data['window_w'] > 1.0001 || $data['window_y'] + $data['window_h'] > 1.0001) {
            return response()->json(['message' => 'The photo window falls outside the frame.'], 422);
        }

        // Same decompression-bomb gate as every image upload — it runs on
        // getimagesize(), no decode, so the alpha channel is never touched.
        $check = $this->photos->inspect($request->file('frame'));
        if (! $check['ok']) {
            return response()->json(['message' => $check['reason'] ?? 'Unsupported image.'], 422);
        }

        // ORIGINAL bytes to S3 — never GalleryService::store, whose JPEG
        // re-encode would flatten the transparent window the whole feature
        // depends on (the GifUploadController precedent).
        $prefix = trim((string) config('natcon.gallery.public_s3_prefix', 'filipinohomes-new/gallery'), '/');
        $key = $prefix.'/frames/'.Str::uuid().'.png';
        Storage::disk('s3')->put($key, file_get_contents($request->file('frame')->getRealPath()), 'public');

        $frame = new GalleryAlbumFrame([
            'album_id' => $album?->id,
            'natcon_event_id' => $event?->id,
            // Only convention-level frames can be reserved for a segment.
            'award_segments' => $event ? $this->normaliseSegments($data['award_segments'] ?? null) : null,
            'elite_only' => $event && ! empty($data['award_segments']) && ! empty($data['elite_only']),
            'vvip' => $event && (! empty($data['vvip']) || ! empty($data['vvip_category'])),
            // An awardee frame that only VVIPs (who also meet the other conditions) can use.
            'require_vvip' => $event && ! empty($data['require_vvip']) && ! empty($data['award_segments']) && empty($data['vvip']),
            'either_vvip' => $event && (! empty($data['either_vvip']) || ! empty($data['require_vvip'])) && ! empty($data['award_segments']) && empty($data['vvip']),
            'vvip_award_segments' => $event && (! empty($data['either_vvip']) || ! empty($data['require_vvip'])) ? $this->normaliseSegments($data['vvip_award_segments'] ?? null) : null,
            'vvip_elite_only' => $event && (! empty($data['either_vvip']) || ! empty($data['require_vvip'])) && ! empty($data['vvip_elite_only']),
            'either_elite' => $event && ! empty($data['either_elite']) && ! empty($data['award_segments']) && empty($data['vvip']),
            'elite_award_segments' => $event && ! empty($data['either_elite']) ? $this->normaliseSegments($data['elite_award_segments'] ?? null) : null,
            'vvip_types' => $event ? $this->normaliseTypes($data['vvip_types'] ?? null) : null,
            'vvip_category' => $event ? ($data['vvip_category'] ?? null) : null,
            'vvip_rank' => $event && ! empty($data['vvip_category']) ? ($data['vvip_rank'] ?? null) : null,
            'name' => trim($data['name']),
            'image_url' => rtrim((string) config('filesystems.disks.s3.url'), '/').'/'.$key,
            's3_key' => $key,
            'width' => $check['width'] ?? null,
            'height' => $check['height'] ?? null,
            'byte_size' => $request->file('frame')->getSize(),
            'window_x' => $data['window_x'],
            'window_y' => $data['window_y'],
            'window_w' => $data['window_w'],
            'window_h' => $data['window_h'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'status' => GalleryAlbumFrame::STATUS_ACTIVE,
            'created_by' => $request->user()?->id,
        ]);
        $frame->auditSource = $this->auditSource(null);
        $frame->save();

        $this->purge($event ?? $album?->event, $album);

        return response()->json(['data' => $this->presentFrame($frame)], 201);
    }

    public function updateAlbumFrame(Request $request, GalleryAlbumFrame $frame): JsonResponse
    {
        $this->guardScope($request, $frame->ownerEvent());
        // Implicit binding happily resolves deleted rows — refuse them, or a
        // PATCH could resurrect a removed frame's visibility.
        abort_if($frame->status === GalleryAlbumFrame::STATUS_DELETED, 404, 'Frame not found.');

        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
            'award_segments' => ['sometimes', 'nullable', 'array'],
            'award_segments.*' => ['string', \Illuminate\Validation\Rule::in(self::FRAME_AUDIENCES)],
            'elite_only' => 'sometimes|boolean',
            'vvip_category' => 'sometimes|nullable|string|max:120',
            'vvip_rank' => 'sometimes|nullable|integer|min:1|max:999',
            'vvip' => 'sometimes|boolean',
            'require_vvip' => 'sometimes|boolean',
            // The VVIP column of Who can use it (shown when the VVIP box is ticked): its own awards / Elite; null = copy the first column.
            'either_vvip' => 'sometimes|boolean',
            'vvip_award_segments' => ['sometimes', 'nullable', 'array'],
            'vvip_award_segments.*' => ['string', \Illuminate\Validation\Rule::in(self::FRAME_AUDIENCES)],
            'vvip_elite_only' => 'sometimes|boolean',
            // The Elite Circle column (its own box, then its own awards; null = copy the first column).
            'either_elite' => 'sometimes|boolean',
            'elite_award_segments' => ['sometimes', 'nullable', 'array'],
            'elite_award_segments.*' => ['string', \Illuminate\Validation\Rule::in(self::FRAME_AUDIENCES)],
            // Who can use it, as a list of audiences (see rulesOf()); an empty list makes the frame public.
            'audiences' => ['sometimes', 'nullable', 'array'],
            'audiences.*.awards' => ['present', 'array'],
            'audiences.*.awards.*' => ['string', \Illuminate\Validation\Rule::in(self::FRAME_AUDIENCES)],
            'audiences.*.elite' => ['sometimes', 'boolean'],
            'audiences.*.vvip' => ['sometimes', 'boolean'],
            'vvip_types' => ['sometimes', 'nullable', 'array'],
            'vvip_types.*' => ['string', \Illuminate\Validation\Rule::in(array_keys(\App\Natcon\Models\VvipEntry::TYPES))],
            // The name plate area, all four or none (null clears it).
            'text_x' => 'sometimes|nullable|required_with:text_y,text_w,text_h|numeric|min:0|max:1',
            'text_y' => 'sometimes|nullable|required_with:text_x,text_w,text_h|numeric|min:0|max:1',
            'text_w' => 'sometimes|nullable|required_with:text_x,text_y,text_h|numeric|min:0.02|max:1',
            'text_h' => 'sometimes|nullable|required_with:text_x,text_y,text_w|numeric|min:0.01|max:1',
            // The VVIP title area (the first title line; more lines stack up from it), all four or none.
            'title_x' => 'sometimes|nullable|required_with:title_y,title_w,title_h|numeric|min:0|max:1',
            'title_y' => 'sometimes|nullable|required_with:title_x,title_w,title_h|numeric|min:0|max:1',
            'title_w' => 'sometimes|nullable|required_with:title_x,title_y,title_h|numeric|min:0.02|max:1',
            'title_h' => 'sometimes|nullable|required_with:title_x,title_y,title_w|numeric|min:0.01|max:1',
            // Window edits come as a complete set or not at all — a lone
            // coordinate against three stored ones is meaningless.
            'window_x' => 'sometimes|required_with:window_y,window_w,window_h|numeric|min:0|max:1',
            'window_y' => 'sometimes|required_with:window_x,window_w,window_h|numeric|min:0|max:1',
            'window_w' => 'sometimes|required_with:window_x,window_y,window_h|numeric|min:0.05|max:1',
            'window_h' => 'sometimes|required_with:window_x,window_y,window_w|numeric|min:0.05|max:1',
        ]);

        if (isset($data['window_x'], $data['window_w'], $data['window_y'], $data['window_h'])
            && ($data['window_x'] + $data['window_w'] > 1.0001 || $data['window_y'] + $data['window_h'] > 1.0001)) {
            return response()->json(['message' => 'The photo window falls outside the frame.'], 422);
        }

        if (isset($data['text_x'], $data['text_w'], $data['text_y'], $data['text_h'])
            && ($data['text_x'] + $data['text_w'] > 1.0001 || $data['text_y'] + $data['text_h'] > 1.0001)) {
            return response()->json(['message' => 'The name area falls outside the frame.'], 422);
        }

        if (isset($data['title_x'], $data['title_w'], $data['title_y'], $data['title_h'])
            && ($data['title_x'] + $data['title_w'] > 1.0001 || $data['title_y'] + $data['title_h'] > 1.0001)) {
            return response()->json(['message' => 'The title area falls outside the frame.'], 422);
        }

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        // Elite only means something inside a segment — clearing the segment
        // clears it, and it can't be set on a frame that has none.
        if (array_key_exists('award_segments', $data)) {
            $data['award_segments'] = $this->normaliseSegments($data['award_segments']);
        }

        // The audience list is the whole of "Who can use it": saving it rewrites the older columns so
        // every reader agrees — award_segments stays non-null (the "awardee frame" marker: the awards the
        // rows name, or the nobody marker) and the three-column flags are cleared.
        if (array_key_exists('audiences', $data)) {
            $rules = self::normaliseRules($data['audiences'] ?? []);
            $union = array_values(array_unique(array_merge(...array_map(fn ($r) => $r['awards'], $rules ?: [['awards' => []]]))));
            $data['audiences'] = $rules === [] ? null : $rules;
            $data['award_segments'] = $rules === [] ? null : ($union === [] ? [self::NO_ONE] : $union);
            $data['elite_only'] = false;
            $data['require_vvip'] = false;
            $data['either_vvip'] = false;
            $data['vvip_award_segments'] = null;
            $data['vvip_elite_only'] = false;
            $data['either_elite'] = false;
            $data['elite_award_segments'] = null;
            if ($rules !== []) {
                $data['vvip'] = false;
            }
        }

        // VVIP and award segments are different audiences: turning one on clears the other.
        // A VVIP frame lists what it is for — logo types, category, rank — each optional
        // ("any" when empty); turning VVIP off clears all three.
        if (array_key_exists('vvip_types', $data)) {
            $data['vvip_types'] = $this->normaliseTypes($data['vvip_types']);
        }
        if (! empty($data['vvip']) || ! empty($data['vvip_category'])) {
            $data['vvip'] = true;
            $data['award_segments'] = null;
        } elseif (! empty($data['award_segments'])) {
            $data['vvip'] = false;
        }
        // "VVIP required" belongs to awardee frames only: a VVIP frame already is one, and a frame
        // with no awards is public.
        $isVvipAfter = array_key_exists('vvip', $data) ? (bool) $data['vvip'] : (bool) $frame->vvip;
        $segmentsForVvip = array_key_exists('award_segments', $data) ? $data['award_segments'] : $frame->award_segments;
        if ($isVvipAfter || empty($segmentsForVvip)) {
            $data['require_vvip'] = false;
            $data['either_vvip'] = false;
            $data['either_elite'] = false;
        }
        // "VVIP only" implies the VVIP column is on.
        if (! empty($data['require_vvip'])) {
            $data['either_vvip'] = true;
        }
        // The VVIP column only exists while the VVIP box is ticked.
        $eitherAfter = array_key_exists('either_vvip', $data) ? (bool) $data['either_vvip'] : (bool) $frame->either_vvip;
        $requireAfter = array_key_exists('require_vvip', $data) ? (bool) $data['require_vvip'] : (bool) $frame->require_vvip;
        if (! $eitherAfter && ! $requireAfter) {
            $data['vvip_award_segments'] = null;
            $data['vvip_elite_only'] = false;
        } elseif (array_key_exists('vvip_award_segments', $data)) {
            $data['vvip_award_segments'] = $this->normaliseSegments($data['vvip_award_segments']);
        }
        // The Elite Circle column exists only while its box is ticked.
        $eliteColAfter = array_key_exists('either_elite', $data) ? (bool) $data['either_elite'] : (bool) $frame->either_elite;
        if (! $eliteColAfter) {
            $data['elite_award_segments'] = null;
        } elseif (array_key_exists('elite_award_segments', $data)) {
            $data['elite_award_segments'] = $this->normaliseSegments($data['elite_award_segments']);
        }
        if (array_key_exists('vvip', $data) && ! $data['vvip']) {
            $data['vvip_category'] = null;
            $data['vvip_rank'] = null;
            $data['vvip_types'] = null;
        }
        if (array_key_exists('vvip_category', $data) && empty($data['vvip_category'])) {
            $data['vvip_rank'] = null;
        }
        $segmentsAfter = array_key_exists('award_segments', $data) ? $data['award_segments'] : $frame->award_segments;
        if (empty($segmentsAfter)) {
            $data['elite_only'] = false;
        }

        $frame->auditSource = $this->auditSource(null);
        $frame->fill($data)->save();

        $this->purge($frame->ownerEvent(), $frame->album);

        return response()->json(['data' => $this->presentFrame($frame->fresh())]);
    }

    public function destroyAlbumFrame(Request $request, GalleryAlbumFrame $frame): JsonResponse
    {
        $this->guardScope($request, $frame->ownerEvent());
        abort_if($frame->status === GalleryAlbumFrame::STATUS_DELETED, 404, 'Frame not found.');

        // A status flip, not delete() — the row is the only pointer to the
        // S3 object, same rule as photos.
        $frame->auditSource = $this->auditSource(null);
        $frame->forceFill(['status' => GalleryAlbumFrame::STATUS_DELETED])->save();

        $this->purge($frame->ownerEvent(), $frame->album);

        return response()->json(['message' => 'Frame removed.']);
    }

    /**
     * Live frames offered for photos in $album: its own, plus (FRAMES_INHERIT)
     * every ancestor's — own first, then up the chain, each block in
     * sort_order. One query for the whole set.
     *
     * @return \Illuminate\Support\Collection<int, GalleryAlbumFrame>
     */
    /**
     * @param  bool  $includeSegmented  Segment-reserved frames (award_segments set) are for
     *                                  awardees only — hidden everywhere except the admin tool.
     */
    private function framesFor(GalleryAlbum $album, bool $includeSegmented = false): \Illuminate\Support\Collection
    {
        $ids = [$album->id];
        if (self::FRAMES_INHERIT) {
            foreach ($album->ancestors() as $ancestor) {
                $ids[] = $ancestor->id;
            }
        }

        $rank = array_flip($ids);

        // Convention albums also get the CONVENTION's frames (the ones the
        // admin manages at the "NATCON {year}" root) — after the album chain.
        $eventId = $album->natcon_event_id;

        return GalleryAlbumFrame::query()
            ->where(function ($q) use ($ids, $eventId) {
                $q->whereIn('album_id', $ids);
                if ($eventId !== null) {
                    $q->orWhere('natcon_event_id', $eventId);
                }
            })
            ->live()
            ->when(! $includeSegmented, fn ($q) => $q->whereNull('award_segments')->where('vvip', false))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (GalleryAlbumFrame $f) => $f->album_id !== null ? ($rank[$f->album_id] ?? 98) : 99)
            ->values();
    }

    /** A convention's own live frames, for the root-level "{year} Frames" dialog. */
    public function eventFrames(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);
        abort_if($event === null, 404, 'No NATCON event found.');

        $rows = GalleryAlbumFrame::where('natcon_event_id', $event->id)
            ->live()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (GalleryAlbumFrame $f) => $this->presentFrame($f))]);
    }

    /**
     * The signed-in agent's NATCON award, if any: their FH email matched
     * against the live convention's recipient roster (there is no user_id on a
     * recipient — email is the only key, lowercased on both sides). Only
     * recipients holding an award SEGMENT count; an ordinary Leuterio Realty
     * agent (segment null) is not an "awardee" here. Server-side only — the
     * client never states its own segment.
     *
     * @return array{event: NatconEvent, segment: string, elite: bool, vvip: bool, elite_known: bool, name: string, team: string}|null
     */
    private function awardeeFor(Request $request): ?array
    {
        $email = mb_strtolower(trim((string) $request->user()?->email));
        $event = NatconEvent::active();

        if ($email === '' || ! $event) {
            return null;
        }

        $recipient = \App\Natcon\Models\Recipient::query()
            ->where('natcon_event_id', $event->id)
            ->where('email', $email)
            ->where('status', '!=', \App\Natcon\Models\Recipient::STATUS_EXCLUDED)
            ->first();

        if (! $recipient) {
            return null;
        }

        // Elite is a registration fact (the Elite toggle in the admin's Awardees
        // table, held by the registration service), joined by email. If that
        // service can't be reached the awardee simply isn't treated as elite.
        // Read fresh: a toggle the admin just flipped must show on the very next load.
        $registrants = app(\App\Natcon\Services\NatconRegClient::class)->byEmail((int) $event->year, false, true);
        $row = $registrants[$email] ?? null;

        return [
            'event' => $event,
            // No award on the roster row = a regular awardee, which frames call "top_agent".
            'segment' => (string) ($recipient->award_segment ?? self::REGULAR_AWARDEE),
            'elite' => (bool) ($row['is_elite'] ?? false),
            // On the VVIP list, or marked VVIP in the admin's Awardees table. VVIPs have their
            // own frames, so they are never "regular" awardees.
            'vvip' => (bool) ($row['is_vvip'] ?? false)
                || \App\Natcon\Models\VvipEntry::where('natcon_event_id', $event->id)->where('email', $email)->exists(),
            // false = the registration service couldn't be read (unreachable,
            // or it rejected our service token), so "not elite" is a guess.
            'elite_known' => $registrants !== null,
            // For the awardee frame's name plate. display_name already holds a
            // couple's whole name ("Jessie and Suzan Cruz").
            'name' => \App\Natcon\Http\Controllers\VvipController::ampersand((string) ($recipient->display_name ?: trim($recipient->first_name.' '.$recipient->last_name))),
            'team' => (string) ($recipient->team ?? ''),
        ];
    }

    /** Agent: am I an awardee of the live convention, and in which segment? */
    public function myAwardee(Request $request): JsonResponse
    {
        $a = $this->awardeeFor($request);

        return response()->json(['data' => [
            'is_awardee' => $a !== null,
            'event_id' => $a['event']->id ?? null,
            'year' => $a['event']->year ?? null,
            'award_segment' => $a['segment'] ?? null,
            'is_elite' => $a['elite'] ?? false,
            'elite_known' => $a['elite_known'] ?? true,
            'display_name' => $a['name'] ?? null,
            'team' => ($a['team'] ?? '') !== '' ? $a['team'] : null,
        ]]);
    }

    /**
     * "Who can use it" as a list of audiences, each {awards, elite, vvip}. A person matches a row EXACTLY:
     * their award is one of its awards (none ticked = no award), and they are Elite / VVIP just as the
     * row is ticked. So a row with only Elite ticked is for Elite people with no award — an Elite Global
     * Partner needs their own row with both ticked.
     *
     * Frames saved before the list existed have it derived from the three-column fields: the first
     * column (its awards + Elite, or Regular awardee), the Elite Circle column and the VVIP column.
     *
     * @return array<int, array{awards: array<int, string>, elite: bool, vvip: bool}>
     */
    public static function rulesOf(GalleryAlbumFrame $f): array
    {
        if ($f->audiences !== null) {
            return self::normaliseRules($f->audiences);
        }

        $strip = fn (?array $list) => array_values(array_diff($list ?? [], [self::REGULAR_AWARDEE, self::NO_ONE]));
        $first = $strip($f->award_segments);
        $rules = [];

        if (! $f->require_vvip) {
            if ($first !== []) {
                $rules[] = ['awards' => $first, 'elite' => (bool) $f->elite_only, 'vvip' => false];
            } elseif ($f->elite_only) {
                $rules[] = ['awards' => [], 'elite' => true, 'vvip' => false];
            }
            if (in_array(self::REGULAR_AWARDEE, $f->award_segments ?? [], true)) {
                $rules[] = ['awards' => [], 'elite' => false, 'vvip' => false];
            }
        }
        if ($f->either_elite) {
            $rules[] = ['awards' => $f->elite_award_segments !== null ? $strip($f->elite_award_segments) : $first, 'elite' => true, 'vvip' => false];
        }
        if ($f->either_vvip || $f->require_vvip) {
            $own = $f->vvip_award_segments !== null;
            $rules[] = ['awards' => $own ? $strip($f->vvip_award_segments) : $first, 'elite' => (bool) ($own ? $f->vvip_elite_only : $f->elite_only), 'vvip' => true];
        }

        return self::normaliseRules($rules);
    }

    /**
     * Clean rows: known awards only (no markers), each row once — and ONE award per row. A row that
     * names several awards (an older "either" row, or a hand-made request) is split into one row per
     * award, so "Global Partner or FHI Global" is two rows and every row is a single kind of person.
     */
    private static function normaliseRules(array $rows): array
    {
        $clean = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $awards = array_values(array_unique(array_intersect(
                array_map('strval', (array) ($row['awards'] ?? [])),
                array_diff(self::FRAME_AUDIENCES, [self::REGULAR_AWARDEE, self::NO_ONE]),
            )));
            $elite = (bool) ($row['elite'] ?? false);
            $vvip = (bool) ($row['vvip'] ?? false);
            foreach ($awards === [] ? [[]] : array_map(fn ($a) => [$a], $awards) as $one) {
                $rule = ['awards' => $one, 'elite' => $elite, 'vvip' => $vvip];
                $clean[json_encode($rule)] = $rule;
            }
        }

        return array_values($clean);
    }

    /** Can this person use the frame? They must match one of its audience rows exactly. */
    public static function frameEligible(GalleryAlbumFrame $f, string $segment, bool $personElite, bool $personVvip): bool
    {
        foreach (self::rulesOf($f) as $rule) {
            $awardOk = $rule['awards'] === [] ? $segment === self::REGULAR_AWARDEE : in_array($segment, $rule['awards'], true);
            if ($awardOk && $rule['elite'] === $personElite && $rule['vvip'] === $personVvip) {
                return true;
            }
        }

        return false;
    }

    /** Agent: the awardee frames that fit me — one of each frame's audience rows matched exactly (none → 403). */
    public function myAwardeeFrames(Request $request): JsonResponse
    {
        $a = $this->awardeeFor($request);

        abort_if($a === null, 403, 'Not a NATCON awardee.');

        // Every frame with a row this person matches — as they are now, VVIP included (a VVIP flagged in
        // the admin's table gets the frames whose row ticks VVIP, whether or not they are on the VVIP list).
        $rows = GalleryAlbumFrame::where('natcon_event_id', $a['event']->id)
            ->live()
            ->where('vvip', false)
            ->whereNotNull('award_segments')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (GalleryAlbumFrame $f) => self::frameEligible($f, $a['segment'], $a['elite'], $a['vvip']));

        return response()->json(['data' => $rows->map(fn (GalleryAlbumFrame $f) => $this->presentFrame($f))->values()]);
    }

    /** For the VVIP page: who this login is as far as awardee frames go (their award, Elite flag), even when not on the roster. */
    public function personContext(Request $request): array
    {
        $email = mb_strtolower(trim((string) $request->user()?->email));
        $event = NatconEvent::active();
        $recipient = $event ? \App\Natcon\Models\Recipient::where('natcon_event_id', $event->id)->where('email', $email)->first() : null;
        $registrants = $event ? app(\App\Natcon\Services\NatconRegClient::class)->byEmail((int) $event->year, false, true) : null;

        return [
            'segment' => (string) ($recipient?->award_segment ?? self::REGULAR_AWARDEE),
            'elite' => (bool) (($registrants[$email]['is_elite'] ?? false)),
        ];
    }

    /** Public read of a convention's own live frames, by year. */
    public function publicEventFrames(int $year): JsonResponse
    {
        $event = NatconEvent::forYear($year);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        // General frames only — segment-reserved ones are for awardees.
        $rows = GalleryAlbumFrame::where('natcon_event_id', $event->id)
            ->live()
            ->whereNull('award_segments')
            ->where('vvip', false)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (GalleryAlbumFrame $f) => $this->presentFrame($f))->values()]);
    }

    /** Upload a frame that every photo of the convention can wear. */
    public function storeEventFrame(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);
        abort_if($event === null, 404, 'No NATCON event found.');

        return $this->saveFrame($request, null, $event);
    }

    /**
     * Which of the given PUBLIC album ids offer frames (own or, when
     * inheriting, an ancestor's). Two small queries for the whole batch —
     * this feeds a per-tile boolean, never the frames themselves.
     *
     * @param  array<int, int>  $albumIds
     * @return array<int, bool>
     */
    private function frameFlagsFor(array $albumIds): array
    {
        if ($albumIds === []) {
            return [];
        }

        $withFrames = GalleryAlbumFrame::query()
            ->live()
            ->whereIn('album_id', GalleryAlbum::forEvent(null)->select('id'))
            ->distinct()
            ->pluck('album_id')
            ->flip();

        if ($withFrames->isEmpty()) {
            return array_fill_keys($albumIds, false);
        }

        $parents = GalleryAlbum::forEvent(null)->pluck('parent_id', 'id');

        $flags = [];
        foreach ($albumIds as $id) {
            $flag = false;
            $node = $id;
            for ($i = 0; $i < 20 && $node !== null; $i++) {
                if ($withFrames->has($node)) {
                    $flag = true;
                    break;
                }
                if (! self::FRAMES_INHERIT) {
                    break;
                }
                $node = $parents[$node] ?? null;
            }
            $flags[$id] = $flag;
        }

        return $flags;
    }

    /** VVIP logo types: known keys only, unique; empty → null (= any type). */
    private function normaliseTypes(?array $types): ?array
    {
        $clean = array_values(array_unique(array_intersect(
            array_map('strval', $types ?? []),
            array_keys(\App\Natcon\Models\VvipEntry::TYPES),
        )));

        return $clean === [] ? null : $clean;
    }

    /** A frame's audience as a clean list: known segments only, unique; empty → null (= everyone). */
    private function normaliseSegments(?array $segments): ?array
    {
        $clean = array_values(array_unique(array_intersect(
            array_map('strval', $segments ?? []),
            self::FRAME_AUDIENCES,
        )));

        return $clean === [] ? null : $clean;
    }

    /** presentFrame for other controllers (the VVIP endpoints). */
    public function presentFramePublic(GalleryAlbumFrame $f): array
    {
        return $this->presentFrame($f);
    }

    private function presentFrame(GalleryAlbumFrame $f): array
    {
        return [
            'id' => $f->id,
            'album_id' => $f->album_id,
            // Set instead of album_id for convention-level frames.
            'natcon_event_id' => $f->natcon_event_id,
            // Reserved for this award segment; null = offered to everyone.
            'award_segments' => $f->award_segments ?? [],
            // A VVIP frame: only for people listed under this category (and rank).
            'vvip' => (bool) $f->vvip,
            // An awardee frame that only VVIPs can use (and only if they meet its other conditions).
            'require_vvip' => (bool) $f->require_vvip,
            // The VVIP column of Who can use it (null = it copies the first column).
            'either_vvip' => (bool) $f->either_vvip,
            'vvip_award_segments' => $f->vvip_award_segments,
            'vvip_elite_only' => (bool) $f->vvip_elite_only,
            // The Elite Circle column of Who can use it (null = it copies the first column).
            'either_elite' => (bool) $f->either_elite,
            'elite_award_segments' => $f->elite_award_segments,
            'vvip_types' => $f->vvip_types ?? [],
            'vvip_category' => $f->vvip_category,
            'vvip_rank' => $f->vvip_rank,
            'elite_only' => (bool) $f->elite_only,
            // Who can use it, as the list the admin edits (derived from the older columns until re-saved).
            'audiences' => $f->vvip ? [] : self::rulesOf($f),
            'name' => $f->name,
            'image_url' => $f->image_url,
            'width' => $f->width,
            'height' => $f->height,
            'window' => [
                'x' => (float) $f->window_x,
                'y' => (float) $f->window_y,
                'w' => (float) $f->window_w,
                'h' => (float) $f->window_h,
            ],
            // Where the awardee's name is written (fractions of the frame); null = not set.
            'text_area' => $f->text_x === null ? null : [
                'x' => (float) $f->text_x,
                'y' => (float) $f->text_y,
                'w' => (float) $f->text_w,
                'h' => (float) $f->text_h,
            ],
            // Where a VVIP's titles go (the first line's box; more lines stack up from it); null = not set.
            'title_area' => $f->title_x === null ? null : [
                'x' => (float) $f->title_x,
                'y' => (float) $f->title_y,
                'w' => (float) $f->title_w,
                'h' => (float) $f->title_h,
            ],
            'sort_order' => $f->sort_order,
            'created_at' => $f->created_at?->toIso8601String(),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    // ── Photographer upload invites (admin) ──────────────────────────────────

    /**
     * Invites for the resolved scope, newest first, with what each one has
     * produced. `status` derives 'expired' on read — an aged token needs no
     * sweeper flipping rows.
     */
    public function invites(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);

        $rows = GalleryUploadInvite::forEvent($event)
            ->with('rootAlbum')
            ->withCount([
                'photos' => fn ($q) => $q->where('status', '!=', GalleryPhoto::STATUS_DELETED),
                'albums',
            ])
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (GalleryUploadInvite $i) => $this->presentInvite($i))]);
    }

    public function storeInvite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => 'required|string|max:120',
            'root_album_id' => 'sometimes|nullable|integer',
            'review_required' => 'sometimes|boolean',
            // A wall clock in the EVENT's timezone (datetime-local input) —
            // module rule #4: parse in that tz, store UTC.
            'expires_at' => 'sometimes|nullable|date_format:Y-m-d\TH:i',
        ]);

        $event = $this->resolveEvent($request);
        $rootAlbum = $this->resolveAlbum($event, $data['root_album_id'] ?? null);

        $invite = new GalleryUploadInvite([
            'natcon_event_id' => $event?->id,
            'root_album_id' => $rootAlbum?->id,
            'label' => trim($data['label']),
            'review_required' => (bool) ($data['review_required'] ?? false),
            'created_by' => $request->user()?->id,
        ]);
        $invite->auditSource = 'admin_gallery_invite';
        $invite->save();

        $raw = $this->invites->mintToken($invite);
        $this->applyExpiryOverride($invite, $event, $data, applyNull: false);

        return response()->json(['data' => [
            'invite' => $this->presentInvite($invite->fresh(['rootAlbum'])),
            'url' => $this->invites->buildLink($invite, $raw),
            'expires_at' => $invite->token_expires_at?->toIso8601String(),
        ]], 201);
    }

    public function updateInvite(Request $request, GalleryUploadInvite $invite): JsonResponse
    {
        $this->guardInvite($request, $invite);

        $data = $request->validate([
            'label' => 'sometimes|string|max:120',
            'root_album_id' => 'sometimes|nullable|integer',
            'review_required' => 'sometimes|boolean',
            'expires_at' => 'sometimes|nullable|date_format:Y-m-d\TH:i',
        ]);

        $event = $invite->event;

        if (array_key_exists('root_album_id', $data)) {
            $invite->root_album_id = $this->resolveAlbum($event, $data['root_album_id'])?->id;
        }
        if (array_key_exists('label', $data)) {
            $invite->label = trim($data['label']);
        }
        if (array_key_exists('review_required', $data)) {
            $invite->review_required = (bool) $data['review_required'];
        }

        $invite->auditSource = 'admin_gallery_invite';
        $invite->save();

        $this->applyExpiryOverride($invite, $event, $data, applyNull: true);

        return response()->json(['data' => $this->presentInvite($invite->fresh(['rootAlbum']))]);
    }

    /**
     * "Copy link" — reproduces the CURRENT link (ensureToken never rotates),
     * so handing the same photographer the URL twice keeps both copies alive.
     */
    public function inviteLink(Request $request, GalleryUploadInvite $invite): JsonResponse
    {
        $this->guardInvite($request, $invite);

        $raw = $this->invites->ensureToken($invite);

        return response()->json(['data' => [
            'url' => $this->invites->buildLink($invite, $raw),
            'expires_at' => $invite->token_expires_at?->toIso8601String(),
        ]]);
    }

    /**
     * Rotate the nonce: every previously shared link dies and a fresh one is
     * returned. Doubles as un-revoke — re-issuing a revoked invite is the
     * explicit "give them access again" gesture.
     */
    public function reissueInvite(Request $request, GalleryUploadInvite $invite): JsonResponse
    {
        $this->guardInvite($request, $invite);

        if ($invite->status !== GalleryUploadInvite::STATUS_ACTIVE) {
            $invite->auditSource = 'admin_gallery_invite';
            $invite->forceFill([
                'status' => GalleryUploadInvite::STATUS_ACTIVE,
                'revoked_at' => null,
                'revoked_by' => null,
            ])->save();
        }

        $raw = $this->invites->mintToken($invite);

        return response()->json(['data' => [
            'invite' => $this->presentInvite($invite->fresh(['rootAlbum'])),
            'url' => $this->invites->buildLink($invite, $raw),
            'expires_at' => $invite->token_expires_at?->toIso8601String(),
        ]]);
    }

    /**
     * Kill the link. The photographer's uploads stay, attribution intact —
     * revocation is about access, never about the photos.
     */
    public function revokeInvite(Request $request, GalleryUploadInvite $invite): JsonResponse
    {
        $this->guardInvite($request, $invite);

        $invite->auditSource = 'admin_gallery_invite';
        $invite->forceFill([
            'status' => GalleryUploadInvite::STATUS_REVOKED,
            'revoked_at' => Carbon::now(),
            'revoked_by' => $request->user()?->id,
        ])->save();

        return response()->json(['data' => $this->presentInvite($invite->fresh(['rootAlbum']))]);
    }

    /**
     * One photographer's trail: every upload, re-caption, removal, restore and
     * permanent delete on this link, plus the link's own lifecycle.
     *
     * Built on the audits table rather than a new one. The join that would be
     * obvious — audits for photos whose upload_invite_id is this invite —
     * breaks exactly when the history is most wanted, because a permanently
     * deleted photo has no row left to join to. So GalleryPhoto::generateTags()
     * stamps `invite:{id}` into the audit at write time and this reads that
     * instead; the tag is a copy, so it outlives the photo.
     *
     * Filtering on the indexed `category` first keeps the unindexed `tags`
     * comparison off the whole table.
     */
    public function inviteHistory(Request $request, GalleryUploadInvite $invite): JsonResponse
    {
        $this->guardInvite($request, $invite);

        $perPage = max(1, min(100, (int) $request->input('per_page', 50)));

        $paginated = Audit::query()
            ->where('category', 'gallery')
            ->where(function ($q) use ($invite) {
                $q->where(function ($q) use ($invite) {
                    $q->where('auditable_type', GalleryPhoto::class)
                        ->where('tags', 'invite:'.$invite->id)
                        // Keep only edits to things a PERSON changed. Indexing
                        // a photo's faces writes face_ids/face_count/
                        // faces_indexed_at back onto the row, which audits as
                        // an ordinary update and read as "Edited ·
                        // Photographer" — an edit nobody made, between every
                        // pair of real entries. The four keys below are also
                        // exactly what `changes` surfaces, so anything kept
                        // has something to show.
                        ->where(function ($q) {
                            $q->where('event', '!=', 'updated');
                            foreach (['caption', 'status', 'album_id', 'sort_order'] as $key) {
                                $q->orWhere('new_values', 'like', '%'.$key.'%');
                            }
                        });
                })->orWhere(function ($q) use ($invite) {
                    $q->where('auditable_type', GalleryUploadInvite::class)
                        ->where('auditable_id', $invite->id)
                        // Drop the `last_used_at` touch every upload makes:
                        // it is bookkeeping, and one entry between each photo
                        // buried the uploads this screen exists to show.
                        // Dropped HERE rather than after paging, so the total
                        // the dialog prints is what it actually lists.
                        //
                        // Two shapes, because $auditExclude was added later:
                        // new rows carry an empty diff, older ones carry
                        // last_used_at as their ONLY key — and a lone key has
                        // no comma separating it from a second one (the value
                        // is a timestamp, which has none either). LIKE rather
                        // than a JSON function: new_values is TEXT, and the
                        // tests run on sqlite.
                        ->where(function ($q) {
                            $q->where('event', '!=', 'updated')
                                ->orWhere(function ($q) {
                                    $q->whereNotNull('new_values')
                                        ->whereNotIn('new_values', ['[]', '{}', ''])
                                        ->where(function ($q) {
                                            $q->where('new_values', 'not like', '%last_used_at%')
                                                ->orWhere('new_values', 'like', '%,%');
                                        });
                                });
                        });
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage);

        $rows = ActivityLogController::scrubRows(array_map(
            fn ($m) => $m->toArray(),
            $paginated->items()
        ));

        // The photos still on file, for thumbnails. A row whose photo is gone
        // renders from the audit alone — that IS the permanent-delete entry.
        $photoIds = array_values(array_unique(array_filter(array_map(
            fn ($row) => ($row['auditable_type'] ?? null) === GalleryPhoto::class
                ? (int) $row['auditable_id']
                : null,
            $rows
        ))));

        $photos = $photoIds === []
            ? collect()
            : GalleryPhoto::whereIn('id', $photoIds)->get()->keyBy('id');

        return response()->json([
            'data' => array_map(fn ($row) => $this->presentHistoryRow($row, $photos), $rows),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
        ]);
    }

    /**
     * Turn one audit row into a timeline entry.
     *
     * `kind` is decided here, not in the frontend: reading a status flip as
     * "removed" vs "restored" vs "hidden" means knowing the module's status
     * lifecycle, and that knowledge belongs on this side of the wire.
     */
    private function presentHistoryRow(array $row, \Illuminate\Support\Collection $photos): array
    {
        $old = is_array($row['old_values'] ?? null) ? $row['old_values'] : [];
        $new = is_array($row['new_values'] ?? null) ? $row['new_values'] : [];
        $event = (string) ($row['event'] ?? '');
        $isPhoto = ($row['auditable_type'] ?? null) === GalleryPhoto::class;

        $kind = match (true) {
            ! $isPhoto && $event === 'created' => 'invite_created',
            ! $isPhoto && $event === 'deleted' => 'invite_deleted',
            ! $isPhoto => 'invite_updated',
            $event === 'created' => 'uploaded',
            $event === 'deleted' => 'purged',
            ($new['status'] ?? null) === GalleryPhoto::STATUS_DELETED => 'removed',
            ($old['status'] ?? null) === GalleryPhoto::STATUS_DELETED => 'restored',
            ($new['status'] ?? null) === GalleryPhoto::STATUS_ACTIVE => 'published',
            ($new['status'] ?? null) === GalleryPhoto::STATUS_HIDDEN => 'hidden',
            array_key_exists('caption', $new) => 'caption',
            array_key_exists('album_id', $new) => 'moved',
            default => 'updated',
        };

        $photo = $isPhoto ? $photos->get((int) $row['auditable_id']) : null;

        // A captionless photo has no label of its own, so resolveAuditLabel()
        // falls back to "GalleryPhoto #12" — a class name and a row id, which
        // says nothing the thumbnail beside it doesn't say better.
        $label = $row['subject_label'] ?? null;
        if ($label && preg_match('/^GalleryPhoto #\d+$/', $label)) {
            $label = null;
        }

        $changes = [];
        foreach (['caption', 'status', 'album_id'] as $key) {
            if (array_key_exists($key, $old) || array_key_exists($key, $new)) {
                $changes[$key] = ['from' => $old[$key] ?? null, 'to' => $new[$key] ?? null];
            }
        }

        return [
            'id' => $row['id'] ?? null,
            'kind' => $kind,
            'created_at' => $row['created_at'] ?? null,
            'description' => $row['description'] ?? null,
            'subject_label' => $label,
            'source' => $row['source'] ?? null,
            // A token upload has no user: the actor IS the link.
            'actor' => $row['user_name']
                ?: (($row['source'] ?? null) === 'photographer_invite' ? 'Photographer' : 'System'),
            // (object) or an empty diff ships as `[]` — a list where the
            // client was promised a map. Module rule; see the reactions tally.
            'changes' => (object) $changes,
            'photo' => $photo ? [
                'id' => $photo->id,
                'thumb_url' => $photo->thumb_url ?: $photo->image_url,
                'caption' => $photo->caption,
                'status' => $photo->status,
            ] : null,
        ];
    }

    /**
     * Remove the invite row itself — for a link minted by mistake, a
     * photographer who never turned up, or one the admin simply wants off the
     * list. Revoke stays the right answer for anyone who actually shot: both
     * kill the link, but the upload_invite_id FKs are nullOnDelete, so a
     * delete ALSO drops the attribution that says whose uploads those were
     * (and with it the ownership predicate that let them re-caption their own
     * work). Their photos and albums survive either way — the counts ride back
     * so the admin can be told exactly what the delete cost.
     */
    public function destroyInvite(Request $request, GalleryUploadInvite $invite): JsonResponse
    {
        $this->guardInvite($request, $invite);

        $photos = $invite->photos()
            ->where('status', '!=', GalleryPhoto::STATUS_DELETED)
            ->count();
        $albums = $invite->albums()->count();

        $invite->auditSource = 'admin_gallery_invite';
        $invite->delete();

        return response()->json(['data' => [
            'photos_kept' => $photos,
            'albums_kept' => $albums,
        ]]);
    }

    /** An invite reached by id must belong to the scope being administered. */
    private function guardInvite(Request $request, GalleryUploadInvite $invite): void
    {
        $event = $this->resolveEvent($request);

        abort_unless($invite->natcon_event_id === $event?->id, 404, 'Invite not found.');
    }

    /**
     * Admin-set expiry (a datetime-local wall clock in the event tz) applied
     * AFTER mintToken, which always writes the default. applyNull says whether
     * an explicit null clears the expiry (updates) or is ignored (creates,
     * where absent just means "keep the default").
     */
    private function applyExpiryOverride(GalleryUploadInvite $invite, ?NatconEvent $event, array $data, bool $applyNull): void
    {
        if (! array_key_exists('expires_at', $data)) {
            return;
        }

        if ($data['expires_at'] === null) {
            if ($applyNull) {
                $invite->forceFill(['token_expires_at' => null])->save();
            }

            return;
        }

        $tz = $event?->timezone ?: 'Asia/Manila';
        $invite->forceFill([
            'token_expires_at' => Carbon::parse($data['expires_at'], $tz)->utc(),
        ])->save();
    }

    private function presentInvite(GalleryUploadInvite $i): array
    {
        $expired = $i->status === GalleryUploadInvite::STATUS_ACTIVE
            && $i->token_expires_at
            && $i->token_expires_at->isPast();

        $tz = $i->event?->timezone ?: 'Asia/Manila';

        return [
            'id' => $i->id,
            'label' => $i->label,
            'status' => $expired ? 'expired' : $i->status,
            'review_required' => (bool) $i->review_required,
            'root_album' => $i->rootAlbum ? [
                'id' => $i->rootAlbum->id,
                'name' => $i->rootAlbum->name,
                'path' => $i->rootAlbum->path(),
            ] : null,
            'photos_count' => (int) ($i->photos_count ?? 0),
            'albums_count' => (int) ($i->albums_count ?? 0),
            'expires_at' => $i->token_expires_at?->toIso8601String(),
            // Form-ready wall clock in the event's timezone — edit with THIS,
            // never expires_at (module rule #4).
            'expires_local' => $i->token_expires_at?->copy()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'last_used_at' => $i->last_used_at?->toIso8601String(),
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }

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
            // The gallery is its own page now, not a section of /natcon/{year}.
            $purger->purgeNatconGallery($event->year, $album?->slug);

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
            'section' => $a->section,
            'album_date' => $a->album_date?->toDateString(),
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
            // Convention albums only: `event` (the convention) vs `prep` (the
            // run-up to it), and the day an event album covers. The public
            // gallery renders those as two separate rows; /albums ignores both.
            'section' => $a->section,
            'album_date' => $a->album_date?->toDateString(),
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
                // The album's URL segment, in both scopes — /albums/{slug}
                // and /natcon/gallery/{slug}. Null only for a row written
                // before slugs existed and never edited since.
                'slug' => $p->album->slug,
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
    /**
     * The read routes (gallery list, albums, face search) are open to agents
     * as well as admins/editors for the agent dashboard's gallery. Only an
     * admin or editor is on the EDITING surface and may see hidden photos.
     */
    private function viewerIsAdmin(Request $request): bool
    {
        return in_array($request->user()?->role?->name, ['admin', 'editor'], true);
    }

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
