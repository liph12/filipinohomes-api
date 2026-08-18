<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Http\Resources\RecipientResource;
use App\Natcon\Models\FormSubmission;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\Outbox;
use App\Natcon\Models\PhotoSubmission;
use App\Natcon\Models\Recipient;
use OwenIt\Auditing\Models\Audit;
use App\Natcon\Models\Suppression;
use App\Natcon\Services\AwardeeService;
use App\Natcon\Services\FormService;
use App\Natcon\Services\InviteService;
use App\Natcon\Services\PhotoService;
use App\Natcon\Services\RecipientImportService;
use App\Natcon\Services\Sources\LrQualifiersSource;
use App\Natcon\Services\Sources\ManualListSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin surface for the NATCON campaign.
 *
 * Registered inside the existing ['auth:sanctum','agent.active'] +
 * RoleMiddleware:admin group, so authorization matches every other admin route.
 *
 * ⚠️ Nothing here sends email. `sendInvites` writes natcon_outbox rows and
 *    returns in well under a second; natcon:drain-outbox does the sending.
 *    That split is deliberate — see DrainOutbox's docblock for why an
 *    inline send behind Cloudflare double-mails everyone.
 */
class AdminController extends Controller
{
    public function __construct(
        private InviteService $invites,
        private AwardeeService $awardees,
        private RecipientImportService $importer,
        private FormService $forms,
    ) {}

    // ── Event ────────────────────────────────────────────────────────────────

    /**
     * Every convention year, shaped for the admin's edit form.
     *
     * ─── Why this is not just ->get() ────────────────────────────────────────
     * It was, and two fields arrived unusable because a raw model serialises for
     * machines while this payload feeds HTML inputs:
     *
     *  · photo_deadline_at is stored UTC and config('app.timezone') is UTC, so it
     *    came back as 15:59 for a deadline the admin had typed as 23:59. The form
     *    put that straight into the input, and saving again re-read it as Manila
     *    wall-clock — so every save walked the deadline eight hours earlier.
     *    photo_deadline_local is the same instant in the event's own timezone,
     *    which is the only thing the admin ever typed or should see.
     *
     *  · starts_on / ends_on are `date` casts and serialise as full ISO
     *    datetimes. <input type="date"> accepts ONLY Y-m-d and silently renders
     *    nothing else, which is why both fields looked empty on an event that
     *    has had dates all along.
     *
     * ⚠️ array_merge, not `+`. The union operator keeps the LEFT operand's keys,
     *    so `$e->toArray() + [...]` would quietly discard every override here.
     */
    public function events(): JsonResponse
    {
        $events = NatconEvent::orderByDesc('id')->get()->map(fn (NatconEvent $e) => array_merge(
            $e->toArray(),
            [
                'starts_on' => $e->starts_on?->format('Y-m-d'),
                'ends_on'   => $e->ends_on?->format('Y-m-d'),
                // Form-ready wall clock. photo_deadline_at stays alongside it as
                // the true instant, for anything that needs to compare moments.
                'photo_deadline_local' => $e->deadlineLocal()?->format('Y-m-d\TH:i'),
            ],
        ));

        return response()->json(['data' => $events]);
    }

