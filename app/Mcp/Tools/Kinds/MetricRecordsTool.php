<?php

namespace App\Mcp\Tools\Kinds;

use App\Mcp\Support\Asks;
use App\Mcp\Support\McpActor;
use App\Services\FhOne\MetricService;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The records behind a number (the listings, inquiries or deals it counts), a
 * page at a time, so an answer can be checked against what was counted. The
 * page is kept small (config('fhmcp.max_per_page')): this is for looking, not
 * for taking everything out.
 */
abstract class MetricRecordsTool extends MetricTool
{
    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        $max = (int) config('fhmcp.max_per_page', 25);

        return [
            'metric' => $schema->string()->max(80)->required()->description('The number\'s id, like "listings.live". Find it with the catalog tool.'),
            'page' => $schema->integer()->min(1)->max(1000)->default(1)->description('Page number, from 1.'),
            'per_page' => $schema->integer()->min(1)->max($max)->default(10)->description("Rows on a page, up to {$max} (default 10)."),
            'sort' => $schema->string()->max(32)->description('A column to sort by, as it appears in the records\' columns.'),
            'dir' => $schema->string()->enum(['asc', 'desc'])->description('Sort direction.'),
            ...Asks::schema($schema, $this->policy()->filters()),
        ];
    }

    protected function rules(): array
    {
        return [
            'metric' => ['required', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('fhmcp.max_per_page', 25)],
            'sort' => ['nullable', 'string', 'max:32'],
            'dir' => ['nullable', 'in:asc,desc'],
            ...Asks::rules($this->policy()->filters()),
        ];
    }

    protected function answer(array $input, McpActor $actor): array
    {
        return app(MetricService::class)->records(
            $this->metric((string) $input['metric']),
            Asks::range($input),
            Asks::filters($input, $this->policy(), $actor),
            (int) ($input['page'] ?? 1),
            (int) ($input['per_page'] ?? 10),
            isset($input['sort']) ? (string) $input['sort'] : null,
            isset($input['dir']) ? (string) $input['dir'] : null,
        );
    }
}
