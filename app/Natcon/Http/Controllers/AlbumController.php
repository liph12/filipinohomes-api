<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\AlbumPhoto;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Services\AlbumService;
use App\Natcon\Services\FaceRecognitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Event face-search ALBUM, admin side — not GalleryController, which is the
 * public landing page's curated strip (see AlbumPhoto for the split).
 *
 * Routes live behind auth:sanctum + RoleMiddleware:admin alongside the rest of
 * /admin/natcon/*. The album is per-event: every endpoint resolves the event
 * (by slug from the /admin/natcon/{slug} page, by event_id, or the active one)
 * and both storage and search are scoped to it.
 */
class AlbumController extends Controller
{
    public function __construct(
        private AlbumService $album,
        private FaceRecognitionService $faces,
    ) {}

    /** Paginated album, newest first. 20 per page — what the admin grid shows. */
    public function index(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);

        $paginator = AlbumPhoto::where('natcon_event_id', $event->id)
            ->orderByDesc('id')
            ->paginate(min(100, (int) $request->input('per_page', 20)));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,jpg,png,webp|max:'
                .(int) config('natcon.album.max_upload_kb', 25 * 1024),
        ]);

        $event = $this->resolveEvent($request);

        try {
            $photo = $this->album->store($event, $request->file('file'), $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $photo], 201);
    }

    /**
     * Selfies in, photos out. One or several faces:
     *
     *   selfies[] (1-5 files) + mode:
     *     all — photos where EVERY searched face appears together (default).
     *           Set intersection; a photo's score is the MINIMUM similarity
     *           across the faces, because the weakest link is what the claim
     *           "all of them are in this shot" rests on.
     *     any — photos containing ANY of the faces. Union; score is the MAX.
     *
     * Rekognition matches one face per probe image (the largest), so N faces
     * cost N SearchFacesByImage calls; the combining is ours. The legacy
     * single `selfie` field is still accepted and treated as selfies[0].
     *
     * Each probe is re-encoded server-side before it reaches Rekognition: the
     * Image.Bytes API caps at 5MB, and a phone camera original is routinely
     * bigger. 1500px at q85 keeps the (single, large) probe face far above
     * detection size while guaranteeing the limit. Selfies are never stored.
     */
    public function search(Request $request): JsonResponse
    {
        // Back-compat: fold a legacy single-file `selfie` into the array shape.
        // Validated through an explicit Validator with the normalized array —
        // mutating $request->files after the fact is a trap, because Laravel
        // caches the converted file list on first access and the validator
        // would never see the injected key.
        $files = $request->file('selfies')
            ?? array_values(array_filter([$request->file('selfie')]));

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
                // Name the offending probe: "retake photo 2" is actionable,
                // "no face detected" across five inputs is a guessing game.
                return response()->json([
                    'message' => $total > 1
                        ? 'No face could be detected in '.$label.'. Please use a clear, well-lit photo of that person.'
                        : $e->getMessage(),
                ], 422);
            }
        }

        $matches = $this->combine($perFace, $mode);

        // whereIn loses the similarity ordering; reassemble in match order.
        $photos = AlbumPhoto::whereIn('id', array_keys($matches))
            ->get()
            ->keyBy('id');

        $data = [];
        foreach ($matches as $photoId => $similarity) {
            $photo = $photos->get($photoId);
            if (! $photo) {
                continue; // indexed once, deleted since — vectors evicted lazily
            }
            $data[] = array_merge($photo->toArray(), [
                'similarity' => round($similarity, 1),
            ]);
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'matched' => count($data),
                'threshold' => (float) config('natcon.album.match_threshold', 90),
                'mode' => $mode,
                'faces_searched' => $total,
            ],
        ]);
    }

    /**
     * Fold per-face result maps into one photoId=>score map, best score first.
     *
     * @param  array<int, array<int, float>>  $perFace
     * @return array<int, float>
     */
    private function combine(array $perFace, string $mode): array
    {
        if (count($perFace) === 1) {
            return $perFace[0];
        }

        $combined = [];

        if ($mode === 'any') {
            foreach ($perFace as $matches) {
                foreach ($matches as $photoId => $similarity) {
                    $combined[$photoId] = max($combined[$photoId] ?? 0, $similarity);
                }
            }
        } else {
            // 'all': survive only in every face's result set.
            $combined = array_shift($perFace);
            foreach ($perFace as $matches) {
                $next = [];
                foreach ($combined as $photoId => $score) {
                    if (isset($matches[$photoId])) {
                        $next[$photoId] = min($score, $matches[$photoId]);
                    }
                }
                $combined = $next;
                if ($combined === []) {
                    break;
                }
            }
        }

        arsort($combined);

        return $combined;
    }

    public function destroy(AlbumPhoto $photo): JsonResponse
    {
        $this->album->delete($photo);

        return response()->json(['message' => 'Photo deleted.']);
    }

    /** Same contract as AdminController::resolveEvent, plus slug for the album page. */
    private function resolveEvent(Request $request): NatconEvent
    {
        $event = match (true) {
            $request->filled('event_id') => NatconEvent::find($request->integer('event_id')),
            $request->filled('slug') => NatconEvent::where('slug', $request->string('slug'))->first(),
            default => NatconEvent::active(),
        };

        abort_unless($event, 404, 'No NATCON event found.');

        return $event;
    }
}
