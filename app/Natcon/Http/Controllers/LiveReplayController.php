<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Services\LandingCachePurger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The admin's view of the live page's REPLAYS — the convention's earlier streams, recorded by the
 * event save itself (AdminController::recordLiveReplay) when a stream ends or the link changes.
 * Here the admin can name one ("Day 1 — Awards night") or drop one (a test link).
 */
class LiveReplayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $event = $this->event($request);

        return response()->json(['data' => $event->liveReplays(includeCurrent: true)]);
    }

    public function update(Request $request, int $replay): JsonResponse
    {
        $event = $this->event($request);
        $data = $request->validate(['title' => 'nullable|string|max:191']);
        $row = DB::table('natcon_live_replays')->where('natcon_event_id', $event->id)->where('id', $replay)->first();
        abort_if($row === null, 404);
        DB::table('natcon_live_replays')->where('id', $replay)->update(['title' => trim((string) ($data['title'] ?? '')) ?: null, 'updated_at' => now()]);
        app(LandingCachePurger::class)->purgeLive();

        return response()->json(['data' => $event->liveReplays(includeCurrent: true)]);
    }

    public function destroy(Request $request, int $replay): JsonResponse
    {
        $event = $this->event($request);
        DB::table('natcon_live_replays')->where('natcon_event_id', $event->id)->where('id', $replay)->delete();
        app(LandingCachePurger::class)->purgeLive();

        return response()->json(['data' => $event->liveReplays(includeCurrent: true)]);
    }

    private function event(Request $request): NatconEvent
    {
        $id = (int) $request->query('event_id', 0);
        $event = $id > 0 ? NatconEvent::find($id) : NatconEvent::active();
        abort_if($event === null, 404, 'No convention found.');

        return $event;
    }
}
