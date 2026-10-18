<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\NatconEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The NATCON Live Contest: contests (several per convention, ONE open at a time, each ended by the
 * admin) and the registrations made from the live page — FULL NAME, MOBILE, GCASH NUMBER (for cash prizes) and EMAIL, the details no
 * platform gives us. A registration belongs to the contest open when it was made; the same mobile or
 * email registers once per contest (same person = same mobile AND email → update; a partial match is
 * refused in words). "Am I registered?" is answered by the server from this browser's visitor id, so
 * the page never trusts a cached flag.
 */
class LiveEntryController extends Controller
{
    // ── Public ─────────────────────────────────────────────────────────────

    /** The contest open right now, for the live page: whether to show Register at all, and what it is called. */
    public function contest(): JsonResponse
    {
        $event = NatconEvent::active();
        $contest = $event ? $this->openContest($event) : null;

        return response()->json(['data' => $contest ? ['open' => true, 'id' => $contest->id, 'title' => $contest->title, 'description' => $contest->description, 'started_at' => $contest->created_at] : ['open' => false]])
            ->header('Cache-Control', 'no-store');
    }

    /** Viewer: register (or update) for the contest open right now. */
    public function store(Request $request): JsonResponse
    {
        $event = NatconEvent::active();
        $contest = $event ? $this->openContest($event) : null;
        abort_unless($contest, 404, 'No contest is open right now. Watch the live page — registration opens when the next contest starts.');

        $data = $request->validate([
            'full_name' => 'required|string|max:191',
            'phone' => 'required|string|min:7|max:32',
            // GCash number for cash prizes — its own number, required.
            'gcash' => 'required|string|min:7|max:32',
            'email' => 'required|email|max:191',
            'visitor_id' => 'nullable|string|max:64',
        ]);
        $phone = self::phoneKey($data['phone']);
        $gcash = self::phoneKey($data['gcash']);
        abort_if(strlen($phone) < 7, 422, 'Please enter a valid mobile number.');
        $email = mb_strtolower(trim($data['email']));
        $now = now();

        $byPhone = DB::table('natcon_live_entries')->where('natcon_live_contest_id', $contest->id)->where('phone', $phone)->first();
        $byEmail = DB::table('natcon_live_entries')->where('natcon_live_contest_id', $contest->id)->where('email', $email)->first();
        if ($byPhone && $byEmail && $byPhone->id === $byEmail->id) {
            $existing = $byPhone;
        } elseif ($byPhone || $byEmail) {
            $field = $byPhone ? 'mobile number' : 'email';
            $other = $byPhone ? 'email' : 'mobile number';
            abort(422, "This {$field} is already registered with a different {$other}. Use the same {$other} you registered with, or a different {$field}.");
        } else {
            $existing = null;
        }

        $row = [
            'full_name' => preg_replace('/\s+/', ' ', trim($data['full_name'])),
            'phone' => $phone,
            'gcash' => $gcash,
            'email' => $email,
            'visitor_id' => $data['visitor_id'] ?? null,
            'updated_at' => $now,
        ];
        if ($existing) {
            DB::table('natcon_live_entries')->where('id', $existing->id)->update($row);
        } else {
            DB::table('natcon_live_entries')->insert($row + ['natcon_event_id' => $event->id, 'natcon_live_contest_id' => $contest->id, 'created_at' => $now]);
        }

        return response()->json(['data' => ['entered' => true, 'updated' => (bool) $existing, 'contest' => $contest->title]]);
    }

    /** Viewer: "am I registered for the open contest?" — by this browser's visitor id; masked details; never cached. */
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate(['visitor_id' => 'required|string|min:8|max:64']);
        $event = NatconEvent::active();
        $contest = $event ? $this->openContest($event) : null;
        $row = $contest ? DB::table('natcon_live_entries')->where('natcon_live_contest_id', $contest->id)->where('visitor_id', $data['visitor_id'])->orderByDesc('updated_at')->first() : null;
        $base = ['open' => (bool) $contest, 'contest' => $contest->title ?? null, 'description' => $contest->description ?? null];
        if (! $row) {
            return response()->json(['data' => $base + ['registered' => false]])->header('Cache-Control', 'no-store');
        }
        $phone = (string) ($row->phone ?? '');
        $email = (string) ($row->email ?? '');

