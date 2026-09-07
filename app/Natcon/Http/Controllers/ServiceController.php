<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\Recipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-to-server reads for natcon-api-v2 (behind verify.service.token).
 *
 * ─── The two-backend situation ───────────────────────────────────────────────
 *
 * natcon-api-v2 owns NATCON *registrations* and will eventually own every
 * natcon_* table. Until that migration happens, THIS repo stays the source of
 * truth for events and the awardee roster, and v2 reads them over HTTP —
 * event facts via the public GET /api/natcon/event, the roster via this
 * controller. Do not add registration tables here; they belong in v2.
 *
 * ─── Privacy posture ─────────────────────────────────────────────────────────
 *
 * Modeled on PublicProfileResource, not RecipientResource: no phone, no
 * reg_id, no lr_payload, no photos. v2 does not need them — the registration
 * form asks the awardee for their own contact details — and every field
 * omitted here is a field a leak on the OTHER service cannot expose.
 */
class ServiceController extends Controller
{
    /**
     * The awardee roster for one convention year.
     *
     * Resolved through NatconEvent::forYear(), never a year literal on the
     * recipients table (rule #1 of this module: per-year data lives on
     * natcon_events). Excluded recipients are the admin saying "not this
     * person" — that decision travels to v2 by omission.
     */
    public function awardees(Request $request): JsonResponse
    {
        $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
        ]);

        $event = NatconEvent::forYear($request->integer('year'));

        if (! $event) {
            return response()->json(['message' => 'No NATCON event for that year.'], 404);
        }

        $rows = Recipient::where('natcon_event_id', $event->id)
            ->where('status', '!=', Recipient::STATUS_EXCLUDED)
            ->orderBy('id')
            ->get()
            ->map(fn (Recipient $r) => [
                'lr_awardee_id' => $r->lr_awardee_id,
                'email'         => $r->email,
                'display_name'  => $r->displayName(),
                // Pre-split server-side: personNames() owns the couple rules
                // (118 of 292 are couples on one login) and v2 must not grow
                // a second copy of that split.
                'person_names'  => $r->personNames(),
                'team'          => $r->team,
                // LR's team art, straight from the stored qualifier record —
                // the registration page shows it beside the team name. Empty
                // string means "no logo"; normalised to null.
                'team_logo'     => data_get($r->qualifier_payload, 'sales_team_member.sales_team.teamlogo') ?: null,
                'state'         => $r->state,
            ])
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'event' => [
                    'id'   => $event->id,
                    'year' => $event->year,
                    'slug' => $event->slug,
                    'name' => $event->name,
                ],
                'total' => $rows->count(),
            ],
        ]);
    }
}