    /**
     * Start a new convention year.
     *
     * Clones the previous year's questions by default — that is the entire
     * reason the form schema is per-event rather than global. Without this,
     * "NATCON for all years" is theoretical: someone would have to hand-write
     * a seed migration every August.
     *
     * Deliberately does NOT deactivate the previous year. Two conventions can
     * legitimately overlap while one is winding down, and NatconEvent::active()
     * resolves newest-first, so the new one takes over on its own.
     */
    public function storeEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year'               => 'required|integer|min:2000|max:2100|unique:natcon_events,year',
            'name'               => 'required|string|max:255',
            'short_name'         => 'nullable|string|max:64',
            'starts_on'          => 'required|date',
            'ends_on'            => 'required|date|after_or_equal:starts_on',
            'venue'              => 'required|string|max:255',
            'hashtag'            => 'nullable|string|max:64',
            'timezone'           => 'nullable|string|max:64|timezone',
            'photo_deadline_at'  => 'nullable|date',
            'reminder_offsets'   => 'nullable|array|max:10',
            'reminder_offsets.*' => 'integer|min:0|max:60',
            'banner_base'        => 'nullable|string|max:255',
            'email_banner_url'   => 'nullable|url|max:2048',
            'thank_you_message'  => 'nullable|string|max:512',
            'copy_fields_from'   => 'nullable|integer|exists:natcon_events,id',
        ]);

        $year     = (int) $data['year'];
        $tz       = $data['timezone'] ?? 'Asia/Manila';
        $previous = NatconEvent::orderByDesc('year')->first();

        $event = DB::transaction(function () use ($data, $year, $tz, $previous, $request) {
            $event = NatconEvent::create([
                'slug'               => 'natcon-' . $year,
                'year'               => $year,
                'name'               => $data['name'],
                'short_name'         => $data['short_name'] ?? ('NATCON ' . $year),
                'starts_on'          => $data['starts_on'],
                'ends_on'            => $data['ends_on'],
                'venue'              => $data['venue'],
                'hashtag'            => $data['hashtag'] ?? ('#LRNATCON' . $year),
                'timezone'           => $tz,
                // Entered as wall-clock time in the event's own timezone, because
                // a deadline like "the 24th" is a Manila date, not a UTC one.
                'photo_deadline_at'  => isset($data['photo_deadline_at'])
                    ? Carbon::parse($data['photo_deadline_at'], $tz)->utc()
                    : null,
                'update_profile_url' => $previous?->update_profile_url
                    ?: 'https://filipinohomes.com/natcon/update-profile',
                'reminder_offsets'   => $data['reminder_offsets'] ?? ($previous?->reminder_offsets ?? [4, 3, 2]),
                'banner_base'        => $data['banner_base'] ?? "/images/natcon-{$year}/natcon{$year}",
                'email_banner_url'   => $data['email_banner_url'] ?? null,
                'thank_you_message'  => $data['thank_you_message'] ?? null,
                'is_active'          => true,
            ]);

            $source = isset($data['copy_fields_from'])
                ? NatconEvent::find($data['copy_fields_from'])
                : $previous;

            if ($source) {
                foreach ($this->forms->fields($source, activeOnly: false) as $field) {
                    $event->formFields()->create($field->only([
                        'key', 'label', 'help_text', 'type', 'is_required', 'is_active',
                        'sort_order', 'illustration_url', 'choices', 'config', 'section',
                    ]));
                }
            }

            return $event;
        });

        $this->forms->forgetSchema($event);

        return response()->json([
            'data' => $event->fresh(),
            'meta' => [
                'copied_fields_from' => $previous?->year,
                'note'               => "Upload this year's banner images to public{$event->banner_base} before sending invites.",
            ],
        ], 201);
    }

    public function updateEvent(Request $request, NatconEvent $event): JsonResponse
    {
        $data = $request->validate([
            'name'               => 'sometimes|string|max:255',
            'short_name'         => 'sometimes|nullable|string|max:64',
            'starts_on'          => 'sometimes|date',
            'ends_on'            => 'sometimes|date|after_or_equal:starts_on',
            'venue'              => 'sometimes|string|max:255',
            'hashtag'            => 'sometimes|nullable|string|max:64',
            'timezone'           => 'sometimes|string|max:64|timezone',
            'update_profile_url' => 'sometimes|url|max:255',
            'reminder_offsets'   => 'sometimes|array|max:10',
            'reminder_offsets.*' => 'integer|min:0|max:60',
            'banner_base'        => 'sometimes|nullable|string|max:255',
            'email_banner_url'   => 'sometimes|nullable|url|max:2048',
            'thank_you_message'  => 'sometimes|nullable|string|max:512',
            'is_active'          => 'sometimes|boolean',
            // Accepted as a wall-clock time in the event's timezone, because
            // "the 24th" is a Manila date. Converted to UTC below.
            'photo_deadline_at'  => 'sometimes|date',
        ]);

        if (isset($data['photo_deadline_at'])) {
            $tz = $data['timezone'] ?? $event->timezone ?? 'Asia/Manila';
            $data['photo_deadline_at'] = Carbon::parse($data['photo_deadline_at'], $tz)->utc();
        }

        $event->fill($data)->save();

        return response()->json(['data' => $event->fresh()]);
    }

    public function stats(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);
        $base  = Recipient::where('natcon_event_id', $event->id);

        $byStatus = (clone $base)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return response()->json(['data' => [
            'total'          => (clone $base)->count(),
            'pending'        => (int) ($byStatus[Recipient::STATUS_PENDING] ?? 0),
            'invited'        => (clone $base)->whereNotNull('invited_at')->count(),
            'opened'         => (clone $base)->whereNotNull('first_opened_at')->count(),
            'responded'      => (clone $base)->whereNotNull('responded_at')->count(),
            'retained'       => (clone $base)->where('response', Recipient::RESPONSE_RETAIN)->count(),
            'changed'        => (clone $base)->where('response', Recipient::RESPONSE_CHANGE)->count(),
            'photo_uploaded' => (clone $base)->whereNotNull('photo_uploaded_at')->count(),
            'form_submitted' => (clone $base)->whereNotNull('form_submitted_at')->count(),
            // The "needs a human" bucket: LR has no record, or the lookup errored.
            'no_lr_record'   => (clone $base)->where('lr_lookup_status', Recipient::LR_NOT_FOUND)->count(),
            'lr_pending'     => (clone $base)->whereIn('lr_lookup_status', [Recipient::LR_PENDING, Recipient::LR_ERROR])->count(),
            'no_photo'       => (clone $base)->whereNull('lr_primary_photo')
                                    ->where(fn ($q) => $q->whereNull('lr_photos')->orWhere('lr_photos', '[]'))
                                    ->whereNull('current_photo_url')->count(),
            'excluded'       => (int) ($byStatus[Recipient::STATUS_EXCLUDED] ?? 0),
            // Started but not finished. The bucket that only exists because the
            // event asks for several photos — these people are NOT responded and
            // are still being reminded, and without a number for them "2 of 6
            // confirmed" hides the fact that three more are half done.
            // Genuinely part-way through the PHOTOS. Excludes details_pending
            // explicitly: those people have sent every picture and are only
            // missing a required answer, and counting them here would report a
            // photo problem the events team does not have.
            'photos_partial' => (clone $base)->whereNull('responded_at')
                                    ->whereHas('activePhotos')
                                    ->where('status', '!=', Recipient::STATUS_DETAILS_PENDING)
                                    ->count(),
            'details_pending' => (int) ($byStatus[Recipient::STATUS_DETAILS_PENDING] ?? 0),
            'photos_required' => Recipient::requiredPhotoCount(),
            // Awardees a reviewer has told to re-shoot.
            'requires_new_photo' => (clone $base)->where('requires_new_photo', true)->count(),
            'sales_tiers'    => $this->salesTiers($request, $event, clone $base),
            'queued_to_send' => Outbox::where('natcon_event_id', $event->id)
                                    ->where('status', Outbox::STATUS_QUEUED)->count(),
            'deadline_at'    => $event->deadlineLocal()?->toIso8601String(),
            'days_remaining' => max(0, (int) ($event->daysUntilDeadline() ?? 0)),
            'send_mode'      => (string) config('natcon.send_mode'),
            // Which convention these numbers belong to. The admin spans years
            // now, so the heading and the export filename read this rather than
            // hardcoding one.
            'event'          => [
                'id'         => $event->id,
                'year'       => $event->year,
                'slug'       => $event->slug,
                'name'       => $event->name,
                'short_name' => $event->displayShortName(),
                'is_active'  => (bool) $event->is_active,
            ],
        ]]);
    }

    /**
     * Where each invite wave stands.
     *
     * `total` is who is in the band; `sent` is who has actually been emailed;
     * `pending` is who pressing Send right now would email. That last one is the
     * number an admin needs before a wave — a band of 127 that has already gone
     * out and a band of 127 that has not look identical until you separate them.
     *
     * `sent` reads invited_at rather than status, so it stays honest for anyone
     * who has since responded, been excluded, or been put through the reset
     * tool — reset clears invited_at, and the count going back down is correct.
     */
    private function salesTiers(Request $request, NatconEvent $event, $base): array
    {
        $breakpoint = $request->filled('breakpoint')
            ? (float) $request->input('breakpoint')
            : $event->salesBreakpoint();

        $band = fn ($apply) => [
            'total'   => (int) $apply(clone $base)->count(),
            'sent'    => (int) $apply(clone $base)->whereNotNull('invited_at')->count(),
            'pending' => (int) $apply(clone $base)->where('status', Recipient::STATUS_PENDING)->count(),
        ];

        return [
            'breakpoint' => $breakpoint,
            // The real minimum, so the lower band can be LABELLED with it rather
            // than hardcoding LR's current floor into the UI.
            'floor'      => (float) ((clone $base)->min('total_sales') ?? 0),
            'all'        => $band(fn ($q) => $q),
            'high'       => $band(fn ($q) => $q->where('total_sales', '>=', $breakpoint)),
            'low'        => $band(fn ($q) => $q->whereNotNull('total_sales')->where('total_sales', '<', $breakpoint)),
            'none'       => $band(fn ($q) => $q->whereNull('total_sales')),
        ];
    }

    // ── Recipients ───────────────────────────────────────────────────────────

    public function recipients(Request $request)
    {
        $event = $this->resolveEvent($request);

        $query = Recipient::with('event')
            ->where('natcon_event_id', $event->id);

        if ($search = trim((string) $request->input('search'))) {
            $like = '%' . $search . '%';
            $query->where(fn ($q) => $q
                ->where('email', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('team', 'like', $like)
                ->orWhere('reg_id', 'like', $like));
        }

        // Filters on the qualifier ROSTER, not on how the row was added — see
        // RecipientResource::is_qualifier for why those differ.
        if ($request->filled('qualifier')) {
            $request->boolean('qualifier')
                ? $query->whereNotNull('qualifier_payload')
                : $query->whereNull('qualifier_payload');
        }

        foreach (['status', 'response', 'lr_lookup_status', 'team', 'source'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        // Same band as the send path, so the list is a truthful preview of it.
        $this->applySalesBand($query, $request);

        if ($request->filled('requires_new_photo')) {
            $query->where('requires_new_photo', $request->boolean('requires_new_photo'));
        }

        // "Who is stuck part-way?" — at least one photo, not yet enough of them.
        // Expressed against responded_at rather than a count, so it stays in step
        // with PhotoService::syncResponseState() if the requirement changes.
        if ($request->input('photo_progress') === 'partial') {
            $query->whereNull('responded_at')->whereHas('activePhotos');
        }

        if ($request->filled('has_photo')) {
            $request->boolean('has_photo')
                ? $query->where(fn ($q) => $q->whereNotNull('lr_primary_photo')->orWhereNotNull('current_photo_url'))
                : $query->whereNull('lr_primary_photo')->whereNull('current_photo_url');
        }

        $sort = in_array($request->input('sort'), ['created_at', 'email', 'responded_at', 'invited_at'], true)
            ? $request->input('sort')
            : 'id';

        $query->orderBy($sort, $request->input('dir') === 'asc' ? 'asc' : 'desc');

        $paginator = $query->paginate(min(100, (int) $request->input('per_page', 25)));

        return response()->json([
            'data' => RecipientResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
        ]);
    }

    public function showRecipient(Recipient $recipient): JsonResponse
    {
        return response()->json([
            'data' => RecipientResource::detailed($recipient->load('event')),
        ]);
    }

    /** Add one or many addresses. Paste-friendly: accepts a raw blob or an array. */
    public function storeRecipients(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_id' => 'nullable|integer|exists:natcon_events,id',
            'emails'   => 'required_without:text|array|max:2000',
            'emails.*' => 'string|max:255',
            'text'     => 'required_without:emails|string|max:200000',
            'dry_run'  => 'boolean',
        ]);

        $event  = $this->resolveEvent($request);
        $source = isset($data['text'])
            ? ManualListSource::fromText($data['text'])
            : new ManualListSource($data['emails'], 'manual');

        $result = $this->importer->import(
            $event,
            $source,
            $request->user()?->id,
            (bool) ($data['dry_run'] ?? false),
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Pull the awardee roster from Leuterio Realty's qualifiers list.
     *
     * The list is authoritative for WHO qualified and what to call them; it
     * carries no photos, so natcon:hydrate-awardees still fills those in
     * afterwards from get-awardee/{email}.
     *
     * Updates existing rows as well as creating new ones — the whole reason this
     * exists is that the admin was full of bare email addresses that nobody could
     * put a name to. Only LR-owned fields are refreshed; see the guard in
     * RecipientImportService.
     *
     * Rows that are NOT in the list are deliberately left exactly as they are.
     * They are hand-added test addresses and one-off invitees, and the admin
     * tells them apart by `source` rather than by having them quietly removed.
     */
    public function syncQualifiers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_id' => 'nullable|integer|exists:natcon_events,id',
            'dry_run'  => 'boolean',
        ]);

        $event  = $this->resolveEvent($request);
        $dryRun = (bool) ($data['dry_run'] ?? false);

        try {
            $source = new LrQualifiersSource();
        } catch (\RuntimeException $e) {
            // Their outage is not our 500. The message is written to be shown to
            // an admin as-is.
            return response()->json(['message' => $e->getMessage(), 'code' => 'lr_unavailable'], 502);
        }

        $result = $this->importer->import(
            $event,
            $source,
            $request->user()?->id,
            $dryRun,
            updateExisting: true,
        );

        if (! $dryRun) {
            // One audit row per sync, in the feed an admin already reads. The
            // import itself writes ~285 rows; this is the record of who asked.
            Audit::create([
                'event'          => 'natcon',
                'auditable_type' => NatconEvent::class,
                'auditable_id'   => $event->id,
                'user_type'      => $request->user() ? get_class($request->user()) : null,
                'user_id'        => $request->user()?->id,
                'new_values'     => [
                    'source'     => 'admin_sync_qualifiers',
                    'fetched'    => $source->count(),
                    'created'    => $result['created'],
                    'updated'    => $result['updated'],
                    'skipped'    => $result['skipped'],
                    'suppressed' => $result['suppressed'],
                    'invalid'    => count($result['invalid']),
                ],
            ]);
        }

        return response()->json([
            'data' => $result + ['fetched' => $source->count()],
            'meta' => [
                'dry_run' => $dryRun,
                // Photos are the one thing this list cannot supply.
                'note'    => 'Names and teams are set immediately. Photos arrive as natcon:hydrate-awardees works through the list.',
            ],
        ]);
    }

    public function updateRecipient(Request $request, Recipient $recipient): JsonResponse
    {
        $data = $request->validate([
            'notes'  => 'nullable|string|max:1000',
            'status' => 'nullable|in:pending,invited,excluded',
        ]);

        if (array_key_exists('notes', $data)) {
            $recipient->notes = $data['notes'];
        }

        if (! empty($data['status'])) {
            $recipient->status = $data['status'];
        }

        $recipient->save();

        return response()->json(['data' => new RecipientResource($recipient->fresh('event'))]);
    }

    public function destroyRecipient(Recipient $recipient): JsonResponse
    {
        // Kill the token as well as the row: a soft delete alone would leave a
        // working link in an already-delivered email.
        $recipient->forceFill(['token_nonce' => null, 'invite_token_hash' => null])->save();
        $recipient->delete();

        return response()->json(['message' => 'Recipient removed.']);
    }

    public function refreshLr(Recipient $recipient): JsonResponse
    {
        $this->awardees->hydrate($recipient, true);

        return response()->json(['data' => new RecipientResource($recipient->fresh('event'))]);
    }

    /**
     * Mint a fresh link for support ("I never got the email"). Rotates the token,
     * so any previously emailed link stops working — which is the point.
     */
    public function issueLink(Recipient $recipient): JsonResponse
    {
        $raw = $this->invites->mintToken($recipient->load('event'));

        // Audited so there's a record of who generated a working link to somebody
        // else's record.
        $recipient->auditSource      = 'admin_issue_link';
        $recipient->auditDescription = 'Issued a new NATCON invite link';
        $recipient->save();

        return response()->json(['data' => [
            'url'        => $this->invites->buildLink($recipient, $raw),
            'expires_at' => $recipient->token_expires_at?->toIso8601String(),
        ]]);
    }

    /**
     * Put one recipient back to pending so they can be invited again.
     *
     * ─── Why this endpoint exists ───────────────────────────────────────────
     * natcon_outbox has UNIQUE(recipient, kind, send_date). That index is what
     * makes a double-clicked Send button, a cron double-fire and the axios 401
     * replay all collapse to exactly one email — but it equally blocks a
     * DELIBERATE re-send on the same day. Until now the only way to re-run the
     * invite flow against a test address was an artisan tinker session on the
     * production box, which is both slow and the sort of thing that eventually
     * gets run against the wrong rows.
     *
     * The outbox rows are DELETED rather than cancelled. A cancelled row still
     * occupies its (recipient, kind, send_date) slot, so the re-send would be
     * silently skipped and this would appear to do nothing.
     */
    public function resetRecipient(Request $request, Recipient $recipient): JsonResponse
    {
        $data = $request->validate([
            // Photos and answers cost the awardee real effort to re-supply, so
            // clearing them is opt-in rather than part of what "reset" means.
            'clear_submissions' => 'sometimes|boolean',
        ]);

        $clear = (bool) ($data['clear_submissions'] ?? false);

        DB::transaction(function () use ($recipient, $clear) {
            Outbox::where('natcon_recipient_id', $recipient->id)->delete();

            $fields = [
                'status'             => Recipient::STATUS_PENDING,
                'invited_at'         => null,
                'last_reminded_at'   => null,
                'reminders_sent'     => 0,
                'first_opened_at'    => null,
                'open_count'         => 0,
                'response'           => null,
                'responded_at'       => null,
                'retained_photo_url' => null,
                'send_failures'      => 0,
                'last_error'         => null,
            ];

            if ($clear) {
                // Superseded, not deleted: the S3 object stays put and the row
                // stays auditable. Deleting would orphan the file in the bucket
                // with nothing left pointing at it to clean up later.
                PhotoSubmission::where('natcon_recipient_id', $recipient->id)
                    ->where('status', PhotoSubmission::STATUS_ACTIVE)
                    ->update(['status' => PhotoSubmission::STATUS_SUPERSEDED]);

                FormSubmission::where('natcon_recipient_id', $recipient->id)->delete();

                $fields['current_photo_url'] = null;
                $fields['photo_uploaded_at'] = null;
                $fields['form_submitted_at'] = null;
            }

            // The token is deliberately NOT rotated. Re-sending mints the same
            // derived link, so leaving it alone means a tester can re-open the
            // email they already have instead of waiting for the new one.
            $recipient->auditSource      = 'admin_reset_recipient';
            $recipient->auditDescription = $clear
                ? 'Reset NATCON recipient, including photo and form submissions'
                : 'Reset NATCON recipient send history and response';

            $recipient->forceFill($fields)->save();
        });

        return response()->json([
            // ::detailed, matching showRecipient — the drawer that calls this
            // renders the full record and would lose half its fields otherwise.
            'data'    => RecipientResource::detailed($recipient->fresh()->load('event')),
            'message' => $clear
                ? 'Reset. Send history, response, photo and answers cleared.'
                : 'Reset. Send history and response cleared; photo and answers kept.',
        ]);
    }

    // ── Sending ──────────────────────────────────────────────────────────────

    /**
     * Dry-run of a send: exactly who would receive it and who wouldn't, with
     * reasons. No side effects — this is what the admin sees before confirming.
     */
    public function preflight(Request $request): JsonResponse
    {
        $event  = $this->resolveEvent($request);
        $kind   = $request->input('kind', Outbox::KIND_INVITE);
        $target = $this->targetQuery($request, $event, $kind);

        $recipients = (clone $target)->get();
        $today      = Carbon::now($event->timezone ?: 'Asia/Manila')->toDateString();

        $alreadyClaimed = Outbox::where('natcon_event_id', $event->id)
            ->where('kind', $kind)
            ->where('send_date', $today)
            ->pluck('natcon_recipient_id')
            ->flip();

        $suppressed = Suppression::lookup($recipients->pluck('email')->all());

        $willSend = $skip = [];

        foreach ($recipients as $r) {
            $reason = match (true) {
                isset($suppressed[$r->email])       => 'Suppressed (bounce/complaint/unsubscribe)',
                $alreadyClaimed->has($r->id)        => 'Already queued or sent today',
                $r->status === Recipient::STATUS_EXCLUDED => 'Excluded',
                $kind === Outbox::KIND_REMINDER && $r->responded_at !== null => 'Already responded',
                default => null,
            };

            $reason === null
                ? $willSend[] = $r
                : $skip[] = ['email' => $r->email, 'reason' => $reason];
        }

        // Surfaced separately because it changes the COPY, not just the count:
        // these people get the "we don't have a photo of you yet" variant.
        $noPhoto = collect($willSend)->filter(fn ($r) => count($r->displayPhotos()) === 0);

        return response()->json(['data' => [
            'kind'          => $kind,
            'will_send'     => count($willSend),
            'skipped'       => $skip,
            'no_photo'      => $noPhoto->count(),
            'no_lr_record'  => collect($willSend)->where('lr_lookup_status', Recipient::LR_NOT_FOUND)->count(),
            'lr_unresolved' => collect($willSend)->whereIn('lr_lookup_status', [Recipient::LR_PENDING, Recipient::LR_ERROR])->count(),
            'send_mode'     => (string) config('natcon.send_mode'),
            'sample'        => collect($willSend)->take(10)->map(fn ($r) => [
                'email'     => $r->email,
                'name'      => $r->displayName(),
                'photos'    => count($r->displayPhotos()),
                'lr_status' => $r->lr_lookup_status,
            ])->values(),
        ]]);
    }

    /**
     * Queue a send. Returns immediately — the scheduler drains the rows.
     *
     * `batch_id` is supplied by the client and generated ONCE when the confirm
     * dialog opens. That's load-bearing: the frontend axios interceptor
     * transparently replays a request after a 401, and without a stable key that
     * replay would be a second blast to the same few hundred people.
     */
    public function sendInvites(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_id'      => 'nullable|integer|exists:natcon_events,id',
            'kind'          => 'nullable|in:invite,reminder',
            'batch_id'      => 'nullable|uuid',
            'recipient_ids' => 'nullable|array|max:2000',
            'recipient_ids.*' => 'integer',
            'statuses'      => 'nullable|array',
            'statuses.*'    => 'string|max:24',
        ]);

        $event   = $this->resolveEvent($request);
        $kind    = $data['kind'] ?? Outbox::KIND_INVITE;
        $batchId = $data['batch_id'] ?? (string) Str::uuid();

        // Idempotency: replaying the same batch_id must not queue a second time.
        // The outbox rows ARE the batch, so their existence is the guard — which
        // is why that table is never pruned.
        if (Outbox::where('batch_id', $batchId)->exists()) {
            return response()->json([
                'data'   => ['batch_id' => $batchId] + Outbox::batchProgress($batchId),
                'replay' => true,
            ]);
        }

        $today      = Carbon::now($event->timezone ?: 'Asia/Manila');
        $recipients = $this->targetQuery($request, $event, $kind)->get();
        $suppressed = Suppression::lookup($recipients->pluck('email')->all());
        $userId     = $request->user()?->id;

        $queued = $skipped = 0;

        DB::transaction(function () use (
            $kind, $batchId, $recipients, $suppressed, $today, $userId, &$queued, &$skipped
        ) {
            foreach ($recipients as $recipient) {
                if (isset($suppressed[$recipient->email])
                    || $recipient->status === Recipient::STATUS_EXCLUDED
                    || ($kind === Outbox::KIND_REMINDER && $recipient->responded_at)) {
                    $skipped++;
                    continue;
                }

                // Returns null when the (recipient, kind, today) claim already
                // exists — the DB-level no-double-send guarantee.
                if (! $this->invites->claimSend($recipient, $kind, $today, $batchId, null, $userId)) {
                    $skipped++;
                    continue;
                }

                $this->invites->ensureToken($recipient);

                if ($kind === Outbox::KIND_INVITE) {
                    $recipient->forceFill(['status' => Recipient::STATUS_QUEUED])->save();
                }

                $queued++;
            }
        });

        // The forensic record of what was targeted, which used to be a `filters`
        // column on a batches table. An audit row is strictly better: it lands in
        // the activity feed an admin already reads, it's append-only so the drain
        // cannot clobber it, and it keeps the queue-time skip count — which the
        // old column lost within 60 seconds of every send when the first drain
        // tick overwrote it with the drain-time cancelled count.
        $this->recordSendAudit($event, $kind, $batchId, $userId, $request, $recipients->count(), $queued, $skipped);

        return response()->json([
            'data' => [
                'batch_id'  => $batchId,
                'total'     => $recipients->count(),
                'queued'    => $queued,
                'skipped'   => $skipped,
                'send_mode' => (string) config('natcon.send_mode'),
                'note'      => config('natcon.send_mode') === 'off'
                    ? 'NATCON_SEND_MODE is "off" — messages are queued but will not be sent until it is changed.'
                    : 'Queued. The scheduler drains roughly ' . config('natcon.drain_limit', 40) . ' per minute.',
            ],
        ], 202);
    }

    /** Live progress for one batch, derived from the outbox rows themselves. */
    public function batch(string $batchId): JsonResponse
    {
        $first = Outbox::where('batch_id', $batchId)->orderBy('id')->first();

        abort_unless($first, 404, 'Batch not found.');

        return response()->json(['data' => [
            'batch_id' => $batchId,
            'kind'     => $first->kind,
            'queued_at' => $first->queued_at?->toIso8601String(),
        ] + Outbox::batchProgress($batchId)]);
    }

    private function recordSendAudit(
        NatconEvent $event,
        string $kind,
        string $batchId,
        ?int $userId,
        Request $request,
        int $total,
        int $queued,
        int $skipped,
    ): void {
        try {
            $user = $request->user();

            Audit::create([
                'user_id'        => $userId,
                'user_type'      => $user ? \App\Models\User::class : null,
                'user_role'      => $user?->role?->name,
                'user_name'      => $user?->name,
                'event'          => 'natcon_send_queued',
                'category'       => 'natcon',
                'source'         => 'admin_send_invites',
                'auditable_type' => NatconEvent::class,
                'auditable_id'   => $event->id,
                'subject_label'  => $event->name,
                'description'    => "Queued {$queued} {$kind}(s) for {$event->short_name}",
                'old_values'     => null,
                'new_values'     => [
                    'batch_id' => $batchId,
                    'kind'     => $kind,
                    'filters'  => $request->only(['statuses', 'recipient_ids', 'kind']),
                    'total'    => $total,
                    'queued'   => $queued,
                    'skipped'  => $skipped,
                ],
            ]);
        } catch (\Throwable $e) {
            // Bookkeeping must never fail the send that already happened.
            \Illuminate\Support\Facades\Log::warning('NATCON send audit failed', [
                'batch_id' => $batchId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    // ── Submissions / export ────────────────────────────────────────────────

    /**
     * Flat rows for the admin table and the CSV export.
     *
     * Returns JSON rather than a streamed CSV on purpose: with
     * responseType:"blob" the frontend axios interceptor can't inspect a 401 body
     * to tell a guest-token rejection from an auth failure, so a blip would log
     * the admin out. The CSV is assembled client-side from these rows.
     */
    public function submissions(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);

        $query = Recipient::where('natcon_event_id', $event->id);

        if ($request->boolean('responded_only')) {
            $query->whereNotNull('responded_at');
        }

        $rows = $query->orderBy('id')->get();

        // ⚠️ ALL fields, not just active ones. Building the column list from the
        //    public schema (which filters is_active) meant hiding a question
        //    silently dropped its column from the export while the answers were
        //    still sitting in the data — the export looked complete and wasn't.
        $columns = $this->forms->fields($event, activeOnly: false)
            ->reject(fn ($f) => $f->type === \App\Natcon\Models\FormField::TYPE_SECTION)
            ->map(fn ($f) => ['key' => $f->key, 'label' => $f->label, 'is_active' => (bool) $f->is_active])
            ->values();

        $answersByRecipient = DB::table('natcon_form_submissions')
            ->where('natcon_event_id', $event->id)
            ->pluck('answers_snapshot', 'natcon_recipient_id');

        return response()->json([
            'data' => $rows->map(function (Recipient $r) use ($answersByRecipient) {
                $snapshot = json_decode((string) ($answersByRecipient[$r->id] ?? '[]'), true) ?: [];
                $answers  = [];
                foreach ($snapshot as $a) {
                    if (isset($a['key'])) {
                        $answers[$a['key']] = $a['display_value'] ?? $a['value'] ?? null;
                    }
                }

                return [
                    'id'                 => $r->id,
                    'email'              => $r->email,
                    'first_name'         => $r->first_name,
                    'last_name'          => $r->last_name,
                    'full_name'          => $r->displayName(),
                    'team'               => $r->team,
                    'reg_id'             => $r->reg_id,
                    'phone'              => $r->phone,
                    'seat_number'        => $r->seat_number,
                    'final_photo_url'    => $r->finalPhotoUrl(),
                    'photo_source'       => $r->finalPhotoSource(),
                    'uploaded_photo_url' => $r->current_photo_url,
                    'retained_photo_url' => $r->retained_photo_url,
                    'lr_photo_count'     => count($r->displayPhotos()),
                    'status'             => $r->status,
                    'response'           => $r->response,
                    'responded_at'       => $r->responded_at?->toDateTimeString(),
                    'photo_uploaded_at'  => $r->photo_uploaded_at?->toDateTimeString(),
                    'form_submitted_at'  => $r->form_submitted_at?->toDateTimeString(),
                    'invited_at'         => $r->invited_at?->toDateTimeString(),
                    'reminders_sent'     => (int) $r->reminders_sent,
                    'open_count'         => (int) $r->open_count,
                    'lr_lookup_status'   => $r->lr_lookup_status,
                    'notes'              => $r->notes,
                    'answers'            => $answers,
                ];
            })->values(),
            'meta' => [
                // The export builds its dynamic columns from this, so a newly
                // added question appears in the CSV with no export-code change —
                // and a hidden one keeps its column instead of vanishing.
                'form_fields' => $columns,
                'total'       => $rows->count(),
                'event'       => ['id' => $event->id, 'year' => $event->year, 'name' => $event->name],
            ],
        ]);
    }

    /**
     * Review one uploaded photo — and, by approving it, choose it.
     *
     * Approving is exclusive: exactly one photo per awardee can be the chosen
     * one, because exactly one goes on the materials. Approving a second without
     * clearing the first would leave "which one did we pick?" answered twice, and
     * PhotoService::syncResponseState() would resolve it by array order, which is
     * not a decision anybody made.
     */
    public function reviewPhoto(Request $request, PhotoSubmission $submission): JsonResponse
    {
        $data = $request->validate([
            'review_status' => 'required|in:pending,approved,rejected',
            'review_note'   => 'nullable|string|max:512',
        ]);

        $recipient = $submission->recipient;

        DB::transaction(function () use ($submission, $data, $request, $recipient) {
            if ($data['review_status'] === PhotoSubmission::REVIEW_APPROVED && $recipient) {
                PhotoSubmission::where('natcon_recipient_id', $recipient->id)
                    ->where('id', '!=', $submission->id)
                    ->where('review_status', PhotoSubmission::REVIEW_APPROVED)
                    ->update([
                        'review_status' => PhotoSubmission::REVIEW_PENDING,
                        'reviewed_by'   => $request->user()?->id,
                        'reviewed_at'   => Carbon::now(),
                    ]);
            }

            $submission->forceFill($data + [
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => Carbon::now(),
            ])->save();

            // Republishes current_photo_url — and therefore finalPhotoUrl() and
            // the CSV export column — from the new choice.
            if ($recipient) {
                app(PhotoService::class)->syncResponseState($recipient);
            }
        });

        return response()->json([
            'data' => $submission->fresh(),
            'meta' => [
                'final_photo_url' => $recipient?->fresh()->finalPhotoUrl(),
            ],
        ]);
    }

    /**
     * Rule an awardee's existing photo unusable, forcing a fresh submission.
     *
     * ─── Why this is per-awardee ────────────────────────────────────────────
     * The campaign lets anyone with a photo on file keep it, which is wrong when
     * that photo cannot be printed. Making it a blanket policy would throw away
     * the retain flow — and its response rate — for the majority whose photo is
     * fine. So it is a judgment recorded one awardee at a time, by whoever looked
     * at the photo.
     *
     * Setting it does NOT clear an existing retain response. Someone who already
     * chose to keep a photo we have since rejected needs to be asked again, and
     * the admin can see they had answered — which is the conversation the events
     * team will actually be having with them.
     */
    public function setPhotoPolicy(Request $request, Recipient $recipient): JsonResponse
    {
        $data = $request->validate([
            'requires_new_photo' => 'required|boolean',
            // Shown to the awardee, so it has to be a reason and not a code.
            'note'               => 'nullable|string|max:255',
        ]);

        $flagged = (bool) $data['requires_new_photo'];

        $recipient->auditSource      = 'admin_photo_policy';
        $recipient->auditDescription = $flagged
            ? 'Required a new NATCON photo (existing photo ruled unusable)'
            : 'Cleared the require-new-photo flag';

        $recipient->forceFill([
            'requires_new_photo'      => $flagged,
            'requires_new_photo_note' => $flagged ? ($data['note'] ?: null) : null,
            'requires_new_photo_at'   => $flagged ? Carbon::now() : null,
            'requires_new_photo_by'   => $flagged ? $request->user()?->id : null,
        ])->save();

        return response()->json([
            'data'    => RecipientResource::detailed($recipient->fresh()->load('event')),
            'message' => $flagged
                ? 'This awardee must now send a new photo. Keeping the old one is blocked.'
                : 'Cleared. This awardee can keep their existing photo again.',
        ]);
    }

    // ── Suppressions ────────────────────────────────────────────────────────

    public function suppressions(): JsonResponse
    {
        return response()->json(['data' => Suppression::orderByDesc('id')->limit(500)->get()]);
    }

    public function suppress(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'  => 'required|email|max:191',
            'reason' => 'required|in:bounce,complaint,unsubscribe,manual,invalid_domain',
            'detail' => 'nullable|string|max:512',
        ]);

        $row = Suppression::updateOrCreate(
            ['email' => strtolower(trim($data['email']))],
            ['reason' => $data['reason'], 'detail' => $data['detail'] ?? null, 'created_by' => $request->user()?->id],
        );

        return response()->json(['data' => $row], 201);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function resolveEvent(Request $request): NatconEvent
    {
        $event = $request->filled('event_id')
            ? NatconEvent::find($request->integer('event_id'))
            : NatconEvent::active();

        abort_unless($event, 404, 'No NATCON event found.');

        return $event;
    }

    /** Who a send would target, before per-recipient skip rules are applied. */
    private function targetQuery(Request $request, NatconEvent $event, string $kind)
    {
        $query = Recipient::where('natcon_event_id', $event->id);

        // Explicit list: the deliberate escape hatch for "send to exactly these
        // people again". It bypasses the status guard below ON PURPOSE, which is
        // why the sales-wave UI never uses it — see the warning on that guard.
        if ($ids = $request->input('recipient_ids')) {
            return $query->whereIn('id', $ids);
        }

        if ($statuses = $request->input('statuses')) {
            $query->whereIn('status', $statuses);
        } else {
            /**
             * ⚠️ This is what makes wave 2 safe.
             *
             * An invite targets `pending` only. DrainOutbox::applySentState()
             * moves a recipient to `invited` the moment their invite goes out, so
             * everyone in an earlier wave drops out of every later one by itself
             * — "send the rest a few days later" is genuinely just pressing Send
             * again.
             *
             * The sales band below is applied AFTER this, never instead of it.
             * An earlier draft of the wave feature would have passed explicit ids
             * from the table selection, which returns above without any status
             * filter and would have re-invited the entire first wave.
             */
            $kind === Outbox::KIND_REMINDER
                ? $query->whereIn('status', Recipient::REMINDABLE)->whereNull('responded_at')
                : $query->where('status', Recipient::STATUS_PENDING);
        }

        return $this->applySalesBand($query, $request);
    }

    /**
     * Narrow a recipient query to one sales wave.
     *
     * Shared by the send path and the admin list so that what an admin is
     * LOOKING at and who a send would actually email cannot drift apart.
     *
     * The upper bound is exclusive: an awardee sitting exactly on the breakpoint
     * belongs to the upper band and appears in one wave, never in both or
     * neither.
     *
     * `no_sales` is its own case rather than "below the floor". Recipients added
     * by hand have no sales figure at all, and a `>=` filter drops them silently
     * — which is how a test address disappears from every view and nobody can
     * work out where it went.
     */
    private function applySalesBand($query, Request $request)
    {
        if ($request->boolean('no_sales')) {
            return $query->whereNull('total_sales');
        }

        if ($request->filled('min_sales')) {
            $query->where('total_sales', '>=', (float) $request->input('min_sales'));
        }

        if ($request->filled('max_sales')) {
            $query->where('total_sales', '<', (float) $request->input('max_sales'));
        }

        return $query;
    }
}
