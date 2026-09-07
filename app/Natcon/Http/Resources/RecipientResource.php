<?php

namespace App\Natcon\Http\Resources;

use App\Natcon\Models\FormField;
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
    /**
     * ⚠️ NEVER make this a promoted constructor parameter.
     *
     * `RecipientResource::collection()` builds its items through
     * `Collection::mapInto()`, which calls `new static($value, $key)` — it
     * passes the array KEY as the second argument. With `$detailed` promoted
     * into that slot, every row except index 0 was constructed with a truthy
     * int and serialised in DETAIL mode: the list leaked `lr_payload` (the very
     * thing the docblock above says is withheld), ran three extra queries per
     * row, and 500'd on the first row whose sales_team was null.
     *
     * A plain property plus a named factory keeps the constructor to the one
     * argument JsonResource itself declares, so a stray positional argument has
     * nowhere to land.
     */
    private bool $detailed = false;

    public static function detailed(Recipient $recipient): self
    {
        $resource = new self($recipient);
        $resource->detailed = true;

        return $resource;
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

            /**
             * The answers the admin chose to see in the LIST, so shirt sizes
             * can be read down a column instead of by opening 300 drawers.
             *
             * Which questions appear is data, not code: a field opts in with
             * `config.show_in_list`. Never keyed on 'polo_shirt_size' — §5 of
             * the module's notes, "the form is admin-defined, never
             * special-case a field key".
             */
            'list_answers' => $this->listAnswers($r),
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
                // ?? not ?: — the elvis operator still evaluates the array
                // access first, so a null sales_team threw "Trying to access
                // array offset on null" rather than yielding null. Every
                // sibling line here already used ??; this one was the outlier.
                'team_logo'         => $r->qualifier_payload['sales_team_member']['sales_team']['teamlogo'] ?? null,
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

    /**
     * Types that are never a list column, whatever the flag says. A paragraph
     * of text and a set of uploaded images are not something you scan down a
     * 150px column — the drawer shows those in full.
     */
    private const NEVER_IN_LIST = [
        FormField::TYPE_SECTION,      // not an input at all
        FormField::TYPE_LONG_TEXT,
        FormField::TYPE_IMAGE_UPLOAD,
    ];

    /**
     * Which fields the awardee list shows, per event.
     *
     * ⚠️ `show_in_list` DEFAULTS TO TRUE. An admin who adds a question wants to
     * see the answers, and the flag existing at all was no help to the two 2026
     * questions that were saved before it did — their config has no such key,
     * and opt-in left the table exactly as it was. So it reads as "shown unless
     * someone turned it off", and the field editor writes the boolean either
     * way rather than omitting it when false.
     *
     * Static because a 100-row page builds 100 resources and the answer is the
     * same for all of them — without it this is a fields query per row.
     * Request-scoped: PHP-FPM discards it, so an admin toggling the flag is one
     * page refresh away from seeing the change.
     *
     * @return array<int, FormField>
     */
    private static function listFields(int $eventId): array
    {
        static $cache = [];

        if (! array_key_exists($eventId, $cache)) {
            $cache[$eventId] = FormField::query()
                ->where('natcon_event_id', $eventId)
                ->where('is_active', true)
                ->whereNotIn('type', self::NEVER_IN_LIST)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->filter(fn ($f) => (bool) (($f->config ?? [])['show_in_list'] ?? true))
                ->values()
                ->all();
        }

        return $cache[$eventId];
    }

    /**
     * The flagged questions as list columns: one entry per flagged field in
     * form order, emitted even for an awardee who has not submitted — so every
     * row on a page carries the same columns and the table can build its grid
     * from any of them.
     *
     * `people` rather than a flat string because 118 of the 2026 awardees are
     * couples on one login: "Medium" for a pair says nothing about who wears
     * which. A per_person field stores a positional array aligned to
     * personNames(), so the two zip together; a single answer yields one entry
     * with a null name and the UI prints the value alone.
     *
     * @return array<int,array{key:string,label:string,people:array<int,array{name:?string,value:string}>}>
     */
    private function listAnswers(Recipient $r): array
    {
        $fields = self::listFields((int) $r->natcon_event_id);

        if ($fields === []) {
            return [];
        }

        $answers = $r->formSubmission?->answerMap() ?? [];
        $names   = $r->personNames();
        $out     = [];

        foreach ($fields as $field) {
            $value = $answers[$field->key] ?? null;

            /*
             * Read from `answers`, not from the snapshot's display_value: the
             * snapshot pre-joins a couple into one string ("Ana: Medium · Ben:
             * Large"), which is precisely what a column of its own has to take
             * apart again. `answers` keeps a per-person field as a positional
             * array aligned to personNames(), so the split is exact — and the
             * choice values get translated back to labels below, or the column
             * would read "medium".
             */
            $labels = [];

            foreach ((array) ($field->choices ?? []) as $choice) {
                // Retired choices included. Someone already answered with one,
                // and printing its slug is worse than a stale label.
                if (is_array($choice) && isset($choice['value'])) {
                    $labels[(string) $choice['value']] = (string) ($choice['label'] ?? $choice['value']);
                }
            }

            $render = function (mixed $one) use ($labels): ?string {
                // Checkbox answers are arrays at the level of ONE person, so
                // each element is looked up on its own before joining.
                if (is_array($one)) {
                    $parts = [];

                    foreach ($one as $item) {
                        $text = $this->flattenAnswer($item);
                        if ($text !== null) {
                            $parts[] = $labels[$text] ?? $text;
                        }
                    }

                    return $parts === [] ? null : implode(', ', $parts);
                }

                $text = $this->flattenAnswer($one);

                return $text === null ? null : ($labels[$text] ?? $text);
            };

            $people = [];

            /*
             * Branch on the FIELD, never on whether the value happens to be an
             * array: a checkbox field stores an array for one person, and
             * treating that as per-person answers would print "Ana — Beef,
             * Ben — Chicken" for one awardee's two dinner choices.
             */
            if ((bool) (($field->config ?? [])['per_person'] ?? false) && is_array($value)) {
                // Named only when there is more than one person to tell apart —
                // the same rule FormService labels its snapshot by. On a solo
                // awardee the row already says who they are.
                $named = count($names) > 1;

                foreach (array_values($value) as $i => $one) {
                    $text = $render($one);
                    if ($text !== null) {
                        $people[] = [
                            'name'  => $named ? ($names[$i] ?? null) : null,
                            'value' => $text,
                        ];
                    }
                }
            } else {
                $text = $render($value);
                if ($text !== null) {
                    $people[] = ['name' => null, 'value' => $text];
                }
            }

            $out[] = [
                'key'    => (string) $field->key,
                'label'  => (string) ($field->label ?: $field->key),
                'people' => $people,
            ];
        }

        return $out;
    }

    private function flattenAnswer(mixed $value): ?string
    {
        if (is_array($value)) {
            $parts = array_filter(
                array_map(fn ($v) => $this->flattenAnswer($v), $value),
                fn ($v) => $v !== null,
            );

            return $parts === [] ? null : implode(', ', $parts);
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
