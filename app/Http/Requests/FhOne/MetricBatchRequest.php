<?php

namespace App\Http\Requests\FhOne;

use Illuminate\Validation\Validator;

/**
 * Many metrics in one call (GET /metrics/batch): the metric ids (`ids`,
 * comma-separated), which of them also want the previous period beside them
 * (`compare_ids`), and the dates and filters they all share. Everything else
 * is as for one metric (MetricRequest), including that a parameter nobody knows
 * is refused.
 */
class MetricBatchRequest extends MetricRequest
{
    /** The most metrics one call may ask for: the whole catalog is about sixty. */
    public const MAX_IDS = 60;

    private const LIST = '/^[a-z0-9_.]+(,[a-z0-9_.]+){0,'.(self::MAX_IDS - 1).'}$/';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'ids' => ['required', 'string', 'regex:'.self::LIST],
            'compare_ids' => ['nullable', 'string', 'regex:'.self::LIST],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator) {
            $stray = array_diff($this->compareIds(), $this->ids());

            if ($stray !== []) {
                $validator->errors()->add('compare_ids', 'Only metrics that are in ids can be compared: '.implode(', ', $stray).'.');
            }
        });
    }

    /** @return list<string> the metrics asked for, once each, in the order they were asked */
    public function ids(): array
    {
        return $this->listOf('ids');
    }

    /** @return list<string> the ones that also want the previous period */
    public function compareIds(): array
    {
        return $this->listOf('compare_ids');
    }

    /** @return list<string> */
    private function listOf(string $field): array
    {
        $value = $this->query($field);

        return is_string($value) && $value !== '' ? array_values(array_unique(explode(',', $value))) : [];
    }
}
