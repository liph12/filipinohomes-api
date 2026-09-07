<?php

namespace App\Http\Controllers;

use App\Models\CompanyEvent;
use App\Models\CompanyEventPhoto;
use App\Services\CompanyEventPhotoService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Company Events — the dashboard's event manager (title, description, time,
 * place, pictures). Admin-only: every route sits inside RoleMiddleware:admin.
 *
 * ─── Timestamps (the house rule) ─────────────────────────────────────────────
 * config('app.timezone') is UTC. The dashboard form posts
 * <input type="datetime-local"> values — wall clocks in the EVENT's timezone —
 * so writes go through Carbon::parse($value, $tz)->utc(), and every response
 * carries a *_local (Y-m-d\TH:i) twin for the form to read back. A bare
 * Carbon::parse() here is the walked-deadline bug, third edition.
 */
class CompanyEventController extends Controller
{
    public function __construct(private CompanyEventPhotoService $photos) {}

    /** GET /admin/events — every live event, soonest-upcoming block first. */
    public function index(): JsonResponse
    {
        $events = CompanyEvent::live()
            ->with('livePhotos')
            ->orderByDesc('starts_at')
            ->get();

        return response()->json([
            'data' => $events->map(fn (CompanyEvent $e) => $this->present($e))->values(),
        ]);
    }

    /** POST /admin/events */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, creating: true);

        $event = new CompanyEvent(array_merge($data, [
            'status' => CompanyEvent::STATUS_ACTIVE,
            'created_by' => $request->user()?->id,
        ]));
        $event->auditSource = 'admin_company_events';
        $event->save();

        return response()->json(['data' => $this->present($event)], 201);
    }

    /** PATCH /admin/events/{event} */
    public function update(Request $request, CompanyEvent $event): JsonResponse
    {
        $data = $this->validated($request, creating: false, event: $event);

        $event->fill($data);
        $event->auditSource = 'admin_company_events';
        $event->save();

        return response()->json(['data' => $this->present($event->fresh(['livePhotos']))]);
    }

    /** DELETE /admin/events/{event} — a status flip, never delete():
     *  the photos' rows are the only pointers to their S3 objects. */
    public function destroy(CompanyEvent $event): JsonResponse
    {
        $event->status = CompanyEvent::STATUS_DELETED;
        $event->auditSource = 'admin_company_events';
        $event->save();

        return response()->json(['data' => true]);
    }

    /** POST /admin/events/photos — one file per request (the gallery upload
     *  convention: per-file progress, no post_max_size cliffs). */
    public function storePhoto(Request $request): JsonResponse
    {
        $data = $request->validate([
            'photo' => 'required|file|mimes:jpeg,jpg,png,webp|max:15360',
            'event_id' => 'required|integer',
        ], [
            'photo.max' => 'Each photo must be 15MB or smaller.',
        ]);

        $event = CompanyEvent::live()->find((int) $data['event_id']);
        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        try {
            $photo = $this->photos->store($event, $request->file('photo'), $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->presentPhoto($photo)], 201);
    }

    /** DELETE /admin/events/photos/{photo} — status flip, same reason. */
    public function destroyPhoto(CompanyEventPhoto $photo): JsonResponse
    {
        $photo->status = CompanyEventPhoto::STATUS_DELETED;
        $photo->auditSource = 'admin_company_events';
        $photo->save();

        return response()->json(['data' => true]);
    }

    /**
     * Shared validation + wall-clock parsing. On create everything is
     * required; on update fields arrive piecemeal (PATCH).
     */
    private function validated(Request $request, bool $creating, ?CompanyEvent $event = null): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'title' => $req.'|string|max:160',
            'description' => 'sometimes|nullable|string|max:5000',
            'place' => $req.'|string|max:255',
            'starts_at' => $req.'|date_format:Y-m-d\TH:i',
            'ends_at' => 'sometimes|nullable|date_format:Y-m-d\TH:i',
            'timezone' => 'sometimes|string|timezone|max:64',
        ]);

        $tz = $data['timezone'] ?? $event?->timezone ?? 'Asia/Manila';

        if (array_key_exists('starts_at', $data)) {
            $data['starts_at'] = Carbon::parse($data['starts_at'], $tz)->utc();
        }
        if (array_key_exists('ends_at', $data)) {
            $data['ends_at'] = $data['ends_at'] === null
                ? null
                : Carbon::parse($data['ends_at'], $tz)->utc();
        }

        $starts = $data['starts_at'] ?? $event?->starts_at;
        $ends = array_key_exists('ends_at', $data) ? $data['ends_at'] : $event?->ends_at;
        if ($starts && $ends && $ends->lessThan($starts)) {
            abort(response()->json(['message' => 'The end time must be after the start time.'], 422));
        }

        return $data;
    }

    private function present(CompanyEvent $e): array
    {
        $tz = $e->timezone ?: 'Asia/Manila';
        $photos = $e->relationLoaded('livePhotos') ? $e->livePhotos : $e->livePhotos()->get();

        return [
            'id' => $e->id,
            'title' => $e->title,
            'description' => $e->description,
            'place' => $e->place,
            'timezone' => $tz,
            'starts_at' => $e->starts_at?->toIso8601String(),
            'starts_at_local' => $e->starts_at?->clone()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'ends_at' => $e->ends_at?->toIso8601String(),
            'ends_at_local' => $e->ends_at?->clone()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'photo_count' => $photos->count(),
            'photos' => $photos->map(fn (CompanyEventPhoto $p) => $this->presentPhoto($p))->values(),
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }

    private function presentPhoto(CompanyEventPhoto $p): array
    {
        return [
            'id' => $p->id,
            'image_url' => $p->image_url,
            'thumb_url' => $p->thumb_url,
            'width' => $p->width,
            'height' => $p->height,
            'sort_order' => $p->sort_order,
        ];
    }
}
