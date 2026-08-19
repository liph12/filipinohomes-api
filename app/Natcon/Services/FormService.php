<?php

namespace App\Natcon\Services;

use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\FormField;
use App\Natcon\Models\FormSubmission;
use App\Natcon\Models\Recipient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Serves the convention form schema and stores answers.
 *
 * Both the schema (choices) and the answers are JSON now — see the migration
 * docblocks for why the child tables went away. The shapes that matter:
 *
 *   natcon_form_fields.choices        [{value,label,help_text,image_url,is_active}]
 *   natcon_form_submissions.answers   {field_key: value}      ← queried
 *   ..._submissions.answers_snapshot  [{key,label,type,value,display_value}]  ← displayed
 */
final class FormService
{
    private const CACHE_TTL = 300;

    /**
     * Ordered, active fields in the wire shape the frontend renderer consumes.
     * Keys mirror the TypeScript discriminated union in src/lib/natcon/form.ts —
     * rename one side and you must rename the other.
     *
     * @return array<int,array<string,mixed>>
     */
    public function schemaFor(NatconEvent $event): array
    {
        return Cache::remember(
            "natcon:schema:{$event->id}",
            self::CACHE_TTL,
            fn () => $this->buildSchema($event),
        );
    }

    public function forgetSchema(NatconEvent $event): void
    {
        Cache::forget("natcon:schema:{$event->id}");
    }

    /** @return array<int,array<string,mixed>> */
    private function buildSchema(NatconEvent $event): array
    {
        return $this->fields($event, activeOnly: true)
            ->map(fn (FormField $f) => $this->presentField($f))
            ->values()
            ->all();
    }

