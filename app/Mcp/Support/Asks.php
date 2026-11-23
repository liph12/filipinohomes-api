<?php

namespace App\Mcp\Support;

use App\Http\Requests\FhOne\MetricRequest;
use App\Mcp\Policies\McpPolicy;
use App\Services\Reports\Executive\Geography;
use App\Services\Reports\Executive\ReportQueries;
use App\Services\Reports\Executive\ReportRange;
use App\Support\OfficeRegionMap;
use App\Support\RegionMap;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * What a tool call asks, made into what FH One's services take: the days
 * (ReportRange), the filters, and the rules and schema that say what a caller
 * may send. The rules are MetricRequest's own, so FH One's REST API and the
 * MCP server refuse the same things.
 *
 * The filters are the person's (what the policy lets them name) with the
 * policy's forced filters laid over them. The forced ones are applied last and
 * win, so an agent's own agent id cannot be replaced by anything they send.
 */
final class Asks
{
    /** The filters that are ids; the rest are words. */
    private const IDS = ['team_id', 'agent_id', 'type_id', 'project_id', 'city_id', 'province_id'];

    /**
     * Validation rules for the dates and the filters a policy allows.
     *
     * @param  list<string>  $filters
     * @return array<string, mixed>
     */
    public static function rules(array $filters): array
    {
        $rules = (new MetricRequest)->rules();

        return array_intersect_key($rules, array_flip(['date_start', 'date_end', ...$filters]));
    }

    /**
     * The tool schema entries for the dates and those filters.
     *
     * @param  list<string>  $filters
     * @return array<string, mixed>
     */
    public static function schema(JsonSchema $schema, array $filters): array
    {
        $entries = [
            'date_start' => $schema->string()->format('date')->description('First day counted, YYYY-MM-DD (Manila). Leave out dates for the last 30 days. A day after today counts as today; at most 366 days.'),
            'date_end' => $schema->string()->format('date')->description('Last day counted, YYYY-MM-DD, included.'),
        ];

        foreach ($filters as $name) {
            $entries[$name] = match ($name) {
                'team_id' => $schema->integer()->min(0)->description('A team id (find it with the lookup tool, type "teams"). 0 means agents with no team.'),
                'agent_id' => $schema->integer()->min(0)->description('An agent id.'),
                'province_id' => $schema->integer()->min(0)->description('A province id (lookup type "provinces").'),
                'city_id' => $schema->integer()->min(0)->description('A town or city id (lookup type "cities").'),
                'project_id' => $schema->integer()->min(0)->description('A project id (lookup type "projects").'),
                'type_id' => $schema->integer()->min(0)->description('A property type id (lookup type "types").'),
                'office_region' => $schema->string()->enum([...OfficeRegionMap::REGIONS, 'unassigned'])->description('FH office region.'),
                'psa_region' => $schema->string()->enum([...RegionMap::REGIONS, Geography::UNKNOWN_REGION])->description('PSA region of the listing.'),
                'island' => $schema->string()->enum(['luzon', 'visayas', 'mindanao'])->description('Island group.'),
                'category' => $schema->string()->enum(array_keys(ReportQueries::CATEGORIES))->description('Sale, rent or foreclosed.'),
                'status' => $schema->string()->max(16)->description('Listing status, as the metric defines it.'),
                default => throw new InvalidArgumentException("No schema for the filter {$name}."),
            };
        }

        return $entries;
    }

    /**
     * The days asked for, as MetricRequest::range() works them out: the last 30
     * days when none are given.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException when the days are not a usable range
     */
    public static function range(array $input): ReportRange
    {
        $end = is_string($input['date_end'] ?? null) ? $input['date_end'] : ReportRange::today();
        $start = is_string($input['date_start'] ?? null)
            ? $input['date_start']
            : Carbon::createFromFormat('!Y-m-d', $end, ReportRange::TZ)->subDays(29)->format('Y-m-d');

        return ReportRange::resolve($start, $end);
    }

    /**
     * The filters of one ask: what the caller named and the policy allows, with
     * the policy's forced filters on top, in a fixed order (equal asks share a
     * cache entry).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, int|string>
     */
    public static function filters(array $input, McpPolicy $policy, McpActor $actor): array
    {
        $filters = [];

        foreach ($policy->filters() as $name) {
            if (isset($input[$name]) && $input[$name] !== '') {
                $filters[$name] = in_array($name, self::IDS, true) ? (int) $input[$name] : (string) $input[$name];
            }
        }

        $filters = [...$filters, ...$policy->forced($actor)];
        ksort($filters);

        return $filters;
    }
}
