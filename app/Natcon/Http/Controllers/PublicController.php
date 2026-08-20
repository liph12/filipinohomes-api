<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Exceptions\ExpiredLinkException;
use App\Natcon\Exceptions\InvalidLinkException;
use App\Natcon\Http\Resources\PublicProfileResource;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\Outbox;
use App\Natcon\Models\Recipient;
use App\Natcon\Models\PhotoSubmission;
use App\Natcon\Models\Suppression;
use App\Natcon\Services\FormService;
use App\Natcon\Services\InviteService;
use App\Natcon\Services\PhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The awardee-facing NATCON endpoints.
 *
 * ─── Two rules that constrain everything here ────────────────────────────────
 *
 * 1. NOTHING IN THIS CONTROLLER MAY RETURN 401.
 *    The frontend axios interceptor (src/lib/axios.ts) treats a 401 whose body is
 *    keyed `message` as a dead session and clears auth_token/auth_user. Awardees
 *    are Leuterio agents, and plenty of them will open the email in a browser
 *    where they're logged into filipinohomes.com — a 401 from a stale invite link
 *    would silently log them out of the main site. Link problems are 404/410,
 *    validation is 422.
 *
 * 2. NO GET MUTATES ANYTHING THE CAMPAIGN DEPENDS ON.
 *    Outlook SafeLinks, Mimecast and Proofpoint fetch every URL in an email within
 *    seconds of delivery, from datacenter IPs, with no JavaScript. If "Retain"
 *    were a GET side effect, hundreds of awardees would be recorded as having
 *    responded without ever opening the mail — and would then be excluded from the
 *    reminders that were supposed to reach them. Responses are POST only.
 *
 * These routes sit behind verify.guest.token. That is a bot speed bump, not a
 * security control: POST /api/guest-token is itself public and unauthenticated.
 * The real control is the per-recipient signed token.
 */
class PublicController extends Controller
{
    public function __construct(
        private InviteService $invites,
        private FormService $forms,
        private PhotoService $photos,
    ) {}

    /**
     * Public event facts. No token required — this feeds the /natcon pages.
     *
     * `?year=` selects a specific convention so past years stay readable as an
     * archive; without it you get the current one. A past year is deliberately
     * still served even when is_active is false — that's the whole point of
     * keeping the data.
     */
    public function event(Request $request): JsonResponse
    {
        $request->validate([
            'year' => 'nullable|integer|min:2000|max:2100',
            'slug' => 'nullable|string|max:64',
        ]);

        $event = match (true) {
            $request->filled('year') => NatconEvent::forYear($request->integer('year')),
            $request->filled('slug') => NatconEvent::where('slug', $request->string('slug'))->first(),
            default                  => NatconEvent::active(),
        };

        if (! $event) {
            return response()->json(['message' => 'No NATCON event found.'], 404);
        }

        $tz      = $event->timezone ?: 'Asia/Manila';
        $current = NatconEvent::active();

        return response()->json(['data' => [
            'slug'           => $event->slug,
            'year'           => $event->year,
            'name'           => $event->name,
            'short_name'     => $event->displayShortName(),
            'starts_on'      => $event->starts_on?->toDateString(),
            'ends_on'        => $event->ends_on?->toDateString(),
            'date_label'     => $event->dateLabel(),
            'venue'          => $event->venue,
            'hashtag'        => $event->hashtag,
            'banner_base'    => $event->banner_base,
            'sponsor_display' => $event->sponsor_display,
            'deadline_at'    => $event->photo_deadline_at?->copy()->setTimezone($tz)->toIso8601String(),
            'timezone'       => $tz,
            'days_remaining' => max(0, (int) ($event->daysUntilDeadline() ?? 0)),
            'is_past'        => $event->isPhotoWindowClosed(),
            // Lets /natcon redirect to the right year, and lets an archive page
            // say "this year has finished" without a second request.
            'is_current'     => $current && $current->id === $event->id,
            'current_year'   => $current?->year,
            'server_time'    => now()->setTimezone($tz)->toIso8601String(),
            // Whether the landing page renders the announcement reaction bar.
            // Cast because a missing column on an un-migrated environment would
            // otherwise send null and read as "off".
            'reactions_enabled' => (bool) ($event->reactions_enabled ?? true),
        ]]);
    }

