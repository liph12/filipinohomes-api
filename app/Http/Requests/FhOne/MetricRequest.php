<?php

namespace App\Http\Requests\FhOne;

use App\Services\FhOne\BreakdownService;
use App\Services\Reports\Executive\DrillService;
use App\Services\Reports\Executive\Geography;
use App\Services\Reports\Executive\LeadQueries;
use App\Services\Reports\Executive\ReportQueries;
use App\Services\Reports\Executive\ReportRange;
use App\Support\OfficeRegionMap;
use App\Support\RegionMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * What FH One may ask of the metric endpoints: dates, filters, and what each
 * endpoint adds (compare, by/top, paging).
 *
 * Dates are Asia/Manila calendar days (`date_start`, `date_end`, Y-m-d, both
 * included); without them it is the last 30 days. A day after today is
 * clamped to today and a range covers at most 366 days, as in the admin
 * report. The filters are the report's own (see MetricRegistry::filters()),
 * plus `project_ids`: a comma-separated list, for everything one developer
 * built.
 *
 * Unlike the admin report, a parameter nobody knows is refused instead of
 * ignored. A misspelled filter that is silently dropped would hand FH One an
 * unfiltered number that looks like the answer to the question it asked.
 */
class MetricRequest extends FormRequest
{
    /** Filters that are ids (0 selects the "none" bucket where there is one). */
    private const ID_FILTERS = ['city_id', 'province_id', 'team_id', 'agent_id', 'type_id', 'project_id', 'listing_id'];

    private const WORD_FILTERS = ['psa_region', 'island', 'office_region', 'category', 'status', 'role', 'leads'];

    public function authorize(): bool
    {
        return true; // the key was checked by VerifyFhOneKey
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_fill_keys(self::ID_FILTERS, ['nullable', 'integer', 'min:0']) + [
            'project_ids' => ['nullable', 'string', 'regex:/^[1-9]\d{0,8}(,[1-9]\d{0,8}){0,199}$/'],
            'psa_region' => ['nullable', Rule::in([...RegionMap::REGIONS, Geography::UNKNOWN_REGION])],
            'island' => ['nullable', Rule::in(['luzon', 'visayas', 'mindanao'])],
            'office_region' => ['nullable', Rule::in([...OfficeRegionMap::REGIONS, 'unassigned'])],
            'category' => ['nullable', Rule::in(array_keys(ReportQueries::CATEGORIES))],
            'status' => ['nullable', 'string', 'max:16', 'alpha_dash'],
            'role' => ['nullable', 'string', 'max:16', 'alpha_dash'],
            'leads' => ['nullable', Rule::in(array_map('strval', array_keys(LeadQueries::BUCKETS)))],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'],
            'compare' => ['nullable', Rule::in(['none', 'previous'])],
            'by' => ['nullable', Rule::in(array_keys(BreakdownService::DIMENSIONS))],
            'top' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.DrillService::MAX_PER_PAGE],
            'sort' => ['nullable', 'string', 'max:32'],
            'dir' => ['nullable', 'in:asc,desc'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $unknown = array_diff(array_keys($this->query()), array_keys($this->rules()));

            if ($unknown !== []) {
                $validator->errors()->add('query', 'Unknown parameter: '.implode(', ', $unknown).'.');
            }
        });
    }

    /** The requested Manila-day range, or the last 30 days. */
    public function range(): ReportRange
    {
        $end = $this->validated('date_end') ?? ReportRange::today();
        $start = $this->validated('date_start')
            ?? Carbon::createFromFormat('!Y-m-d', $end, ReportRange::TZ)->subDays(29)->format('Y-m-d');

        try {
            return ReportRange::resolve($start, $end);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['date_start' => $e->getMessage()]);
        }
    }

    /**
     * The filters on the request, cleaned and in a fixed order (so equal asks
     * share a cache entry). Whether they suit the metric is checked by the
     * service, which knows the metric.
     *
     * @return array<string, int|string|list<int>>
     */
    public function filters(): array
    {
        $data = $this->validated();
        $filters = [];

        foreach ([...self::ID_FILTERS, ...self::WORD_FILTERS] as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $filters[$key] = in_array($key, self::ID_FILTERS, true) ? (int) $data[$key] : (string) $data[$key];
            }
        }

        if (isset($data['project_ids'])) {
            $ids = array_values(array_unique(array_map('intval', explode(',', (string) $data['project_ids']))));
            sort($ids);
            $filters['project_ids'] = $ids;
        }

        ksort($filters);

        return $filters;
    }
}