    /** @return \Illuminate\Support\Collection<int,FormField> */
    public function fields(NatconEvent $event, bool $activeOnly = true)
    {
        return FormField::query()
            ->where('natcon_event_id', $event->id)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Has this awardee answered every required question?
     *
     * True when the event asks nothing required — an event with no required
     * fields must not become impossible to complete.
     *
     * ⚠️ Checks the ANSWERS, not form_submitted_at. Someone can submit the form
     *    while a field is optional and have it turned required afterwards; the
     *    timestamp would still say "submitted" while the answer the events team
     *    needs is missing. Reading the stored answers means adding a required
     *    question correctly reopens whoever never answered it.
     */
    public function hasRequiredAnswers(Recipient $recipient): bool
    {
        return $this->missingRequiredLabels($recipient) === [];
    }

    /**
     * Labels of the required questions this awardee still hasn't answered.
     *
     * Returned as LABELS rather than keys because the only two consumers are
     * human-facing — the reminder email and the awardee page both have to name
     * the thing being asked for. "We still need your NATCON Polo Shirt Size"
     * is a message someone can act on; "polo_shirt_size is missing" is not.
     *
     * @return array<int,string>
     */
    public function missingRequiredLabels(Recipient $recipient): array
    {
        $event = $recipient->event;

        if (! $event) {
            return [];
        }

        $required = $this->fields($event, activeOnly: true)
            ->filter(fn (FormField $f) => $f->isInput() && $f->is_required);

        if ($required->isEmpty()) {
            return [];
        }

        $answers = FormSubmission::where('natcon_event_id', $event->id)
            ->where('natcon_recipient_id', $recipient->id)
            ->value('answers') ?? [];

        $people = max(1, count($recipient->personNames()));

        return $required
            ->filter(function (FormField $f) use ($answers, $people) {
                $raw = $answers[$f->key] ?? null;

                if (! $this->isPerPerson($f)) {
                    return $this->isBlank($raw);
                }

                // ⚠️ Not isBlank(). A couple who answered before per_person
                //    existed has a bare string stored, and a couple who answered
                //    for one of two has ["large", null] — array_filter would call
                //    both "answered" and mark them complete with a size missing.
                //    Every seat has to be filled, and there have to be as many
                //    entries as there are people.
                $entries = is_array($raw) ? $raw : [$raw];

                if (count($entries) < $people) {
                    return true;
                }

                foreach (array_slice($entries, 0, $people) as $entry) {
                    if ($this->isBlank($entry)) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('label')
            ->values()
            ->all();
    }

    /** Empty in the same terms normalize() uses, so both agree on "unanswered". */
    private function isBlank($raw): bool
    {
        return $raw === null || $raw === ''
            || (is_array($raw) && count(array_filter($raw, fn ($v) => $v !== null && $v !== '')) === 0);
    }

    /** @return array<string,mixed> */
    private function presentField(FormField $f): array
    {
        $out = [
            'id'               => $f->key,
            'label'            => $f->label,
            'help_text'        => $f->help_text,
            'type'             => $f->type,
            'required'         => (bool) $f->is_required && $f->isInput(),
            'sort_order'       => (int) $f->sort_order,
            'section'          => $f->section,
            'illustration_url' => $f->illustration_url,
        ];

        // Type-specific extras are flattened onto the field so the renderer
        // reads field.max_length rather than field.config.max_length.
        foreach ((array) ($f->config ?? []) as $k => $v) {
            if (! array_key_exists($k, $out)) {
                $out[$k] = $v;
            }
        }

        if ($f->isChoiceType()) {
            $out['choices'] = collect($f->activeChoices())->map(fn ($c) => [
                'value'     => $c['value'],
                'label'     => $c['label'] ?? $c['value'],
                'help_text' => $c['help_text'] ?? null,
                'image_url' => $c['image_url'] ?? null,
            ])->values()->all();
        }

        return $out;
    }

    /**
     * How many submissions answered each field, keyed by field key.
     *
     * One query rather than N. JSON_TABLE over JSON_KEYS gives exactly the
     * "answered fields per submission" expansion we need; it requires MySQL
     * 8.0.4+ (this deployment is on 9.5). Falls back to counting in PHP if the
     * server is older — at ~1,000 rows that's a couple of MB and entirely fine.
     *
     * @return array<string,int>
     */
    public function answerCounts(NatconEvent $event): array
    {
        try {
            $rows = DB::select(
                'SELECT jt.k AS k, COUNT(*) AS n
                   FROM natcon_form_submissions s,
                        JSON_TABLE(JSON_KEYS(s.answers), \'$[*]\'
                                   COLUMNS (k VARCHAR(64) PATH \'$\')) jt
                  WHERE s.natcon_event_id = ?
                  GROUP BY jt.k',
                [$event->id],
            );

            return collect($rows)->pluck('n', 'k')->map(fn ($n) => (int) $n)->all();
        } catch (\Throwable $e) {
            $counts = [];

            FormSubmission::where('natcon_event_id', $event->id)
                ->select('answers')
                ->chunk(500, function ($chunk) use (&$counts) {
                    foreach ($chunk as $submission) {
                        foreach (array_keys($submission->answerMap()) as $key) {
                            $counts[$key] = ($counts[$key] ?? 0) + 1;
                        }
                    }
                });

            return $counts;
        }
    }

    /**
     * Validate and persist a submission.
     *
     * Answers arrive keyed by field key. Unknown keys are dropped rather than
     * rejected: an awardee with a stale tab open after an admin retires a field
     * should still be able to submit the rest.
     *
     * @param  array<string,mixed>  $answers
     * @throws ValidationException
     */
    public function submit(
        Recipient $recipient,
        array $answers,
        ?string $ip = null,
        ?string $userAgent = null,
    ): FormSubmission {
        $event  = $recipient->event;
        $fields = $this->fields($event, activeOnly: true);

        $errors   = [];
        $stored   = [];
        $snapshot = [];

        foreach ($fields as $field) {
            if (! $field->isInput()) {
                continue;
            }

            $raw = $answers[$field->key] ?? null;

            [$error, $value, $display] = $this->isPerPerson($field)
                ? $this->normalizePerPerson($field, $raw, $recipient->personNames())
                : $this->normalize($field, $raw);

            if ($error !== null) {
                $errors["answers.{$field->key}"] = [$error];
                continue;
            }

            // ⚠️ Skip empty answers entirely rather than storing null. The
            //    "is this field in use?" check is JSON_CONTAINS_PATH, which
            //    treats a stored null as present — and would then refuse to let
            //    an admin delete a field nobody actually filled in.
            if ($value === null) {
                continue;
            }

            $stored[$field->key] = $value;

            $snapshot[] = [
                'key'           => $field->key,
                'label'         => $field->label,
                'type'          => $field->type,
                'value'         => $value,
                'display_value' => $display,
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($recipient, $event, $stored, $snapshot, $ip, $userAgent) {
            $submission = FormSubmission::updateOrCreate(
                [
                    'natcon_event_id'     => $event->id,
                    'natcon_recipient_id' => $recipient->id,
                ],
                [
                    'answers'              => $stored,
                    'answers_snapshot'     => $snapshot,
                    'submitted_ip'         => $ip,
                    'submitted_user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                ],
            );

            $recipient->forceFill(['form_submitted_at' => Carbon::now()])->save();

            // ⚠️ Answering the form can now COMPLETE someone, so completion has
            //    to be recomputed here as well as on photo changes. Without this
            //    an awardee who sent every photo and then filled in their shirt
            //    size would sit at details_pending for ever and keep receiving
            //    reminders for something they had already done.
            app(PhotoService::class)->syncResponseState($recipient);

            $event->forceFill([
                'form_submitted_count' => FormSubmission::where('natcon_event_id', $event->id)->count(),
            ])->save();

            return $submission;
        });
    }

    /** Does this question get asked once per person on the account? */
    private function isPerPerson(FormField $field): bool
    {
        return $field->isInput() && (bool) $field->config('per_person', false);
    }

    /**
     * A per-person field: one answer per human, validated by the SAME rules.
     *
     * 118 of the 292 awardees for 2026 are couples on one login, and a single
     * polo-shirt answer was being collected for two people. Rather than
     * special-casing that question, any field can be marked per_person and this
     * asks it once for each name on the account.
     *
     * ⚠️ Delegates to normalize() per entry rather than reimplementing the type
     *    rules. A second copy of "is this a valid choice" would drift from the
     *    first the moment a field type is added.
     *
     * Stored as a positional array aligned to personNames(); the display string
     * carries the names, so the export and the admin drawer can attribute a size
     * to a person without re-splitting anything.
     *
     * @param  array<int,string>  $people
     * @return array{0:?string, 1:mixed, 2:?string}
     */
    private function normalizePerPerson(FormField $field, $raw, array $people): array
    {
        $values = [];
        $labels = [];
        $named  = count($people) > 1;

        foreach (array_values($people) as $i => $person) {
            // A legacy scalar answers for the first person only — that is exactly
            // what the 16 couples who answered before this existed have stored.
            $entry = is_array($raw) ? ($raw[$i] ?? null) : ($i === 0 ? $raw : null);

            [$error, $value, $display] = $this->normalize($field, $entry);

            if ($error !== null) {
                return [$named ? "{$person} — {$error}" : $error, null, null];
            }

            $values[] = $value;
            $labels[] = $named ? "{$person}: " . ($display ?? '—') : (string) ($display ?? '');
        }

        // Optional and untouched by everyone: unanswered, not an array of nulls.
        $answered = array_filter($values, fn ($v) => $v !== null && $v !== '' && $v !== []);

        if ($answered === []) {
            return [null, null, null];
        }

        return [null, $values, implode(' · ', $labels)];
    }

    /**
     * Validate one field's value.
     *
     * @return array{0:?string, 1:mixed, 2:?string}  [error, stored value, display value]
     */
    private function normalize(FormField $field, $raw): array
    {
        $isEmpty = $raw === null || $raw === ''
            || (is_array($raw) && count(array_filter($raw, fn ($v) => $v !== null && $v !== '')) === 0);

        if ($isEmpty) {
            return $field->is_required
                ? ["{$field->label} is required.", null, null]
                : [null, null, null];
        }

        if ($field->isChoiceType()) {
            $valid    = $field->choiceLabels();
            $selected = $field->isMulti() ? array_values((array) $raw) : [$raw];

            if (! $field->isMulti() && count($selected) > 1) {
                return ["{$field->label} accepts a single choice.", null, null];
            }

            $values = [];
            $labels = [];

            foreach ($selected as $value) {
                $value = is_scalar($value) ? (string) $value : '';

                if (! array_key_exists($value, $valid)) {
                    return ["\"{$value}\" is not an option for {$field->label}.", null, null];
                }

                $values[] = $value;
                $labels[] = $valid[$value];
            }

            $min = (int) $field->config('min_select', 0);
            $max = (int) $field->config('max_select', 0);

            if ($min > 0 && count($values) < $min) {
                return ["Choose at least {$min} option(s) for {$field->label}.", null, null];
            }
            if ($max > 0 && count($values) > $max) {
                return ["Choose at most {$max} option(s) for {$field->label}.", null, null];
            }

            return [null, $field->isMulti() ? $values : $values[0], implode(', ', $labels)];
        }

        return match ($field->type) {
            FormField::TYPE_NUMBER       => $this->number($field, $raw),
            FormField::TYPE_DATE         => $this->date($field, $raw),
            FormField::TYPE_EMAIL        => $this->email($field, $raw),
            FormField::TYPE_IMAGE_UPLOAD => $this->images($field, $raw),
            default                            => $this->text($field, $raw),
        };
    }

    private function text(FormField $field, $raw): array
    {
        $value = trim((string) $raw);
        $max   = (int) $field->config('max_length', $field->type === FormField::TYPE_LONG_TEXT ? 5000 : 255);

        if (mb_strlen($value) > $max) {
            return ["{$field->label} must be {$max} characters or fewer.", null, null];
        }

        return [null, $value, $value];
    }

    private function email(FormField $field, $raw): array
    {
        $value = strtolower(trim((string) $raw));

        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ["{$field->label} must be a valid email address.", null, null];
        }

        return [null, $value, $value];
    }

    private function number(FormField $field, $raw): array
    {
        if (! is_numeric($raw)) {
            return ["{$field->label} must be a number.", null, null];
        }

        $value = 0 + $raw;
        $min   = $field->config('min');
        $max   = $field->config('max');

        if ($min !== null && $value < (float) $min) {
            return ["{$field->label} must be at least {$min}.", null, null];
        }
        if ($max !== null && $value > (float) $max) {
            return ["{$field->label} must be at most {$max}.", null, null];
        }

        return [null, $value, (string) $raw];
    }

    private function date(FormField $field, $raw): array
    {
        try {
            $value = Carbon::parse((string) $raw);
        } catch (\Throwable $e) {
            return ["{$field->label} must be a valid date.", null, null];
        }

        return [null, $value->toDateString(), $value->format('F j, Y')];
    }

    private function images(FormField $field, $raw): array
    {
        $urls = array_values(array_filter(
            (array) $raw,
            fn ($u) => is_string($u) && filter_var($u, FILTER_VALIDATE_URL),
        ));

        if (! $urls) {
            return ["{$field->label} must be an uploaded image.", null, null];
        }

        $max = (int) $field->config('max_files', 1);
        if ($max > 0 && count($urls) > $max) {
            return ["{$field->label} accepts at most {$max} image(s).", null, null];
        }

        return [null, $urls, implode(', ', $urls)];
    }
}
