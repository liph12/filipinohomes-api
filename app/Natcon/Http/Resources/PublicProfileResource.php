<?php

namespace App\Natcon\Http\Resources;

use App\Natcon\Models\PhotoSubmission;
use App\Natcon\Models\Recipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What an awardee's browser is allowed to see.
 *
 * This class is the privacy firewall for the whole feature, so the omissions are
 * the point and none of them are accidental:
 *
 *   phone       — Leuterio Realty's public endpoint hands out awardee phone
 *                 numbers to anyone who knows an email address. That's their
 *                 exposure; re-serving it under filipinohomes.com would make it
 *                 ours. The awardee already knows their own number, so there is
 *                 no product value to trade against the risk.
 *   reg_id      — internal LR identifier, useful for enumeration, useless here.
 *   qr_code     — event credential.
 *   lr_payload  — the raw upstream record.
 *   id / email  — the recipient is identified by their token, not by anything the
 *                 page has to echo back.
 *
 * Anything added here is visible to whoever holds a forwarded link. Add
 * deliberately.
 *
 * @mixin Recipient
 */
class PublicProfileResource extends JsonResource
{
    /** @var array<int,array<string,mixed>> */
    public array $formSchema = [];

    public function toArray(Request $request): array
    {
        /** @var Recipient $r */
        $r     = $this->resource;
        $event = $r->event;
        $tz    = $event->timezone ?: 'Asia/Manila';

        $submission = $r->relationLoaded('formSubmission')
            ? $r->formSubmission
            : $r->formSubmission()->first();

        // Oldest first, so a photo keeps the slot it was added in and the tray on
        // the awardee's page doesn't reshuffle every time they add another.
        $uploaded = $r->relationLoaded('activePhotos')
            ? $r->activePhotos
            : $r->activePhotos()->get();

        $required = Recipient::requiredPhotoCount();

        return [
            'recipient' => [
                'first_name'   => $r->first_name,
                'last_name'    => $r->last_name,
                'display_name' => $r->displayName(),
                // Who is actually on this account. Carried per-recipient rather
                // than folded into the form schema, because that schema is
                // CACHED PER EVENT — baking one couple's names into it would
                // serve them to all 292 awardees.
                'person_names' => $r->personNames(),
                'team'         => $r->team,
                'email_masked' => $this->maskEmail($r->email),
            ],

            'event' => [
                'name'       => $event->name,
                'short_name' => $event->displayShortName(),
                'year'       => $event->year,
                'starts_on'  => $event->starts_on?->toDateString(),
                'ends_on'    => $event->ends_on?->toDateString(),
                'venue'      => $event->venue,
                'date_label' => $event->dateLabel(),
                'hashtag'    => $event->hashtag,
                // The frontend derives its responsive <picture> srcset from this,
                // so a new year is a new folder plus one admin edit.
                'banner_base' => $event->banner_base,
            ],

            'photos' => [
                // What Leuterio Realty has on file. Empty is a real and common
                // case — the frontend renders a "we don't have one yet" state
                // rather than a broken <img>.
                'existing' => $r->displayPhotos(),

                // An ARRAY now, not a single url. The event asks for several
                // photos so the organizers have something to choose from, and the
                // id travels with each one because the page needs it to delete.
                'uploaded' => $uploaded->map(fn ($p) => [
                    'id'          => $p->id,
                    'url'         => $p->photo_url,
                    'uploaded_at' => $this->iso($p->created_at, $tz),
                    // 'uploaded' | 'lr_retained'. The tray labels a kept photo
                    // differently from one they sent, even though both count
                    // toward the same total.
                    'source'      => $p->source,
                    // So the awardee can see which one was picked, rather than
                    // wondering whether anyone looked.
                    'chosen'      => $p->review_status === PhotoSubmission::REVIEW_APPROVED,
                ])->values(),

                // True once they have saved ANY decision. The page uses it to
                // tell "hasn't decided yet" — where the photos on file are
                // pre-selected — from "decided, and dropped that one", where
                // re-adding it would resurrect a photo they removed on purpose.
                'has_saved_set' => $uploaded->isNotEmpty(),

                // Sent so the copy on the page and the copy in the email cannot
                // disagree about the number, and so lowering the requirement
                // mid-campaign needs no frontend deploy.
                'required_count' => $required,
                'max_count'      => Recipient::maxPhotoCount(),
                'remaining'      => max(0, $required - $uploaded->count()),
                'complete'       => $uploaded->count() >= $required,

                'final'    => [
                    'url'    => $r->finalPhotoUrl(),
                    'source' => $r->finalPhotoSource(),
                ],
            ],

            // Whether a reviewer has ruled the photo on file unusable. The page
            // hides Keep when this is set, and PublicController::respond() refuses
            // a retain regardless — the emailed retain link outlives the UI.
            'policy' => [
                'requires_new_photo' => (bool) $r->requires_new_photo,
                'requires_new_photo_note' => $r->requires_new_photo_note,
            ],

            // Every timestamp is emitted in the event's timezone, not UTC. The
            // instant is identical either way, but a Manila-offset string is what
            // the page displays and what anyone debugging an "I submitted at 2pm"
            // report expects to see.
            'response' => [
                'choice'              => $r->response,
                'responded_at'        => $this->iso($r->responded_at, $tz),
                'retained_photo_url'  => $r->retained_photo_url,
                'photo_uploaded_at'   => $this->iso($r->photo_uploaded_at, $tz),
            ],

            'form' => [
                'fields'       => $this->formSchema,
                'answers'      => $submission?->answerMap() ?? (object) [],
                'submitted_at' => $this->iso($r->form_submitted_at, $tz),
            ],

            'deadline' => [
                // With offset, always. A bare "2026-08-24" parses as UTC midnight
                // in JS and a bare "2026-08-24T23:59:59" parses as the viewer's
                // local midnight — both wrong, in opposite directions.
                'at'             => $event->photo_deadline_at?->copy()->setTimezone($tz)->toIso8601String(),
                'timezone'       => $tz,
                'days_remaining' => max(0, (int) ($event->daysUntilDeadline() ?? 0)),
                'is_past'        => $event->isPhotoWindowClosed(),
            ],

            // Server clock, so the countdown can correct a device whose date is
            // wrong without silently showing the wrong number of days.
            'server_time' => now()->setTimezone($tz)->toIso8601String(),

            // Served from the API rather than hardcoded in the bundle so marketing
            // can reword the confirmation without a frontend deploy. It now
            // genuinely is editable — this reads a column on the event, where the
            // first version had a PHP literal sitting under this same comment.
            'messages' => [
                'thank_you' => $event->thankYou(),
            ],
        ];
    }

    private function iso($value, string $tz): ?string
    {
        return $value?->copy()->setTimezone($tz)->toIso8601String();
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ($local === '' || $domain === '') {
            return '***';
        }

        $visible = mb_substr($local, 0, 1);
        $tail    = mb_strlen($local) > 1 ? mb_substr($local, -1) : '';

        return $visible . str_repeat('*', max(1, mb_strlen($local) - 2)) . $tail . '@' . $domain;
    }
}