    /** The awardee's own record, resolved from the token alone. */
    public function profile(Request $request)
    {
        $request->validate(['t' => 'required|string|min:16|max:160']);

        return $this->withRecipient($request->string('t'), function (Recipient $recipient) {
            // A GET marking "opened" is only meaningful because this endpoint is
            // called from JavaScript — link prescanners never execute it. Do not
            // move this call into a server-rendered page.
            $recipient->forceFill([
                'first_opened_at' => $recipient->first_opened_at ?? Carbon::now(),
                'open_count'      => $recipient->open_count + 1,
            ])->save();

            return $this->profilePayload($recipient);
        });
    }

    /**
     * Record Retain or Change. POST only, and idempotent — re-posting the same
     * choice returns the same payload rather than erroring, because the frontend
     * retries and people double-tap.
     */
    public function respond(Request $request)
    {
        $data = $request->validate([
            't'                  => 'required|string|min:16|max:160',
            'choice'             => 'required|in:retain,change',
            'retained_photo_url' => 'nullable|url|max:2048',
        ]);

        return $this->withRecipient($data['t'], function (Recipient $recipient) use ($data) {
            $event  = $recipient->event;
            $choice = $data['choice'];

            // Post-deadline policy, deliberately asymmetric: accepting a "retain"
            // costs nobody anything and turns a support ticket into a record,
            // whereas accepting a new photo after the print deadline creates an
            // expectation we can't honour.
            if ($event->isPhotoWindowClosed() && $choice === Recipient::RESPONSE_CHANGE) {
                return response()->json([
                    'message' => 'The photo collection deadline has passed. Please contact the NATCON team.',
                    'code'    => 'deadline_passed',
                ], 422);
            }

            $retained = null;

            if ($choice === Recipient::RESPONSE_RETAIN) {
                // Enforced here, not just by hiding the button. The retain URL is
                // already sitting in a delivered email — it can be re-opened,
                // forwarded, or fetched by a link prescanner long after a reviewer
                // decides the photo on file is unusable.
                if ($recipient->requires_new_photo) {
                    return response()->json([
                        'message' => $recipient->requires_new_photo_note
                            ?: 'The photo we have on file cannot be used for the official materials. Please send us a new one.',
                        'code'    => 'new_photo_required',
                    ], 422);
                }

                $available = $recipient->displayPhotos();

                if (! $available) {
                    return response()->json([
                        'message' => "We don't have a photo on file for you yet, so there's nothing to retain. Please upload one.",
                        'code'    => 'no_photo_on_file',
                    ], 422);
                }

                $requested = $data['retained_photo_url'] ?? null;

                // Only a photo we actually hold for this person may be retained —
                // otherwise the field is an open write of an arbitrary URL into
                // what the events team will print.
                if ($requested !== null && ! in_array($requested, $available, true)) {
                    return response()->json([
                        'message' => 'That photo is not one of the photos on file for you.',
                        'code'    => 'unknown_photo',
                    ], 422);
                }

                $retained = $requested ?? $available[0];
            }

            $recipient->forceFill([
                'response'           => $choice,
                'responded_at'       => Carbon::now(),
                'retained_photo_url' => $retained,
                'status'             => $choice === Recipient::RESPONSE_RETAIN
                    ? Recipient::STATUS_RESPONDED_RETAIN
                    // "change" is an intent, not a delivery — status only advances
                    // to photo_uploaded once a file actually lands.
                    : ($recipient->current_photo_url
                        ? Recipient::STATUS_PHOTO_UPLOADED
                        : Recipient::STATUS_RESPONDED_CHANGE),
            ])->save();

            $this->refreshCounters($event);

            return $this->profilePayload($recipient->fresh(['event']));
        });
    }

