<?php

namespace App\Natcon\Http\Resources;

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

        return [
            'recipient' => [
                'first_name'   => $r->first_name,
                'last_name'    => $r->last_name,
                'display_name' => $r->displayName(),
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
                'uploaded' => $r->current_photo_url,
                'final'    => [
                    'url'    => $r->finalPhotoUrl(),
                    'source' => $r->finalPhotoSource(),
                ],
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
