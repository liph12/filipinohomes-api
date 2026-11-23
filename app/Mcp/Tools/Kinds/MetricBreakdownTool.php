<?php

namespace App\Mcp\Tools\Kinds;

use App\Mcp\Support\Asks;
use App\Mcp\Support\McpActor;
use App\Services\FhOne\MetricService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;

/**
 * A number split by something (team, project, province...): the top rows. The
 * splits open to this person are the policy's: an agent cannot split by agent
 * or team, so their numbers never lead to anyone else's.
 */
abstract class MetricBreakdownTool extends MetricTool
{
    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'metric' => $schema->string()->max(80)->required()->description('The number\'s id, like "deals.sold". Find it with the catalog tool.'),
            'by' => $schema->string()->enum($this->policy()->dimensions())->required()->description('What to split it by.'),
            'top' => $schema->integer()->min(1)->max(50)->default(10)->description('How many rows, biggest first (default 10).'),
            ...Asks::schema($schema, $this->policy()->filters()),
        ];
    }

    protected function rules(): array
    {
        return [
            'metric' => ['required', 'string', 'max:80'],
            'by' => ['required', Rule::in($this->policy()->dimensions())],
            'top' => ['nullable', 'integer', 'min:1', 'max:50'],
            ...Asks::rules($this->policy()->filters()),
        ];
    }

    protected function answer(array $input, McpActor $actor): array
    {
        return app(MetricService::class)->breakdown(
            $this->metric((string) $input['metric']),
            (string) $input['by'],
            Asks::range($input),
            Asks::filters($input, $this->policy(), $actor),
            (int) ($input['top'] ?? 10),
        );
    }
}
