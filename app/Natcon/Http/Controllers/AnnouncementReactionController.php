<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\AnnouncementReaction;
use App\Natcon\Models\NatconAnnouncement;
use App\Natcon\Models\NatconEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Anonymous reactions on the public landing page's announcements.
 *
 * ⚠️ NOT named ReactionController. App\Http\Controllers\ReactionController
 *    already exists for chat messages, and two same-named controllers one
 *    namespace apart is how the wrong one gets imported in a file that touches
 *    both — with PHP raising nothing, because both resolve. Same reasoning as
 *    NatconAnnouncement keeping its prefix.
 *
 * The toggle semantics are lifted from that chat controller, which already
 * describes them correctly: the same key toggles off, a different key replaces
 * the previous one. That is what Facebook does, and it falls out of the unique
 * index on (announcement, visitor).
 */
class AnnouncementReactionController extends Controller
{
    /**
     * Counts for every live announcement of one year, in one aggregate.
     *
     * Public and uncached here on purpose — the frontend proxies this behind a
     * short revalidate window, so api2 sees roughly two of these a minute
     * however busy the page gets. Do NOT let the browser call this directly.
     *
     * Returns counts only, never who reacted: the proxied response is shared by
     * every viewer, so anything visitor-specific in it would leak across people
     * and be wrong for all of them. The tapper's own choice is remembered
     * client-side.
     */
    public function index(int $year): JsonResponse
    {
        $event = NatconEvent::forYear($year);

        if (! $event || ! $event->reactions_enabled) {
            return response()->json(['data' => []]);
        }

        // Scoped to LIVE announcements: a draft or scheduled post is not on the
        // page, so its counts have no business being fetchable from it.
        $ids = NatconAnnouncement::where('natcon_event_id', $event->id)
            ->live()
            ->pluck('id')
            ->all();

        $tally = AnnouncementReaction::tallyFor($ids);

        $data = [];

        foreach ($ids as $id) {
            $counts = $tally[$id] ?? [];
            // Present even at zero, so the client can tell "no reactions yet"
            // from "this announcement is gone".
            $data[(string) $id] = [
                // Cast, or an empty PHP array serialises as [] and the client
                // receives a list where it was promised a map. Harmless for
                // `counts[key]`, fatal for anything reaching for Object.keys —
                // the same shape bug the submissions payload already has.
                'counts' => (object) $counts,
                'total' => array_sum($counts),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Add, switch or remove one visitor's reaction to one announcement.
     *
     * Returns that announcement's fresh counts so the tapper sees the truth
     * immediately instead of waiting out the read proxy's cache window.
     */
    public function store(Request $request, NatconAnnouncement $announcement): JsonResponse
    {
        $data = $request->validate([
            // A key, not an emoji — see AnnouncementReaction::KEYS.
            'reaction' => 'required|string|in:'.implode(',', AnnouncementReaction::KEYS),
            'visitor_id' => 'required|string|max:64',
        ]);

        $event = $announcement->event;

        // Both gates matter. An unpublished or scheduled announcement is not on
        // the page, and a year with reactions switched off must refuse the write
        // and not merely hide the bar.
        abort_unless($event && $event->reactions_enabled, 404, 'Reactions are not available.');
        abort_unless($this->isLive($announcement), 404, 'Announcement not found.');

        $existing = AnnouncementReaction::where('natcon_announcement_id', $announcement->id)
            ->where('visitor_id', $data['visitor_id'])
            ->first();

        $mine = null;

        if ($existing) {
            if ($existing->reaction === $data['reaction']) {
                // Tapping the one you already picked clears it.
                $existing->delete();
            } else {
                $existing->update(['reaction' => $data['reaction'], 'ip' => $request->ip()]);
                $mine = $data['reaction'];
            }
        } else {
            try {
                AnnouncementReaction::create([
                    'natcon_announcement_id' => $announcement->id,
                    'visitor_id' => $data['visitor_id'],
                    'reaction' => $data['reaction'],
                    'ip' => $request->ip(),
                ]);
            } catch (QueryException $e) {
                // Two taps in flight at once: the unique index rejected the
                // second. The visitor's intent is already recorded, so this is a
                // success from their side — re-read and carry on rather than
                // handing a double-click a 500.
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }

                AnnouncementReaction::where('natcon_announcement_id', $announcement->id)
                    ->where('visitor_id', $data['visitor_id'])
                    ->update(['reaction' => $data['reaction']]);
            }

            $mine = $data['reaction'];
        }

        $counts = AnnouncementReaction::tallyFor([$announcement->id])[$announcement->id] ?? [];

        return response()->json(['data' => [
            'announcement_id' => $announcement->id,
            // (object) for the same reason as in index().
            'counts' => (object) $counts,
            'total' => array_sum($counts),
            'mine' => $mine,
        ]]);
    }

    /**
     * Whether this announcement is actually on the public page right now.
     *
     * Re-uses scopeLive rather than re-implementing published/scheduled logic,
     * so the rule can never drift from the feed's own.
     */
    private function isLive(NatconAnnouncement $announcement): bool
    {
        return NatconAnnouncement::whereKey($announcement->id)->live()->exists();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000 covers MySQL's duplicate-entry (1062) integrity violation.
        return $e->getCode() === '23000';
    }
}
