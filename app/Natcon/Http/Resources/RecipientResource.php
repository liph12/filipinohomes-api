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
            'form_answers' => $r->formSubmission()->first()?->answerMap() ?? (object) [],
        ];
    }

    private function iso($value, string $tz): ?string
    {
        return $value?->copy()->setTimezone($tz)->toIso8601String();
    }
}
