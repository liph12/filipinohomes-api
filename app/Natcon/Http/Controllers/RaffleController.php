<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\RaffleWinner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The live raffle's winners (/natcon/admin → Raffle), per convention.
 *
 * The panel draws in the browser (the stage picks the winner; nothing here
 * decides who wins) and reports each draw as it lands — one POST per winner,
 * idempotent on the browser's own key — so a refresh, another laptop or next
 * year's admin all see the same list.
 *
 * `event_id` on every call: omitted means the live convention, which is the
 * public site's default and the wrong one for the admin once the next year
 * is seeded — the panel always sends it (see api.ts, "About eventId").
 */
class RaffleController extends Controller
{
    /** Conventions that have raffle winners, newest first — the "past winners" picker. */
    public function years(): JsonResponse
    {
        $rows = RaffleWinner::query()
            ->select('natcon_event_id', DB::raw('COUNT(*) AS winners'), DB::raw('MAX(drawn_at) AS last_drawn_at'))
            ->groupBy('natcon_event_id')
            ->get()
            ->keyBy('natcon_event_id');

        $events = NatconEvent::whereIn('id', $rows->keys())->orderByDesc('year')->get();

        return response()->json([
            'data' => $events->map(fn (NatconEvent $e) => [
                'event_id' => $e->id,
                'year' => $e->year,
                'name' => $e->name,
                'short_name' => $e->short_name,
                'winners' => (int) $rows[$e->id]->winners,
                'last_drawn_at' => $rows[$e->id]->last_drawn_at,
            ])->values(),
        ]);
    }

    /** One convention's draws, newest first. */
    public function winners(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);

        $rows = RaffleWinner::where('natcon_event_id', $event->id)
            ->orderByDesc('drawn_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'event' => ['id' => $event->id, 'year' => $event->year, 'name' => $event->name],
            'data' => $rows->map(fn (RaffleWinner $w) => $this->present($w)),
        ]);
    }

    /**
     * Record a draw. Idempotent on (event, client_key): the stage may retry a
     * POST that timed out, and a retry must not crown the same draw twice.
     */
    public function storeWinner(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);
        $data = $request->validate([
            'client_key' => ['required', 'string', 'max:64'],
            'raffle' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:191'],
            'contact' => ['nullable', 'string', 'max:191'],
            'prize' => ['nullable', 'string', 'max:191'],
            'drawn_at' => ['required', 'date'],
        ]);

        // Rows are soft-deleted, and (event, raffle, name) is unique across
        // hidden rows too — so a name drawn again after being removed takes
        // its old row back (restored) rather than tripping the index.
        $row = RaffleWinner::withTrashed()->firstOrNew([
            'natcon_event_id' => $event->id,
            'client_key' => $data['client_key'],
        ]);
        if (! $row->exists) {
            $hidden = RaffleWinner::onlyTrashed()
                ->where('natcon_event_id', $event->id)
                ->where('raffle', $data['raffle'])
                ->where('name', $data['name'])
                ->first();
            if ($hidden) {
                $row = $hidden;
            }
        }

        // Once per raffle: the same name under the same title, still live, is
        // refused (say it in words rather than let the unique index throw).
        $repeat = RaffleWinner::where('natcon_event_id', $event->id)
            ->where('raffle', $data['raffle'])
            ->where('name', $data['name'])
            ->when($row->exists, fn ($q) => $q->whereKeyNot($row->id))
            ->exists();
        if ($repeat) {
            return response()->json(['message' => "{$data['name']} already won in “{$data['raffle']}” — one win per person per raffle."], 422);
        }

        $row->fill($data);
        $row->auditSource = 'admin_natcon_raffle';
        if ($row->trashed()) {
            $row->deleted_by = null;
            $row->restore();
        }
        $row->save();

        return response()->json(['data' => $this->present($row)], $row->wasRecentlyCreated ? 201 : 200);
    }

    public function destroyWinner(Request $request, RaffleWinner $winner): JsonResponse
    {
        $winner->auditSource = 'admin_natcon_raffle';
        $winner->removeBy($request->user()?->id);

        return response()->json(['ok' => true]);
    }

    /** Clear a convention's winners — the panel's "Clear", after its two-press arm. */
    public function clearWinners(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);

        $rows = RaffleWinner::where('natcon_event_id', $event->id)->get();
        $rows->each(fn (RaffleWinner $w) => $w->removeBy($request->user()?->id));
        $deleted = $rows->count();

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    private function present(RaffleWinner $w): array
    {
        return [
            'id' => $w->id,
            'client_key' => $w->client_key,
            'raffle' => $w->raffle,
            'name' => $w->name,
            'contact' => $w->contact,
            'prize' => $w->prize ?? '',
            'drawn_at' => $w->drawn_at?->toIso8601String(),
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
