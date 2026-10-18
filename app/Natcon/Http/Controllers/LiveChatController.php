<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Services\LiveStreamEmbed;
use App\Natcon\Services\YouTubeLiveChatCollector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin: who commented on the convention's live stream (natcon_live_comments, collected while live).
 * The commenter list, one person's comments, a CSV for the raffle's custom list, and a clear.
 */
class LiveChatController extends Controller
{
    private function event(Request $request): NatconEvent
    {
        $event = $request->filled('event_id') ? NatconEvent::find($request->integer('event_id')) : NatconEvent::active();
        abort_unless($event, 404, 'No NATCON event found.');

        return $event;
    }

    /** Everyone who commented, most comments first, with totals. */
    public function commenters(Request $request): JsonResponse
    {
        $event = $this->event($request);
        $rows = DB::table('natcon_live_comments')
            ->where('natcon_event_id', $event->id)
            ->select('channel_id', DB::raw('MAX(display_name) as display_name'), DB::raw('MAX(avatar_url) as avatar_url'), DB::raw('COUNT(*) as comments'), DB::raw('MIN(published_at) as first_at'), DB::raw('MAX(published_at) as last_at'), DB::raw('MAX(video_id) as video_id'))
            ->groupBy('channel_id')
            ->orderByDesc('comments')
            ->orderBy('first_at')
            ->get();
        $totals = DB::table('natcon_live_comments')->where('natcon_event_id', $event->id)
            ->selectRaw('COUNT(*) as comments, COUNT(DISTINCT channel_id) as commenters, MAX(created_at) as last_collected_at')->first();

        return response()->json(['data' => $rows, 'meta' => [
            'comments' => (int) ($totals->comments ?? 0),
            'commenters' => (int) ($totals->commenters ?? 0),
            'last_collected_at' => $totals->last_collected_at,
            'has_credentials' => (string) config('services.youtube.api_key', '') !== '' || (string) config('services.youtube.refresh_token', '') !== '',
        ]]);
    }

    /** One commenter's messages, oldest first. */
    public function comments(Request $request, string $channelId): JsonResponse
    {
        $event = $this->event($request);
        $rows = DB::table('natcon_live_comments')
            ->where('natcon_event_id', $event->id)->where('channel_id', $channelId)
            ->orderBy('published_at')->limit(500)
            ->get(['id', 'text', 'published_at', 'video_id']);

        return response()->json(['data' => $rows]);
    }

    /** CSV in the raffle sheet's columns (Name | Contact), one line per commenter — import it as the custom list. */
    public function export(Request $request): StreamedResponse
    {
        $event = $this->event($request);
        $rows = DB::table('natcon_live_comments')
            ->where('natcon_event_id', $event->id)
            ->select('channel_id', DB::raw('MAX(display_name) as display_name'), DB::raw('COUNT(*) as comments'), DB::raw('MIN(published_at) as first_at'))
            ->groupBy('channel_id')->orderByDesc('comments')->get();
        $name = "natcon-{$event->year}-live-commenters.csv";

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Contact', 'Comments', 'First comment'], ',', '"', '\\');
            foreach ($rows as $r) {
                fputcsv($out, [$r->display_name, 'https://www.youtube.com/channel/'.$r->channel_id, $r->comments, $r->first_at], ',', '"', '\\');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Read the chat RIGHT NOW (the admin's "Collect now"), instead of waiting for the scheduler's minute.
     * A few pages within a short budget, then the outcome in words — so a stream that is not live, a
     * non-YouTube link or a missing credential is told apart from "nobody has commented yet".
     */
    public function collectNow(Request $request, YouTubeLiveChatCollector $collector): JsonResponse
    {
        $event = $this->event($request);
        $embed = LiveStreamEmbed::for($event->live_url);
        $videoId = $embed['video_id'] ?? null;
        if (($embed['provider'] ?? null) !== 'youtube' || ! $videoId) {
            return response()->json(['data' => ['status' => 'not_youtube_video', 'inserted' => 0]]);
        }

        $deadline = microtime(true) + 8;
        $inserted = 0;
        $status = 'stored';
        do {
            $r = $collector->collect($event, $videoId);
            $inserted += $r['inserted'];
            $status = $r['status'];
            if ($status !== 'stored' || $r['inserted'] === 0) {
                break;
            }
            $sleep = min($r['wait_ms'], 2000);
            if (microtime(true) + $sleep / 1000 >= $deadline) {
                break;
            }
            usleep($sleep * 1000);
        } while (microtime(true) < $deadline);

        return response()->json(['data' => ['status' => $status, 'inserted' => $inserted, 'live_enabled' => (bool) $event->live_enabled, 'message' => $r['message'] ?? null]]);
    }

    /** Wipe the convention's collected comments (a test stream, a restart). */
    public function clear(Request $request): JsonResponse
    {
        $event = $this->event($request);
        $deleted = DB::table('natcon_live_comments')->where('natcon_event_id', $event->id)->delete();

        return response()->json(['data' => ['deleted' => $deleted]]);
    }
}