    /** Replacement photo upload. The one public write to production S3. */
    public function photo(Request $request)
    {
        $data = $request->validate([
            't'    => 'required|string|min:16|max:160',
            // HEIC/HEIF are accepted because iPhones hand them over from the photo
            // library by default; rejecting them means "Invalid file type:
            // IMG_4821.HEIC" and an abandoned submission we cannot re-collect.
            // mimes: is checked against the decoded type, so this is not a
            // client-controlled claim.
            'file' => 'required|file|mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif|max:' . (int) config('natcon.photo.max_upload_kb', 15360),
        ], [
            'file.mimetypes' => 'Please upload a JPG, PNG, WEBP or HEIC image.',
            'file.max'       => 'That image is too large. Please keep it under 15MB.',
        ]);

        return $this->withRecipient($data['t'], function (Recipient $recipient) use ($request) {
            if ($recipient->event->isPhotoWindowClosed()) {
                return response()->json([
                    'message' => 'The photo collection deadline has passed. Please contact the NATCON team.',
                    'code'    => 'deadline_passed',
                ], 422);
            }

            try {
                $this->photos->store(
                    $recipient,
                    $request->file('file'),
                    $request->ip(),
                    (string) $request->userAgent(),
                );
            } catch (\RuntimeException $e) {
                // Thrown by the megapixel / decode gate. A user-fixable problem,
                // so 422 with the reason rather than a 500.
                return response()->json(['message' => $e->getMessage(), 'code' => 'bad_image'], 422);
            } catch (\Throwable $e) {
                Log::error('NATCON photo upload failed', [
                    'recipient_id' => $recipient->id,
                    'error'        => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => "We couldn't save that photo. Please try again.",
                    'code'    => 'upload_failed',
                ], 500);
            }

            // ⚠️ This used to forceFill response = change and responded_at right
            //    here, under "uploading IS the change response". That was true
            //    when one photo was the whole ask. Now that the event asks for
            //    three, marking someone responded on their FIRST upload would
            //    drop them out of the reminder query holding one photo, and show
            //    them as confirmed in the admin — a silent partial failure found
            //    on deadline day.
            //
            //    Completion is derived from the photo count in exactly one place.
            //    See PhotoService::syncResponseState().
            $this->photos->syncResponseState($recipient);

            $this->refreshCounters($recipient->event);

            return $this->profilePayload($recipient->fresh(['event']));
        });
    }

    /**
     * Declare which existing photos the awardee is keeping.
     *
     * Sent alongside their uploads when they press Save, so the whole set lands
     * as one decision rather than as a keep and a change that could disagree.
     */
    public function keepPhotos(Request $request)
    {
        $data = $request->validate([
            't'      => 'required|string|min:16|max:160',
            'urls'   => 'present|array|max:10',
            'urls.*' => 'string|max:2048',
        ]);

        return $this->withRecipient($data['t'], function (Recipient $recipient) use ($data) {
            if ($recipient->event->isPhotoWindowClosed()) {
                return response()->json([
                    'message' => 'The photo collection deadline has passed. Please contact the NATCON team.',
                    'code'    => 'deadline_passed',
                ], 422);
            }

            // Same enforcement as respond(): a reviewer's rejection of the photos
            // on file cannot be worked around by keeping them through this route.
            if ($recipient->requires_new_photo && $data['urls']) {
                return response()->json([
                    'message' => $recipient->requires_new_photo_note
                        ?: 'The photo we have on file cannot be used for the official materials. Please send us new ones.',
                    'code'    => 'new_photo_required',
                ], 422);
            }

            $this->photos->keepExisting($recipient, $data['urls']);
            $this->refreshCounters($recipient->event);

            return $this->profilePayload($recipient->fresh(['event']));
        });
    }