        return response()->json(['data' => $base + [
            'registered' => true,
            'name' => self::nameOf($row),
            'phone' => $phone !== '' ? substr($phone, 0, 4).str_repeat('•', max(0, strlen($phone) - 7)).substr($phone, -3) : null,
            'email' => $email !== '' ? preg_replace('/^(.).*(@.*)$/', '$1•••$2', $email) : null,
            // The same browser's OWN details, unmasked, so the Edit form can be prefilled. This read is keyed
            // by the browser's private visitor id and never cached, so nobody else's row can come back here.
            'details' => ['full_name' => self::nameOf($row), 'phone' => $phone ?: null, 'gcash' => $row->gcash ?: null, 'email' => $email ?: null],
            'registered_at' => $row->created_at,
        ]])->header('Cache-Control', 'no-store');
    }

    // ── Admin: contests ────────────────────────────────────────────────────

    /** Every contest of the convention, newest first, with its registration count; the open one flagged. */
    public function contests(Request $request): JsonResponse
    {
        $event = $this->event($request);
        $rows = DB::table('natcon_live_contests as c')
            ->leftJoin('natcon_live_entries as e', 'e.natcon_live_contest_id', '=', 'c.id')
            ->where('c.natcon_event_id', $event->id)
            ->select('c.id', 'c.title', 'c.description', 'c.created_at', 'c.started_at', 'c.ended_at', DB::raw('COUNT(e.id) as registrations'))
            ->groupBy('c.id', 'c.title', 'c.description', 'c.created_at', 'c.started_at', 'c.ended_at')
            ->orderByDesc('c.created_at')
            ->get()
            ->map(fn ($c) => (array) $c + ['status' => self::statusOf($c), 'open' => self::statusOf($c) === 'live', 'registrations' => (int) $c->registrations]);
        $legacy = DB::table('natcon_live_entries')->where('natcon_event_id', $event->id)->whereNull('natcon_live_contest_id')->count();

        return response()->json(['data' => $rows->values(), 'meta' => ['open_id' => $rows->firstWhere('open', true)['id'] ?? null, 'uncategorised' => $legacy]]);
    }

    /** Create a contest as a DRAFT — always allowed. Going live is the separate startContest() step. */
    public function storeContest(Request $request): JsonResponse
    {
        $event = $this->event($request);
        // The description is what the Register modal shows above its button, so a contest cannot exist without one.
        $data = $request->validate(['title' => 'required|string|max:191', 'description' => 'required|string|max:2000']);
        $id = DB::table('natcon_live_contests')->insertGetId([
            'natcon_event_id' => $event->id,
            'title' => trim($data['title']),
            'description' => trim($data['description']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => DB::table('natcon_live_contests')->find($id)], 201);
    }

    /** Edit a contest's title and description — at any stage; the live page picks the new words up at once. */
    public function updateContest(Request $request, int $contest): JsonResponse
    {
        $event = $this->event($request);
        $row = DB::table('natcon_live_contests')->where('natcon_event_id', $event->id)->where('id', $contest)->first();
        abort_unless($row, 404, 'Contest not found.');
        $data = $request->validate(['title' => 'required|string|max:191', 'description' => 'required|string|max:2000']);
        DB::table('natcon_live_contests')->where('id', $contest)->update([
            'title' => trim($data['title']), 'description' => trim($data['description']), 'updated_at' => now(),
        ]);

        return response()->json(['data' => DB::table('natcon_live_contests')->find($contest)]);
    }

    /** Go live with a draft. ONE live at a time: refused while another is live (end it first). */
    public function startContest(Request $request, int $contest): JsonResponse
    {
        $event = $this->event($request);
        $row = DB::table('natcon_live_contests')->where('natcon_event_id', $event->id)->where('id', $contest)->first();
        abort_unless($row, 404, 'Contest not found.');
        abort_if($row->ended_at !== null, 422, 'This contest has ended. Create a new one to run it again.');
        $live = $this->openContest($event);
        if ($live !== null && $live->id !== $contest) {
            abort(422, "\"{$live->title}\" is live right now. End it before going live with another contest.");
        }
        if ($row->started_at === null) {
            DB::table('natcon_live_contests')->where('id', $contest)->update(['started_at' => now(), 'updated_at' => now()]);
        }

        return response()->json(['data' => DB::table('natcon_live_contests')->find($contest)]);
    }

    /** End a live contest: registration closes, its pool is frozen, and another may go live. */
    public function endContest(Request $request, int $contest): JsonResponse
    {
        $event = $this->event($request);
        $row = DB::table('natcon_live_contests')->where('natcon_event_id', $event->id)->where('id', $contest)->first();
        abort_unless($row, 404, 'Contest not found.');
        if ($row->ended_at === null) {
            DB::table('natcon_live_contests')->where('id', $contest)->update(['ended_at' => now(), 'updated_at' => now()]);
        }

        return response()->json(['data' => DB::table('natcon_live_contests')->find($contest)]);
    }

    /** Delete a contest and every registration made for it. Deleting the OPEN one closes registration too. */
    public function destroyContest(Request $request, int $contest): JsonResponse
    {
        $event = $this->event($request);
        $row = DB::table('natcon_live_contests')->where('natcon_event_id', $event->id)->where('id', $contest)->first();
        abort_unless($row, 404, 'Contest not found.');
        $registrations = DB::table('natcon_live_entries')->where('natcon_live_contest_id', $contest)->delete();
        DB::table('natcon_live_contests')->where('id', $contest)->delete();

        return response()->json(['data' => ['deleted' => true, 'registrations' => $registrations]]);
    }

    // ── Admin: registrations ───────────────────────────────────────────────

    /** The registrations of one contest (`contest_id`; default the open one, else the newest), newest first. */
    public function index(Request $request): JsonResponse
    {
        $event = $this->event($request);
        $contest = $this->contestFor($request, $event);
        $rows = $this->entriesQuery($event, $contest)->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => (array) $r + ['name' => self::nameOf($r)])->values(),
            'meta' => ['entries' => $rows->count(), 'contest' => $contest ? ['id' => $contest->id, 'title' => $contest->title, 'open' => self::statusOf($contest) === 'live', 'status' => self::statusOf($contest)] : null],
        ]);
    }

    /** CSV for the raffle sheet (Name | Contact = "mobile | email") plus when they registered — one contest. */
    public function export(Request $request): StreamedResponse
    {
        $event = $this->event($request);
        $contest = $this->contestFor($request, $event);
        $rows = $this->entriesQuery($event, $contest)->orderBy('created_at')->get();
        $slug = $contest ? '-'.Str::slug($contest->title) : '';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Contact', 'GCash', 'Registered at'], ',', '"', '\\');
            foreach ($rows as $r) {
                // Contact = "mobile | email", one cell, exactly as the admin table shows it and the raffle sheet reads it.
                fputcsv($out, [self::nameOf($r), implode(' | ', array_filter([$r->phone, $r->email])), $r->gcash, $r->created_at], ',', '"', '\\');
            }
            fclose($out);
        }, "natcon-{$event->year}-contest{$slug}-registrations.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Wipe one contest's registrations. */
    public function clear(Request $request): JsonResponse
    {
        $event = $this->event($request);
        $contest = $this->contestFor($request, $event);

        return response()->json(['data' => ['deleted' => $this->entriesQuery($event, $contest)->delete()]]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** The LIVE contest: started by the admin and not yet ended. Drafts (not started) are not open. */
    private function openContest(NatconEvent $event): ?object
    {
        return DB::table('natcon_live_contests')->where('natcon_event_id', $event->id)->whereNotNull('started_at')->whereNull('ended_at')->orderByDesc('id')->first();
    }

    private static function statusOf(object $c): string
    {
        return $c->ended_at !== null ? 'ended' : ($c->started_at !== null ? 'live' : 'draft');
    }

    /** The contest an admin read is about: `contest_id` when given, else the live one, else the newest. Null = none yet. */
    private function contestFor(Request $request, NatconEvent $event): ?object
    {
        if ($request->filled('contest_id')) {
            $c = DB::table('natcon_live_contests')->where('natcon_event_id', $event->id)->where('id', $request->integer('contest_id'))->first();
            abort_unless($c, 404, 'Contest not found.');

            return $c;
        }

        return $this->openContest($event) ?? DB::table('natcon_live_contests')->where('natcon_event_id', $event->id)->orderByDesc('id')->first();
    }

    private function entriesQuery(NatconEvent $event, ?object $contest)
    {
        $q = DB::table('natcon_live_entries')->where('natcon_event_id', $event->id);

        return $contest ? $q->where('natcon_live_contest_id', $contest->id) : $q->whereNull('natcon_live_contest_id');
    }

    private function event(Request $request): NatconEvent
    {
        $event = $request->filled('event_id') ? NatconEvent::find($request->integer('event_id')) : NatconEvent::active();
        abort_unless($event, 404, 'No NATCON event found.');

        return $event;
    }

    /** The one name shown: the full-name box, else the older first/middle/last fields joined. */
    private static function nameOf(object $r): string
    {
        return $r->full_name ?: trim(implode(' ', array_filter([$r->first_name ?? null, $r->middle_name ?? null, $r->last_name ?? null])));
    }

    /** "0917 123 4567", "+63 917-123-4567" and "09171234567" are one number: digits only, PH +63 folded to 0. */
    private static function phoneKey(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }
}
