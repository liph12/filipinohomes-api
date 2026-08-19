<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\FormField;
use App\Natcon\Services\FormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin CRUD for the customizable NATCON form.
 *
 * Two rules run through everything here:
 *
 *   1. A field somebody has already answered is NEVER hard-deleted — it's
 *      deactivated, so historical answers stay readable and the export stays
 *      honest. This used to be a database invariant (a restrictOnDelete FK on
 *      the answers table); with answers stored as JSON on the submission it is
 *      enforced here instead. Note the blast radius went DOWN, not up: deleting
 *      a field no longer destroys any answer data at all.
 *   2. Every write busts the schema cache, or the awardee page serves a stale
 *      form for up to 5 minutes and the admin thinks the save failed.
 */
class FormFieldController extends Controller
{
    public function __construct(private FormService $forms) {}

    /** Full editable schema, including inactive rows (the public one hides those). */
    public function index(Request $request): JsonResponse
    {
        $event  = $this->resolveEvent($request);
        $fields = $this->forms->fields($event, activeOnly: false);
        $counts = $this->forms->answerCounts($event);

        return response()->json([
            'data' => $fields->map(fn (FormField $f) => $this->present($f, (int) ($counts[$f->key] ?? 0))),
            'meta' => [
                'types' => FormField::TYPES,
                'event' => [
                    'id'   => $event->id,
                    'slug' => $event->slug,
                    'year' => $event->year,
                    'name' => $event->name,
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $event = $this->resolveEvent($request);
        $data  = $this->validateField($request, $event, null);

        $field = FormField::create([
            'natcon_event_id'  => $event->id,
            'key'              => $data['key'],
            'label'            => $data['label'],
            'help_text'        => $data['help_text'] ?? null,
            'type'             => $data['type'],
            'is_required'      => $data['is_required'] ?? false,
            'is_active'        => $data['is_active'] ?? true,
            'sort_order'       => $data['sort_order'] ?? $this->nextSortOrder($event),
            'illustration_url' => $data['illustration_url'] ?? null,
            'section'          => $data['section'] ?? 'general',
            'config'           => $data['config'] ?? null,
            'choices'          => $data['choices'] ?? null,
        ]);

        $this->forms->forgetSchema($event);

        return response()->json(['data' => $this->present($field->fresh(), 0)], 201);
    }

    public function update(Request $request, FormField $field): JsonResponse
    {
        $event = $field->event;
        $data  = $this->validateField($request, $event, $field);
        $used  = $this->answerCount($field);

        // The key is what answers are stored against. Renaming it after people
        // have answered would strand their values, so it freezes once in use.
        if (isset($data['key']) && $data['key'] !== $field->key && $used > 0) {
            unset($data['key']);
        }

        // Merging retired choices back in keeps a recorded answer resolvable to
        // a label even after the admin removes that option from the form.
        if (array_key_exists('choices', $data)) {
            $data['choices'] = $this->mergeRetiredChoices($field, $data['choices'] ?? []);
        }

        $field->fill($data)->save();

        $this->forms->forgetSchema($event);

        return response()->json(['data' => $this->present($field->fresh(), $used)]);
    }

    /**
     * Delete when untouched, deactivate when it has answers. The distinction is
     * reported back so the UI can say "hidden, existing answers kept" rather
     * than pretending it vanished.
     */
    public function destroy(FormField $field): JsonResponse
    {
        $event = $field->event;
        $used  = $this->answerCount($field);

        if ($used > 0) {
            $field->forceFill(['is_active' => false])->save();
            $this->forms->forgetSchema($event);

            return response()->json([
                'message'     => "Hidden from the form. {$used} existing answer(s) were kept.",
                'deactivated' => true,
            ]);
        }

        $field->delete();
        $this->forms->forgetSchema($event);

        return response()->json(['message' => 'Question deleted.', 'deactivated' => false]);
    }

    /** Persist a drag-reorder. Sparse steps of 10 leave room to insert between. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => 'required|array|max:200',
            'ids.*' => 'integer|exists:natcon_form_fields,id',
        ]);

        $event = $this->resolveEvent($request);

        DB::transaction(function () use ($data, $event) {
            foreach ($data['ids'] as $index => $id) {
                FormField::where('id', $id)
                    ->where('natcon_event_id', $event->id)
                    ->update(['sort_order' => ($index + 1) * 10]);
            }
        });

        $this->forms->forgetSchema($event);

        return response()->json(['message' => 'Order saved.']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function validateField(Request $request, NatconEvent $event, ?FormField $field): array
    {
        $id = $field?->id ?? 'NULL';

        $rules = [
            'label'            => ($field ? 'sometimes|' : 'required|') . 'string|max:255',
            'type'             => ($field ? 'sometimes|' : 'required|') . 'in:' . implode(',', FormField::TYPES),
            'key'              => [
                $field ? 'sometimes' : 'nullable',
                'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                "unique:natcon_form_fields,key,{$id},id,natcon_event_id,{$event->id}",
            ],
            'help_text'        => 'nullable|string|max:512',
            'is_required'      => 'boolean',
            'is_active'        => 'boolean',
            'sort_order'       => 'nullable|integer|min:0|max:65000',
            'illustration_url' => 'nullable|url|max:2048',
            'section'          => 'nullable|string|max:64',
            'config'           => 'nullable|array',

            'choices'              => 'nullable|array|max:60',
            'choices.*.value'      => 'required|string|max:128|regex:/^[A-Za-z0-9_\-]+$/',
            'choices.*.label'      => 'required|string|max:255',
            'choices.*.help_text'  => 'nullable|string|max:255',
            'choices.*.image_url'  => 'nullable|url|max:2048',
        ];

        $data = $request->validate($rules, [
            'key.regex'             => 'The key must be lowercase letters, numbers and underscores, starting with a letter.',
            'choices.*.value.regex' => 'Choice values may only contain letters, numbers, hyphens and underscores.',
        ]);

        // ⚠️ Choices no longer have a UNIQUE(field, value) index behind them, and
        //    the reader builds value => label with pluck(), which silently keeps
        //    the LAST duplicate. Without this check a repeated value would make
        //    one option unselectable in a way that looks like a rendering bug.
        if (! empty($data['choices'])) {
            $values = array_column($data['choices'], 'value');

            if (count($values) !== count(array_unique($values))) {
                $dupes = implode(', ', array_unique(array_diff_assoc($values, array_unique($values))));

                throw \Illuminate\Validation\ValidationException::withMessages([
                    'choices' => ["Choice values must be unique. Duplicated: {$dupes}."],
                ]);
            }
        }

        // Auto-derive the machine key from the label on create so an admin never
        // has to think about it.
        if (! $field && empty($data['key'])) {
            $data['key'] = $this->uniqueKey($event, $data['label']);
        }

        $type = $data['type'] ?? $field?->type;

        // A section header is a layout element, not an input.
        if ($type === FormField::TYPE_SECTION) {
            $data['is_required'] = false;
        }

        if (array_key_exists('choices', $data)) {
            $data['choices'] = in_array($type, FormField::CHOICE_TYPES, true)
                ? $this->normalizeChoices($data['choices'] ?? [])
                : null;
        }

        return $data;
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeChoices(array $choices): array
    {
        return collect($choices)->values()->map(fn ($c) => [
            'value'     => $c['value'],
            'label'     => $c['label'],
            'help_text' => $c['help_text'] ?? null,
            'image_url' => $c['image_url'] ?? null,
            'is_active' => true,
        ])->all();
    }

    /**
     * Carry forward any choice the admin removed, flagged inactive.
     *
     * A recorded answer stores the choice VALUE, so dropping the option outright
     * would leave an old submission rendering a bare machine string with no
     * label. Retired options are hidden from the form but still resolvable.
     *
     * @param  array<int,array<string,mixed>>  $incoming
     * @return array<int,array<string,mixed>>
     */
    private function mergeRetiredChoices(FormField $field, array $incoming): array
    {
        $keep = array_column($incoming, 'value');

        $retired = collect($field->allChoices())
            ->reject(fn ($c) => in_array($c['value'], $keep, true))
            ->map(fn ($c) => ['is_active' => false] + $c)
            ->values()
            ->all();

        return array_merge($incoming, $retired);
    }

    private function uniqueKey(NatconEvent $event, string $label): string
    {
        // ⚠️ lower() BEFORE snake(). Str::snake inserts an underscore ahead of
        //    every capital, so an acronym shatters: "NATCON Polo Shirt Size"
        //    became n_a_t_c_o_n_polo_shirt_size and "FH VIP Notes" would become
        //    f_h_v_i_p_notes. The live 2026 field carries the mangled key to this
        //    day — it is left alone deliberately, since the key is what the
        //    stored answers are filed under and renaming it would orphan them.
        //    Nobody sees it now that labels come from the submission snapshot.
        $base = Str::snake(Str::lower(Str::ascii($label)));
        $base = preg_replace('/[^a-z0-9_]/', '', $base) ?: 'field';
        $base = ltrim($base, '0..9_') ?: 'field';
        $base = Str::limit($base, 60, '');

        $key = $base;
        $i   = 2;

        while (FormField::where('natcon_event_id', $event->id)->where('key', $key)->exists()) {
            $key = Str::limit($base, 58, '') . '_' . $i++;
        }

        return $key;
    }

    private function nextSortOrder(NatconEvent $event): int
    {
        return ((int) FormField::where('natcon_event_id', $event->id)->max('sort_order')) + 10;
    }

    /**
     * How many submissions answered this one field.
     *
     * ⚠️ JSON_CONTAINS_PATH alone is not enough: it returns 1 for a stored JSON
     *    null, and `JSON_EXTRACT(...) IS NOT NULL` will NOT filter that, because
     *    JSON_EXTRACT returns the JSON literal null rather than SQL NULL. Hence
     *    the JSON_TYPE guard. (FormService never writes empty keys, so this
     *    is belt-and-braces against hand-edited rows.)
     */
    private function answerCount(FormField $field): int
    {
        $path = '$."' . $field->key . '"';

        return (int) DB::table('natcon_form_submissions')
            ->where('natcon_event_id', $field->natcon_event_id)
            ->whereRaw('JSON_CONTAINS_PATH(answers, \'one\', ?)', [$path])
            ->whereRaw('JSON_TYPE(JSON_EXTRACT(answers, ?)) <> \'NULL\'', [$path])
            ->count();
    }

    private function present(FormField $field, int $answerCount): array
    {
        return [
            'id'               => $field->id,
            'key'              => $field->key,
            'label'            => $field->label,
            'help_text'        => $field->help_text,
            'type'             => $field->type,
            'is_required'      => (bool) $field->is_required,
            'is_active'        => (bool) $field->is_active,
            'sort_order'       => (int) $field->sort_order,
            'illustration_url' => $field->illustration_url,
            'section'          => $field->section,
            'config'           => $field->config ?? (object) [],
            'answer_count'     => $answerCount,
            // Drives the UI lock on the key field and the delete-vs-hide copy.
            'in_use'           => $answerCount > 0,
            'choices'          => collect($field->allChoices())->map(fn ($c) => [
                // Choices have no database id any more. `value` is unique per
                // field and regex-constrained, so it's a safe React key — which
                // is the only thing the admin UI used the id for.
                'id'        => $c['value'],
                'value'     => $c['value'],
                'label'     => $c['label'] ?? $c['value'],
                'help_text' => $c['help_text'] ?? null,
                'image_url' => $c['image_url'] ?? null,
                'is_active' => ($c['is_active'] ?? true) !== false,
            ])->values(),
        ];
    }

    private function resolveEvent(Request $request): NatconEvent
    {
        $event = $request->filled('event_id')
            ? NatconEvent::find($request->integer('event_id'))
            : NatconEvent::active();

        abort_unless($event, 404, 'No NATCON event found.');

        return $event;
    }
}
