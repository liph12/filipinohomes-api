<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Services\LandingCachePurger;
use App\Natcon\Services\LiveStreamEmbed;
use Carbon\Carbon;
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

    /** Add a replay by hand — a session streamed elsewhere, or one the save never saw. */
    public function store(Request $request): JsonResponse
    {
        $event = $this->event($request);
        $data = $request->validate($this->rules());
        $url = trim($data['url']);
        $hash = hash('sha256', $url);
        abort_if(DB::table('natcon_live_replays')->where('natcon_event_id', $event->id)->where('url_hash', $hash)->exists(), 422, 'That link is already in the list.');
        $when = $this->when($event, $data['happened_at'] ?? null) ?? now();
        DB::table('natcon_live_replays')->insert([
            'natcon_event_id' => $event->id,
            'url' => $url,
            'url_hash' => $hash,
            'title' => trim((string) ($data['title'] ?? '')) ?: null,
            'started_at' => $when,
            'ended_at' => $when,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(LandingCachePurger::class)->purgeLive();

        return response()->json(['data' => $event->liveReplays(includeCurrent: true)], 201);
    }

    /** Rename, re-link or re-date a replay. */
    public function update(Request $request, int $replay): JsonResponse
    {
        $event = $this->event($request);
        $data = $request->validate(array_map(fn ($r) => array_merge(['sometimes'], (array) $r), $this->rules()));
        $row = DB::table('natcon_live_replays')->where('natcon_event_id', $event->id)->where('id', $replay)->first();
        abort_if($row === null, 404);
        $set = ['updated_at' => now()];
        if (array_key_exists('title', $data)) {
            $set['title'] = trim((string) ($data['title'] ?? '')) ?: null;
        }
        if (array_key_exists('url', $data)) {
            $url = trim($data['url']);
            $hash = hash('sha256', $url);
            abort_if(
                DB::table('natcon_live_replays')->where('natcon_event_id', $event->id)->where('url_hash', $hash)->where('id', '!=', $replay)->exists(),
                422,
                'Another replay already uses that link.',
            );
            $set['url'] = $url;
            $set['url_hash'] = $hash;
        }
        if (array_key_exists('happened_at', $data)) {
            $when = $this->when($event, $data['happened_at']);
            if ($when !== null) {
                $set['started_at'] = $when;
                $set['ended_at'] = $when;
            }
        }
        DB::table('natcon_live_replays')->where('id', $replay)->update($set);
        app(LandingCachePurger::class)->purgeLive();

        return response()->json(['data' => $event->liveReplays(includeCurrent: true)]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'url' => [
                'required', 'string', 'url', 'max:2048',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! LiveStreamEmbed::isSupported((string) $value)) {
                        $fail('Only YouTube and Facebook links can be shown. Paste a youtube.com, youtu.be, facebook.com or fb.watch link.');
                    }
                },
            ],
            'title' => 'nullable|string|max:191',
            // A wall-clock time in the event's timezone (datetime-local), like the other admin dates.
            'happened_at' => 'nullable|date',
        ];
    }

    private function when(NatconEvent $event, ?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value, $event->timezone ?: 'Asia/Manila')->utc() : null;
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
