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

    /** The convention's awardee roster by email (name and award come from here — the VVIP list keeps only category, rank and email). */
    private function roster(NatconEvent $event)
    {
        return \App\Natcon\Models\Recipient::where('natcon_event_id', $event->id)
            ->where('status', '!=', \App\Natcon\Models\Recipient::STATUS_EXCLUDED)
            ->get()
            ->keyBy('email');
    }

    private function rosterName(?\App\Natcon\Models\Recipient $r): string
    {
        // A couple's name is written "Angie Kay and Marc Godornes" on the roster; the VVIP list and
        // frames use "&" ("Angie Kay & Marc Godornes").
        return $r ? self::ampersand((string) ($r->display_name ?: trim($r->first_name.' '.$r->last_name))) : '';
    }

    /** " and " (any case, as its own word) → " & ". */
    public static function ampersand(string $name): string
    {
        return (string) preg_replace('/\s+and\s+/i', ' & ', $name);
    }

    /**
     * The logos a person carries, DERIVED from their awardee record instead of typed into the
     * list: Elite Circle ← the Elite toggle; RM Pro ← Top Rent Manager Pro OR Top Rent Manager &
     * RM Pro; Global Partners ← Global Partner; FHI Dubai ← FHI Global; Elite Team Leader ← Elite Team Leader. (VVIP itself = being on the list.)
     *
     * @return array<int, string> keys of VvipEntry::TYPES, in display order
     */
    public static function derivedTypes(?\App\Natcon\Models\Recipient $r, bool $elite): array
    {
        $segment = $r?->award_segment;

        return array_values(array_filter([
            $elite ? 'elite_circle' : null,
            in_array($segment, [\App\Natcon\Models\Recipient::SEGMENT_RENT_MANAGER, \App\Natcon\Models\Recipient::SEGMENT_RENT_MANAGER_RM_PRO], true) ? 'rm_pro' : null,
            $segment === \App\Natcon\Models\Recipient::SEGMENT_GLOBAL_PARTNER ? 'global_partners' : null,
            $segment === \App\Natcon\Models\Recipient::SEGMENT_FHI_GLOBAL ? 'fhi_dubai' : null,
            $segment === \App\Natcon\Models\Recipient::SEGMENT_ELITE_TEAM_LEADER ? 'elite_team_leader' : null,
        ]));
    }

    /** @return array<string, array<string, mixed>>|null registration rows by email (null when the service can't be read) */
    private function registrants(NatconEvent $event): ?array
    {
        return app(\App\Natcon\Services\NatconRegClient::class)->byEmail((int) $event->year);
    }

    private function present(VvipEntry $e, $roster = null, ?array $registrants = null): array
    {
        $r = $roster?->get($e->email);

        return [
            'id' => $e->id,
            'category' => $e->category,
            'rank' => $e->rank,
            // From the awardee roster by email (the list itself doesn't store a name).
            'name' => $this->rosterName($r) ?: (string) $e->name,
            'email' => $e->email,
            'types' => self::derivedTypes($r, ! empty($registrants[$e->email]['is_elite'])),
        ];
    }

    /** Admin: everything imported for the convention, in sheet order. */
    public function index(Request $request): JsonResponse
    {
        $event = $this->event($request);

        $rows = VvipEntry::where('natcon_event_id', $event->id)
            ->orderBy('id')
            ->get();
        $roster = $this->roster($event);
        $registrants = $this->registrants($event);

        return response()->json(['data' => $rows->map(fn (VvipEntry $e) => $this->present($e, $roster, $registrants))->values()]);
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
            // Only category, rank and email are kept; a name or logos sent along are ignored (they come from the awardee roster).
            'entries.*.name' => 'nullable|string|max:191',
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
                    'name' => '',
                    'email' => ! empty($row['email']) ? mb_strtolower(trim($row['email'])) : null,
                    'types' => null,
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
            $people[$key]['name'] = $people[$key]['name'] ?? ($this->rosterName($roster->get($e->email)) ?: (string) $e->name);
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

    /**
     * Admin: the saved list, simplified — one row per entry with the person's NAME and LOGOS taken
     * from the awardee roster by email: VVIP (being on the list), Elite Circle (the Elite toggle),
     * RM Pro (Top Rent Manager Pro or Top Rent Manager & RM Pro), Global Partners, FHI Dubai.
     */
    public function simplified(Request $request): JsonResponse
    {
        $event = $this->event($request);
        $roster = $this->roster($event);
        $registrants = $this->registrants($event);

        $rows = VvipEntry::where('natcon_event_id', $event->id)->orderBy('id')->get()->map(function (VvipEntry $e) use ($roster, $registrants) {
            $r = $roster->get($e->email);
            $types = self::derivedTypes($r, ! empty($registrants[$e->email]['is_elite']));

            return [
                'id' => $e->id,
                'category' => $e->category,
                'rank' => $e->rank,
                'email' => $e->email,
                'name' => $this->rosterName($r),
                'on_roster' => (bool) $r,
                'vvip' => true,
                'elite_circle' => in_array('elite_circle', $types, true),
                'rm_pro' => in_array('rm_pro', $types, true),
                'global_partners' => in_array('global_partners', $types, true),
                'fhi_dubai' => in_array('fhi_dubai', $types, true),
                'elite_team_leader' => in_array('elite_team_leader', $types, true),
            ];
        })->values();

        return response()->json(['data' => [
            // false = the registration service couldn't be read, so Elite Circle is unknown (shown unticked).
            'registration_available' => $registrants !== null,
            'rows' => $rows,
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
            'entries' => $entries->map(fn (VvipEntry $e) => $this->present($e, $event ? $this->roster($event) : null, $event ? $this->registrants($event) : null))->values(),
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

        $rows = GalleryAlbumFrame::where('natcon_event_id', $event->id)
            ->live()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // Name and logos come from the person's awardee record (the list keeps only category, rank, email).
        $myEmail = (string) $entries->first()->email;
        $me = $this->roster($event)->get($myEmail);
        $myName = $this->rosterName($me) ?: (string) $entries->first()->name;
        $myTypes = self::derivedTypes($me, ! empty(($this->registrants($event) ?? [])[$myEmail]['is_elite']));

        // 1. VVIP frames — matched on the logos / category / rank the person is listed with.
        $vvipFrames = $rows->where('vvip', true)->map(function (GalleryAlbumFrame $f) use ($entries, $gallery, $myTypes, $myName) {
            // A frame lists what it is for; each filter it leaves empty means "any":
            // category, rank, and the logo types (a person matches if they carry ANY of them).
            $match = $entries->first(fn (VvipEntry $e) => ($f->vvip_category === null || VvipEntry::categoryKey($e->category) === VvipEntry::categoryKey($f->vvip_category))
                && ($f->vvip_rank === null || (int) $f->vvip_rank === (int) $e->rank)
                && (empty($f->vvip_types) || array_intersect($f->vvip_types, $myTypes) !== []));

            return $match ? $gallery->presentFramePublic($f) + ['for_name' => $myName] : null;
        })->filter();

        // 2. Awardee frames marked "VVIP" in Who can use it: for a VVIP who also meets the rest of
        //    their conditions (award, Elite) — and only the most specific of them.
        $ctx = $gallery->personContext($request);
        $shared = GalleryController::mostSpecific(
            $rows->where('vvip', false)
                ->filter(fn (GalleryAlbumFrame $f) => $f->require_vvip && ! empty($f->award_segments))
                ->filter(fn (GalleryAlbumFrame $f) => GalleryController::frameEligible($f, $ctx['segment'], $ctx['elite'], true)),
        )->map(fn (GalleryAlbumFrame $f) => $gallery->presentFramePublic($f) + ['for_name' => $myName]);

        $frames = $vvipFrames->concat($shared)->sortBy('sort_order')->values();

        return response()->json(['data' => $frames]);
    }
}
