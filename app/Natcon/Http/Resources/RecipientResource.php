<?php

namespace App\Natcon\Http\Resources;

use App\Natcon\Models\Recipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-side view of a recipient. Unlike PublicProfileResource this DOES
 * include the LR contact details — an admin chasing a non-responder needs the
 * phone number, and this route is already behind auth:sanctum + RoleMiddleware.
 *
 * `lr_payload` is still withheld from the list (it's several KB per row and the
 * list can be 1,000 rows); the detail endpoint includes it.
 *
 * @mixin Recipient
 */
class RecipientResource extends JsonResource
{
    public function __construct($resource, private bool $detailed = false)
    {
        parent::__construct($resource);
    }

    public static function detailed(Recipient $recipient): self
    {
        return new self($recipient, true);
    }

    public function toArray(Request $request): array
    {
        /** @var Recipient $r */
        $r  = $this->resource;
        $tz = $r->event?->timezone ?: 'Asia/Manila';

        $base = [
            'id'           => $r->id,
            'email'        => $r->email,
            'first_name'   => $r->first_name,
            'last_name'    => $r->last_name,
            'display_name' => $r->displayName(),
            'phone'        => $r->phone,
            'team'         => $r->team,
            // LR's province, under LR's name. The admin labels it "Province".
            'state'        => $r->state,
            'reg_id'       => $r->reg_id,
            'seat_number'  => $r->seat_number,

            'lr_lookup_status' => $r->lr_lookup_status,
            'lr_fetched_at'    => $this->iso($r->lr_fetched_at, $tz),
            'lr_last_error'    => $r->lr_last_error,
            'lr_photos'        => $r->displayPhotos(),
            'lr_photo_count'   => count($r->displayPhotos()),

            'status'   => $r->status,
            'response' => $r->response,

            'invited_at'        => $this->iso($r->invited_at, $tz),
            'last_reminded_at'  => $this->iso($r->last_reminded_at, $tz),
            'reminders_sent'    => (int) $r->reminders_sent,
            'first_opened_at'   => $this->iso($r->first_opened_at, $tz),
            'open_count'        => (int) $r->open_count,
            'responded_at'      => $this->iso($r->responded_at, $tz),
            'photo_uploaded_at' => $this->iso($r->photo_uploaded_at, $tz),
            'form_submitted_at' => $this->iso($r->form_submitted_at, $tz),

            'current_photo_url'  => $r->current_photo_url,
            'retained_photo_url' => $r->retained_photo_url,
            // What the events team should actually print.
            'final_photo_url'    => $r->finalPhotoUrl(),
            'final_photo_source' => $r->finalPhotoSource(),

            // On the LIST row, not just the detail view: the table needs to show
            // "2 of 3" and a needs-new-photo chip without opening every drawer.
            'active_photo_count' => $r->activePhotos()->count(),
            'photos_required'    => Recipient::requiredPhotoCount(),
            'requires_new_photo' => (bool) $r->requires_new_photo,

            // From LR's qualifiers list. On the LIST row, not just the detail
            // view: with 285 imported awardees sitting beside a handful of
            // hand-added test addresses, telling them apart has to be possible
            // without opening each drawer.
            'total_sales'            => $r->total_sales !== null ? (float) $r->total_sales : null,
            'lr_confirmation_status' => $r->lr_confirmation_status,

            /**
             * Is this person actually on LR's qualifier roster?
             *
             * ⚠️ NOT the same question as `source`. Source records how the row
             *    first got here — Eutequio was pasted in by hand during testing
             *    and is also a genuine qualifier, so his source reads 'paste'
             *    forever. Labelling the list by source would file a real awardee
             *    under "added manually", which is exactly the confusion the label
             *    exists to prevent. Presence of the qualifier payload is the
             *    honest answer, and a sync sets it regardless of origin.
             */
            'is_qualifier' => $r->qualifier_payload !== null,

            'source'        => $r->source,
            'send_failures' => (int) $r->send_failures,
            'last_error'    => $r->last_error,
            'notes'         => $r->notes,
            'has_token'     => $r->token_nonce !== null,
            'created_at'    => $this->iso($r->created_at, $tz),
        ];

        if (! $this->detailed) {
            return $base;
        }

        return $base + [
            'lr_payload' => $r->lr_payload,

            // The reason and the attribution, shown together. This flag makes a
            // real person go and re-shoot a photo, so the drawer says who decided.
            // Flattened from qualifier_payload rather than given columns of their
            // own — the drawer is the only thing that reads them.
            'qualifier' => $r->qualifier_payload ? [
                'agent_id'          => $r->qualifier_payload['agentid'] ?? null,
                'team_id'           => $r->qualifier_payload['sales_team_member']['sales_team']['id'] ?? null,
                'team_logo'         => $r->qualifier_payload['sales_team_member']['sales_team']['teamlogo'] ?: null,
                'is_leader'         => (bool) ($r->qualifier_payload['sales_team_member']['isleader'] ?? false),
                'date_joined'       => $r->qualifier_payload['sales_team_member']['datejoined'] ?? null,
                'confirmed_at'      => $r->qualifier_payload['member'][0]['natcon_confirmation']['updated_at'] ?? null,
            ] : null,

            'requires_new_photo_note' => $r->requires_new_photo_note,
            'requires_new_photo_at'   => $this->iso($r->requires_new_photo_at, $tz),
            'requires_new_photo_by'   => $r->requiresNewPhotoBy?->only(['id', 'name']),

            'photo_submissions' => $r->photoSubmissions()
                ->orderByDesc('id')
                ->get()
                ->map(fn ($p) => [
                    'id'            => $p->id,
                    'photo_url'     => $p->photo_url,
                    'status'        => $p->status,
                    'review_status' => $p->review_status,
                    'width'         => $p->width,
                    'height'        => $p->height,
                    'byte_size'     => $p->byte_size,
                    'created_at'    => $this->iso($p->created_at, $tz),
                ])->all(),
            'sends' => $r->sends()
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn ($s) => [
                    'kind'      => $s->kind,
                    'status'    => $s->status,
                    'send_date' => $s->send_date?->toDateString(),
                    'subject'   => $s->subject,
                    'sent_at'   => $this->iso($s->sent_at, $tz),
                    'error'     => $s->error,
                ])->all(),
            // An ordered list of {key,label,value}, not the raw answers map: the
            // drawer was deriving labels from the field slug and printing
            // "natcon polo shirt size". The label is frozen in the snapshot at
            // submit time, so it survives the question later being renamed.
            'form_answers' => $r->formSubmission()->first()?->labelledRows() ?? [],
        ];
    }

    private function iso($value, string $tz): ?string
    {
        return $value?->copy()->setTimezone($tz)->toIso8601String();
    }
}
