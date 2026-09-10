<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\OrganizerCommittee;
use App\Natcon\Models\OrganizerMember;
use App\Natcon\Services\LandingCachePurger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The organizing-committee chart on the public NATCON "Organizers" page.
 *
 * A committee card is saved WITH its people: every store/update carries the
 * full `members` list and the controller syncs it (update the ids it knows,
 * create the rest, delete what was left out). One dialog, one request, one
 * purge — and a half-saved card can never exist.
 *
 * ⚠️ The public method serves an INDEXABLE page. Everything it returns is copy
 *    the events team wrote for publication: staff names and staff portraits,
 *    never awardee data.
 */
class OrganizerController extends Controller
{
    // ── Public ───────────────────────────────────────────────────────────────

    /**
     * Every committee for one convention year, with its people. Keyed by year
     * because the URL is /natcon/organizers and the page resolves the live
     * year itself. An unknown year is an empty list, not a 404.
     */
    public function index(int $year): JsonResponse
    {
        $event = NatconEvent::forYear($year);

        if (! $event) {
            return response()->json(['data' => []]);
        }

        $rows = OrganizerCommittee::where('natcon_event_id', $event->id)
            ->with('members')
            ->live()
            ->get();

        return response()->json(['data' => $rows->map(fn (OrganizerCommittee $c) => $this->present($c))]);
    }

    // ── Admin ────────────────────────────────────────────────────────────────

    public function adminIndex(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);

        $rows = OrganizerCommittee::where('natcon_event_id', $event->id)
            ->with('members')
            ->orderBy('phase')->orderBy('sort_order')->orderBy('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (OrganizerCommittee $c) => $this->present($c, detailed: true))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateCommittee($request);
        $event = $this->resolveEvent($request);
        $members = $data['members'] ?? [];
        unset($data['members']);

        // New cards go to the end of their phase unless told otherwise.
        if (! array_key_exists('sort_order', $data)) {
            $data['sort_order'] = (int) OrganizerCommittee::where('natcon_event_id', $event->id)
                ->where('phase', $data['phase'])
                ->max('sort_order') + 10;
        }

        $row = DB::transaction(function () use ($data, $event, $members, $request) {
            $row = new OrganizerCommittee($data + ['natcon_event_id' => $event->id]);
            $row->created_by = $request->user()?->id;
            $row->auditSource = 'admin_natcon_organizer';
            $row->save();

            $this->syncMembers($row, $members);

            return $row;
        });

        $this->purge($event->year);

        return response()->json(['data' => $this->present($row->fresh('members'), detailed: true)], 201);
    }

    public function update(Request $request, OrganizerCommittee $committee): JsonResponse
    {
        $data = $this->validateCommittee($request, partial: true);
        $members = $data['members'] ?? null;
        unset($data['members']);

        DB::transaction(function () use ($committee, $data, $members) {
            $committee->auditSource = 'admin_natcon_organizer';
            $committee->fill($data)->save();

            // `members` absent means "leave the people alone" (a reorder PATCH,
            // say); present — even empty — means "this is the whole list".
            if ($members !== null) {
                $this->syncMembers($committee, $members);
            }
        });

        $this->purge($committee->event?->year);

        return response()->json(['data' => $this->present($committee->fresh('members'), detailed: true)]);
    }

    public function destroy(OrganizerCommittee $committee): JsonResponse
    {
        // Read the year BEFORE deleting — the relation is unreachable afterwards.
        $year = $committee->event?->year;

        $committee->auditSource = 'admin_natcon_organizer';
        // Members go with it via the FK cascade.
        $committee->delete();

        $this->purge($year);

        return response()->json(['message' => 'Committee removed.']);
    }

    /**
     * Persist a phase's card order as gapped indexes. The client sends the
     * ids of ONE phase's cards in their new order; rows outside this event are
     * ignored by the scope guard, so nobody can reorder another year's chart.
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|max:200',
            'ids.*' => 'integer|exists:natcon_organizer_committees,id',
        ]);

        $event = $this->resolveEvent($request);

        DB::transaction(function () use ($data, $event) {
            foreach ($data['ids'] as $index => $id) {
                OrganizerCommittee::where('id', $id)
                    ->where('natcon_event_id', $event->id)
                    ->update(['sort_order' => ($index + 1) * 10]);
            }
        });

        $this->purge($event->year);

        return response()->json(['message' => 'Order saved.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function validateCommittee(Request $request, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'phase' => "{$rule}|string|in:".implode(',', OrganizerCommittee::PHASES),
            'title' => "{$rule}|string|max:191",
            'note' => 'sometimes|nullable|string|max:191',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
            'members' => 'sometimes|array|max:60',
            'members.*.id' => 'sometimes|nullable|integer',
            'members.*.role' => 'required|string|in:'.implode(',', OrganizerMember::ROLES),
            'members.*.name' => 'required|string|max:191',
            // Their assignment within the committee — "Entrance" — under the name.
            'members.*.description' => 'sometimes|nullable|string|max:120',
            'members.*.photo_url' => 'sometimes|nullable|url|max:2048',
        ]);
    }

    /**
     * Make the card's people exactly the submitted list, in the submitted
     * order. Ids that belong to this card are updated in place (so a rename
     * keeps its row and its audit trail); unknown or foreign ids are treated
     * as new; anything not in the list is deleted.
     */
    private function syncMembers(OrganizerCommittee $committee, array $members): void
    {
        $existing = $committee->members()->get()->keyBy('id');
        $keep = [];

        foreach ($members as $index => $m) {
            $attrs = [
                'role' => $m['role'],
                'name' => trim($m['name']),
                'description' => isset($m['description']) ? (trim($m['description']) ?: null) : null,
                'photo_url' => $m['photo_url'] ?? null,
                'sort_order' => ($index + 1) * 10,
            ];

            $id = $m['id'] ?? null;
            $row = $id ? $existing->get($id) : null;

            if ($row) {
                $row->auditSource = 'admin_natcon_organizer';
                $row->fill($attrs)->save();
            } else {
                $row = new OrganizerMember($attrs + ['committee_id' => $committee->id]);
                $row->auditSource = 'admin_natcon_organizer';
                $row->save();
            }

            $keep[] = $row->id;
        }

        $committee->members()->whereNotIn('id', $keep)->get()->each(function (OrganizerMember $gone) {
            $gone->auditSource = 'admin_natcon_organizer';
            $gone->delete();
        });
    }

    private function present(OrganizerCommittee $c, bool $detailed = false): array
    {
        $base = [
            'id' => $c->id,
            'phase' => $c->phase,
            'title' => $c->title,
            'note' => $c->note,
            'members' => $c->members->map(fn (OrganizerMember $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'name' => $m->name,
                'description' => $m->description,
                'photo_url' => $m->photo_url,
            ])->values(),
        ];

        return $detailed
            ? $base + ['sort_order' => $c->sort_order]
            : $base;
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

    /** After the save, never guarded by its result — see LandingController::purge. */
    private function purge(?int $year): void
    {
        app(LandingCachePurger::class)->purgeOrganizers($year);
    }
}
