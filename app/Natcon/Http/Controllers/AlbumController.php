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
     * Selfie in, photos out.
     *
     * The probe is re-encoded server-side before it reaches Rekognition: the
     * Image.Bytes API caps at 5MB, and a phone camera original is routinely
     * bigger. 1500px at q85 keeps the (single, large) probe face far above
     * detection size while guaranteeing the limit. The selfie is never stored.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'selfie' => 'required|image|mimes:jpeg,jpg,png,webp|max:15360',
        ]);

        $event = $this->resolveEvent($request);

        $file = $request->file('selfie');
        $info = @getimagesize($file->getRealPath());
        if ($info === false || ($info[0] * $info[1]) > 40_000_000) {
            return response()->json(['message' => 'That file does not look like a usable photo.'], 422);
        }

        $manager = new ImageManager(new Driver);
        $probe = (string) $manager->read($file->getRealPath())
            ->scaleDown(width: 1500, height: 1500)
            ->toJpeg(85);

        try {
            $matches = $this->faces->searchByImage($event, $probe);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

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
            ],
        ]);
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
