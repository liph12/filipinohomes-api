<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GalleryAlbumFrame;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\VvipEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * VVIP honourees: the awards sheet imported per convention (admin), and the
 * signed-in agent's own entries + the frames those entries unlock.
 */
class VvipController extends Controller
{
    private function event(Request $request): NatconEvent
    {
        $event = $request->filled('event_id')
            ? NatconEvent::find($request->integer('event_id'))
            : NatconEvent::active();

        abort_unless($event, 404, 'No NATCON event found.');

        return $event;
    }

    private function present(VvipEntry $e): array
    {
        return [
            'id' => $e->id,
            'category' => $e->category,
            'rank' => $e->rank,
            'name' => $e->name,
            'email' => $e->email,
            // Logo types (several allowed). The title is category + rank, built by the client.
            'types' => $e->types ?? [],
        ];
    }

    /** Admin: everything imported for the convention, in sheet order. */
    public function index(Request $request): JsonResponse
    {
        $event = $this->event($request);

        $rows = VvipEntry::where('natcon_event_id', $event->id)
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (VvipEntry $e) => $this->present($e))->values()]);
    }

    /**
     * Admin: import the parsed sheet. The convention's list is REPLACED — the
     * sheet is the source of truth, so re-importing a corrected file never
     * leaves stale rows behind.
     */
    public function import(Request $request): JsonResponse
    {
        $event = $this->event($request);

        $data = $request->validate([
            'entries' => 'required|array|min:1|max:3000',
            // Free text: whatever the sheet calls the award ("Top Sales Agents").
            'entries.*.category' => 'required|string|max:120',
            'entries.*.rank' => 'nullable|integer|min:1|max:999',
            'entries.*.name' => 'required|string|max:191',
            'entries.*.email' => 'nullable|email|max:191',
            'entries.*.types' => 'nullable|array|max:8',
            'entries.*.types.*' => ['string', Rule::in(array_keys(VvipEntry::TYPES))],
        ]);

        // One row per person per category. The same person may appear in several
        // categories (their titles combine), but not twice in the same one.
        $seen = [];
        foreach ($data['entries'] as $i => $row) {
            $email = ! empty($row['email']) ? mb_strtolower(trim($row['email'])) : null;
            if ($email === null) {
                continue;
            }
            $key = $email.'|'.VvipEntry::categoryKey($row['category']);
            if (isset($seen[$key])) {
                return response()->json([
                    'message' => sprintf('%s is listed twice in %s (rows %d and %d). A person can be in several categories, but only once in each.',
                        $email, trim($row['category']), $seen[$key] + 1, $i + 1),
                ], 422);
            }
            $seen[$key] = $i;
        }

        // Rank is unique within a category as well.
        $ranks = [];
        foreach ($data['entries'] as $i => $row) {
            if (empty($row['rank'])) {
                continue;
            }
            $key = VvipEntry::categoryKey($row['category']).'|'.(int) $row['rank'];
            if (isset($ranks[$key])) {
                return response()->json([
                    'message' => sprintf('Rank %d appears twice in %s (rows %d and %d). Each rank can be used once per category.',
                        (int) $row['rank'], trim($row['category']), $ranks[$key] + 1, $i + 1),
                ], 422);
            }
            $ranks[$key] = $i;
        }

        $now = now();

        DB::transaction(function () use ($event, $data, $now) {
            VvipEntry::where('natcon_event_id', $event->id)->delete();

            foreach (array_chunk($data['entries'], 500) as $chunk) {
                VvipEntry::insert(array_map(fn (array $row) => [
                    'natcon_event_id' => $event->id,
                    'category' => trim($row['category']),
                    'rank' => $row['rank'] ?? null,
                    'name' => trim($row['name']),
                    'email' => ! empty($row['email']) ? mb_strtolower(trim($row['email'])) : null,
                    'types' => ! empty($row['types']) ? json_encode(array_values(array_unique($row['types']))) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });

        return $this->index($request);
    }

    /**
     * Admin: compare the stored VVIP list with the awardee roster and the admin's own
     * VVIP flags (the VVIP toggle in the Awardees table, held by the registration
     * service), joined by email — so the two can be made to agree.
     */
    public function check(Request $request): JsonResponse
    {
        $event = $this->event($request);

        $entries = VvipEntry::where('natcon_event_id', $event->id)->orderBy('id')->get();
        $roster = \App\Natcon\Models\Recipient::where('natcon_event_id', $event->id)
            ->where('status', '!=', \App\Natcon\Models\Recipient::STATUS_EXCLUDED)
            ->get()
            ->keyBy('email');
        $registrants = app(\App\Natcon\Services\NatconRegClient::class)->byEmail((int) $event->year);

        $flagged = collect($registrants ?? [])
            ->filter(fn ($r) => ! empty($r['is_vvip']))
            ->keys()
            ->map(fn ($e) => mb_strtolower((string) $e))
            ->all();

        // Names are compared loosely: case, punctuation, accents and spacing don't matter,
        // and "&" counts as "and" ("Jo-Ann & Albert" = "Jo-Ann and Albert").
        $norm = fn (?string $n) => trim((string) preg_replace(
            ['/\s*&\s*/', '/[^a-z0-9]+/'],
            [' and ', ' '],
            mb_strtolower(\Illuminate\Support\Str::ascii((string) $n)),
        ));
        $titleOf = fn (VvipEntry $e) => trim(($e->rank ? $e->rank.' · ' : '').$e->category);

        // One row per PERSON (email), with every category they are listed under.
        $people = [];
        foreach ($entries as $e) {
            $key = $e->email ?? 'noemail:'.$e->id;
            $people[$key]['name'] = $people[$key]['name'] ?? $e->name;
            $people[$key]['email'] = $e->email;
            $people[$key]['listed'][] = $titleOf($e);
        }

        $sheetOnly = [];
        $nameDiffers = [];
        $matched = 0;

        foreach ($people as $p) {
            $email = $p['email'];
            $listed = implode(', ', $p['listed']);

            if ($email === null) {
                $sheetOnly[] = ['name' => $p['name'], 'email' => null, 'listed' => $listed, 'reason' => 'no_email'];
                continue;
            }

            $recipient = $roster->get($email);
            if (! $recipient) {
                $sheetOnly[] = ['name' => $p['name'], 'email' => $email, 'listed' => $listed, 'reason' => 'not_on_roster'];
                continue;
            }

            if ($registrants !== null && ! in_array($email, $flagged, true)) {
                $sheetOnly[] = ['name' => $p['name'], 'email' => $email, 'listed' => $listed, 'reason' => 'not_vvip_in_admin'];
                continue;
            }

            $matched++;

            $rosterName = (string) ($recipient->display_name ?: trim($recipient->first_name.' '.$recipient->last_name));
            if ($norm($rosterName) !== '' && $norm($rosterName) !== $norm($p['name'])) {
                $nameDiffers[] = ['email' => $email, 'sheet_name' => $p['name'], 'roster_name' => $rosterName];
            }
        }

        $sheetEmails = collect($people)->pluck('email')->filter()->all();
        $adminOnly = [];
        foreach ($flagged as $email) {
            if (in_array($email, $sheetEmails, true)) {
                continue;
            }
            $r = $roster->get($email);
            $adminOnly[] = [
                'email' => $email,
                'name' => $r ? (string) ($r->display_name ?: trim($r->first_name.' '.$r->last_name)) : $email,
                'on_roster' => (bool) $r,
            ];
        }

        return response()->json(['data' => [
            // false = the registration service couldn't be read, so the admin VVIP flags are unknown.
            'registration_available' => $registrants !== null,
            'sheet_people' => count($people),
            'admin_vvip' => count($flagged),
            'matched' => $matched,
            'sheet_only' => $sheetOnly,
            'admin_only' => $adminOnly,
            'name_differs' => $nameDiffers,
        ]]);
    }

    /** Admin: empty the convention's VVIP list. */
    public function clear(Request $request): JsonResponse
    {
        $event = $this->event($request);
        VvipEntry::where('natcon_event_id', $event->id)->delete();

        return response()->json(['data' => []]);
    }

    /** The signed-in agent's entries for the LIVE convention (matched by login email). */
    private function mine(Request $request): array
    {
        $email = mb_strtolower(trim((string) $request->user()?->email));
        $event = NatconEvent::active();

        if ($email === '' || ! $event) {
            return [null, collect()];
        }

        return [$event, VvipEntry::where('natcon_event_id', $event->id)->where('email', $email)->orderBy('id')->get()];
    }

    /** Agent: am I on the VVIP list? */
    public function myEntries(Request $request): JsonResponse
    {
        [$event, $entries] = $this->mine($request);

        return response()->json(['data' => [
            'is_vvip' => $entries->isNotEmpty(),
            'event_id' => $event?->id,
            'year' => $event?->year,
            'entries' => $entries->map(fn (VvipEntry $e) => $this->present($e))->values(),
        ]]);
    }

    /**
     * Agent: the frames my entries unlock — a frame tied to a category (and,
     * when it names one, a rank) is mine when I'm listed under that category at
     * that rank. Each frame also carries the sheet name it was matched on, so
     * the studio can pre-fill it.
     */
    public function myFrames(Request $request, GalleryController $gallery): JsonResponse
    {
        [$event, $entries] = $this->mine($request);

        abort_if($entries->isEmpty(), 403, 'Not on the VVIP list.');

        $frames = GalleryAlbumFrame::where('natcon_event_id', $event->id)
            ->live()
            ->where('vvip', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (GalleryAlbumFrame $f) use ($entries, $gallery) {
                // A frame lists what it is for; each filter it leaves empty means "any":
                // category, rank, and the logo types (a person matches if they carry ANY of them).
                $match = $entries->first(fn (VvipEntry $e) => ($f->vvip_category === null || VvipEntry::categoryKey($e->category) === VvipEntry::categoryKey($f->vvip_category))
                    && ($f->vvip_rank === null || (int) $f->vvip_rank === (int) $e->rank)
                    && (empty($f->vvip_types) || array_intersect($f->vvip_types, $e->types ?? []) !== []));

                return $match ? $gallery->presentFramePublic($f) + ['for_name' => $match->name] : null;
            })
            ->filter()
            ->values();

        return response()->json(['data' => $frames]);
    }
}