    /**
     * Remove one of their own photos, so a slot can be re-used.
     *
     * Needed the moment more than one photo is collected: without it, an awardee
     * who sends a bad shot as their second of three is stuck with it, and the cap
     * on submissions turns into a trap rather than a limit.
     */
    public function deletePhoto(Request $request)
    {
        $data = $request->validate([
            't'             => 'required|string|min:16|max:160',
            'submission_id' => 'required|integer',
        ]);

        return $this->withRecipient($data['t'], function (Recipient $recipient) use ($data) {
            // Deleting after the print deadline would let someone drop to zero
            // photos with no way to replace them — uploads are already closed by
            // then. The window has to gate both directions.
            if ($recipient->event->isPhotoWindowClosed()) {
                return response()->json([
                    'message' => 'The photo collection deadline has passed, so photos can no longer be changed. Please contact the NATCON team.',
                    'code'    => 'deadline_passed',
                ], 422);
            }

            // Scoped to THIS recipient, so an id from someone else's record is a
            // 404 rather than a cross-account delete. The token identifies the
            // recipient; the body must not be able to widen that.
            $submission = PhotoSubmission::where('id', $data['submission_id'])
                ->where('natcon_recipient_id', $recipient->id)
                ->where('status', PhotoSubmission::STATUS_ACTIVE)
                ->first();

            if (! $submission) {
                return response()->json([
                    'message' => 'That photo is no longer on file.',
                    'code'    => 'unknown_photo',
                ], 404);
            }

            $this->photos->remove($recipient, $submission);
            $this->refreshCounters($recipient->event);

            return $this->profilePayload($recipient->fresh(['event']));
        });
    }

    /** Custom form answers. Separate commit from the photo decision, on purpose. */
    public function form(Request $request)
    {
        $data = $request->validate([
            't'       => 'required|string|min:16|max:160',
            'answers' => 'required|array',
        ]);

        return $this->withRecipient($data['t'], function (Recipient $recipient) use ($data, $request) {
            $this->forms->submit(
                $recipient,
                $data['answers'],
                $request->ip(),
                (string) $request->userAgent(),
            );

            return $this->profilePayload($recipient->fresh(['event']));
        });
    }

    /**
     * "I lost my link." The only email-keyed endpoint, and deliberately a useless
     * oracle: identical body and status whether or not the address is on the list,
     * so it cannot be used to test who is an awardee.
     */
    public function resendLink(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email|max:191']);

        $always = response()->json([
            'message' => "If that email is on our NATCON list, we've sent a fresh link to it.",
        ], 202);

        $email = strtolower(trim($request->string('email')));
        $event = NatconEvent::active();

        if (! $event || Suppression::suppresses($email)) {
            return $always;
        }

        $recipient = Recipient::with('event')
            ->where('natcon_event_id', $event->id)
            ->where('email', $email)
            ->first();

        if (! $recipient || $recipient->status === Recipient::STATUS_EXCLUDED) {
            return $always;
        }

        // Its own outbox kind, so this can't consume the day's invite claim — and
        // so the UNIQUE(recipient, kind, send_date) index doubles as a 1-per-day
        // abuse limit on a public endpoint.
        $claim = $this->invites->claimSend($recipient, Outbox::KIND_RESEND);

        if ($claim) {
            $this->invites->ensureToken($recipient);
        }

        return $always;
    }

    /**
     * Resolve the token and map link failures onto stable status codes.
     * 404 for anything unresolvable (one body for every cause — no oracle),
     * 410 for a genuine expiry so the frontend can offer a fresh link.
     */
    private function withRecipient(string $token, callable $callback)
    {
        try {
            $recipient = $this->invites->resolveToken($token);
        } catch (ExpiredLinkException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'link_expired'], 410);
        } catch (InvalidLinkException $e) {
            return response()->json(['message' => 'Invalid link.', 'code' => 'link_invalid'], 404);
        }

        return $callback($recipient);
    }

    private function profilePayload(Recipient $recipient)
    {
        $resource = new PublicProfileResource($recipient);
        $resource->formSchema = $this->forms->schemaFor($recipient->event);

        return $resource;
    }

    private function refreshCounters(NatconEvent $event): void
    {
        $base = Recipient::where('natcon_event_id', $event->id);

        $event->forceFill([
            'responded_count'      => (clone $base)->whereNotNull('responded_at')->count(),
            'photo_uploaded_count' => (clone $base)->whereNotNull('photo_uploaded_at')->count(),
        ])->save();
    }
}
