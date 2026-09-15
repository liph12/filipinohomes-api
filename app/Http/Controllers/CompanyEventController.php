<?php

namespace App\Http\Controllers;

use App\Models\CompanyEvent;
use App\Models\CompanyEventPhoto;
use App\Models\CompanyEventRegistration;
use App\Natcon\Services\LandingCachePurger;
use App\Services\CompanyEventPhotoService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Company Events — the dashboard's event manager (title, description, time,
 * place, pictures). Admin-only: every route sits inside RoleMiddleware:admin —
 * except the PUBLIC PAGE trio at the bottom: publicIndex / publicShow (token-
 * less reads for the site's SSR and Googlebot) and register (guest token +
 * throttle), which collects name, mobile, email, address and a note into
 * company_event_registrations. Sign-ups never leave the admin API.
 *
 * Every write purges the Next cache for /events and /events/{slug} (tags
 * `company-events`, `company-event-{slug}`) through LandingCachePurger.
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
    public function __construct(
        private CompanyEventPhotoService $photos,
        private LandingCachePurger $purger,
    ) {}

    /** GET /admin/events — every live event, soonest-upcoming block first. */
    public function index(): JsonResponse
    {
        $events = CompanyEvent::live()
            ->with('livePhotos')
            ->withCount('registrations')
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
        $this->purger->purgeCompanyEvents($event->slug);

        return response()->json(['data' => $this->present($event)], 201);
    }

    /** PATCH /admin/events/{event} */
    public function update(Request $request, CompanyEvent $event): JsonResponse
    {
        $data = $this->validated($request, creating: false, event: $event);

        $event->fill($data);
        $event->auditSource = 'admin_company_events';
        $event->save();
        $this->purger->purgeCompanyEvents($event->slug);

        return response()->json(['data' => $this->present($event->fresh(['livePhotos'])->loadCount('registrations'))]);
    }

    /** DELETE /admin/events/{event} — a status flip, never delete():
     *  the photos' rows are the only pointers to their S3 objects. */
    public function destroy(CompanyEvent $event): JsonResponse
    {
        $event->status = CompanyEvent::STATUS_DELETED;
        $event->auditSource = 'admin_company_events';
        $event->save();
        $this->purger->purgeCompanyEvents($event->slug);

        return response()->json(['data' => true]);
    }

    /** GET /admin/events/{event}/registrations — every sign-up, newest first. */
    public function registrations(CompanyEvent $event): JsonResponse
    {
        $rows = $event->registrations()->orderByDesc('id')->get();

        return response()->json([
            'data' => $rows->map(fn (CompanyEventRegistration $r) => $this->presentRegistration($r))->values(),
            'meta' => [
                'count' => $rows->count(),
                'capacity' => $event->capacity,
            ],
        ]);
    }

    /** DELETE /admin/events/{event}/registrations/{registration} — a real
     *  delete: this is someone's personal data, and there is nothing to keep. */
    public function destroyRegistration(CompanyEvent $event, CompanyEventRegistration $registration): JsonResponse
    {
        if ($registration->company_event_id !== $event->id) {
            return response()->json(['message' => 'Registration not found.'], 404);
        }
        $registration->delete();
        $this->purger->purgeCompanyEvents($event->slug);

        return response()->json(['data' => true]);
    }

    // ── The public page ─────────────────────────────────────────────────────

    /** GET /events — published events, upcoming soonest-first, then past newest-first. */
    public function publicIndex(): JsonResponse
    {
        $events = CompanyEvent::published()->with('livePhotos')->withCount('registrations')->get();
        [$upcoming, $past] = $events->partition(fn (CompanyEvent $e) => ! $e->isPast());

        return response()->json([
            'data' => $upcoming->sortBy('starts_at')->values()
                ->concat($past->sortByDesc('starts_at')->values())
                ->map(fn (CompanyEvent $e) => $this->presentPublic($e))
                ->values(),
        ]);
    }

    /** GET /events/{slug} */
    public function publicShow(string $slug): JsonResponse
    {
        $event = CompanyEvent::published()->where('slug', $slug)->with('livePhotos')->withCount('registrations')->first();
        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        return response()->json(['data' => $this->presentPublic($event)]);
    }

    /**
     * POST /events/{slug}/register — guest token + throttle. One seat per
     * mobile number per event; every refusal is a 422 with a sentence the form
     * can show as-is. Nothing here may answer 401 (the frontend interceptor
     * would read it as a dead login).
     */
    public function register(Request $request, string $slug): JsonResponse
    {
        $event = CompanyEvent::published()->where('slug', $slug)->first();
        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }
        if (! $event->registration_open) {
            return response()->json(['message' => 'Registration for this event is closed.'], 422);
        }
        if ($event->isPast()) {
            return response()->json(['message' => 'This event has already taken place.'], 422);
        }

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'required|string|max:32',
            'email' => 'nullable|email|max:191',
            'address' => 'required|string|max:255',
            'notes' => 'nullable|string|max:500',
        ], [
            'name.required' => 'Please enter your name.',
            'phone.required' => 'Please enter your mobile number.',
            'address.required' => 'Please enter your address.',
            'email.email' => 'That email address does not look right.',
        ]);

        $phone = $this->normalizePhone($data['phone']);
        if ($phone === null) {
            return response()->json([
                'message' => 'Enter a valid Philippine mobile number, e.g. 0917 123 4567.',
                'errors' => ['phone' => ['Enter a valid Philippine mobile number, e.g. 0917 123 4567.']],
            ], 422);
        }

        $count = $event->registrations()->count();
        if ($event->capacity !== null && $count >= $event->capacity) {
            return response()->json(['message' => 'This event is fully booked.'], 422);
        }
        if ($event->registrations()->where('phone', $phone)->exists()) {
            return response()->json([
                'message' => 'This mobile number is already registered for this event.',
                'errors' => ['phone' => ['This mobile number is already registered for this event.']],
            ], 422);
        }

        $reg = $event->registrations()->create([
            'name' => trim($data['name']),
            'phone' => $phone,
            'email' => isset($data['email']) ? Str::lower(trim($data['email'])) : null,
            'address' => trim($data['address']),
            'notes' => isset($data['notes']) ? trim($data['notes']) : null,
            'ip_hash' => hash('sha256', (string) $request->ip().'|'.(string) config('app.key')),
        ]);
        // The page shows the seat count, so it is stale the moment someone signs up.
        $this->purger->purgeCompanyEvents($event->slug);

        return response()->json([
            'data' => [
                'id' => $reg->id,
                'name' => $reg->name,
                'event' => $event->title,
                'registered' => $count + 1,
                'spots_left' => $event->capacity !== null ? max(0, $event->capacity - $count - 1) : null,
            ],
        ], 201);
    }

    /**
     * PH mobile → 09XXXXXXXXX. Accepts 0917 123 4567, +63 917 123 4567,
     * 63917..., 917.... Anything else is null (the caller answers 422).
     */
    private function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        return preg_match('/^09\d{9}$/', $digits) === 1 ? $digits : null;
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
            'is_public' => 'sometimes|boolean',
            'registration_open' => 'sometimes|boolean',
            'capacity' => 'sometimes|nullable|integer|min:1|max:100000',
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
            'slug' => $e->slug,
            'title' => $e->title,
            'description' => $e->description,
            'place' => $e->place,
            'timezone' => $tz,
            'starts_at' => $e->starts_at?->toIso8601String(),
            'starts_at_local' => $e->starts_at?->clone()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'ends_at' => $e->ends_at?->toIso8601String(),
            'ends_at_local' => $e->ends_at?->clone()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'is_public' => (bool) $e->is_public,
            'registration_open' => (bool) $e->registration_open,
            'capacity' => $e->capacity,
            'registration_count' => $this->registrationCount($e),
            'photo_count' => $photos->count(),
            'photos' => $photos->map(fn (CompanyEventPhoto $p) => $this->presentPhoto($p))->values(),
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }

    /** What the public page gets: facts, photos, and the seat count — no sign-up data. */
    private function presentPublic(CompanyEvent $e): array
    {
        $tz = $e->timezone ?: 'Asia/Manila';
        $photos = $e->relationLoaded('livePhotos') ? $e->livePhotos : $e->livePhotos()->get();
        $count = $this->registrationCount($e);

        return [
            'id' => $e->id,
            'slug' => $e->slug,
            'title' => $e->title,
            'description' => $e->description,
            'place' => $e->place,
            'timezone' => $tz,
            'starts_at' => $e->starts_at?->toIso8601String(),
            'starts_at_local' => $e->starts_at?->clone()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'ends_at' => $e->ends_at?->toIso8601String(),
            'ends_at_local' => $e->ends_at?->clone()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'photos' => $photos->map(fn (CompanyEventPhoto $p) => $this->presentPhoto($p))->values(),
            'registration_open' => (bool) $e->registration_open,
            'capacity' => $e->capacity,
            'registered' => $count,
            'spots_left' => $e->capacity !== null ? max(0, $e->capacity - $count) : null,
            'is_full' => $e->capacity !== null && $count >= $e->capacity,
            'is_past' => $e->isPast(),
        ];
    }

    private function registrationCount(CompanyEvent $e): int
    {
        return isset($e->registrations_count) ? (int) $e->registrations_count : $e->registrations()->count();
    }

    private function presentRegistration(CompanyEventRegistration $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'phone' => $r->phone,
            'email' => $r->email,
            'address' => $r->address,
            'notes' => $r->notes,
            'created_at' => $r->created_at?->toIso8601String(),
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
