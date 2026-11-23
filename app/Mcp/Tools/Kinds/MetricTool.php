<?php

namespace App\Mcp\Tools\Kinds;

use App\Mcp\Support\Asks;
use App\Mcp\Support\McpActor;
use App\Mcp\Tools\FhTool;
use App\Services\FhOne\MetricService;
use App\Services\Reports\Executive\MetricRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;

/**
 * One number, for some days and filters, optionally with the span before it:
 * exactly what FH One's API gives (MetricService), with this person's policy
 * deciding which numbers and filters are open and what is forced on.
 */
abstract class MetricTool extends FhTool
{
    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'metric' => $schema->string()->max(80)->required()->description('The number\'s id, like "listings.live". Find it with the catalog tool.'),
            'compare' => $schema->boolean()->description('Also give the span just before these days, with the change.'),
            ...Asks::schema($schema, $this->policy()->filters()),
        ];
    }

    protected function rules(): array
    {
        return [
            'metric' => ['required', 'string', 'max:80'],
            'compare' => ['nullable', 'boolean'],
            ...Asks::rules($this->policy()->filters()),
        ];
    }

    protected function answer(array $input, McpActor $actor): array
    {
        $metric = $this->metric((string) $input['metric']);

        return app(MetricService::class)->value(
            $metric,
            Asks::range($input),
            Asks::filters($input, $this->policy(), $actor),
            (bool) ($input['compare'] ?? false),
        );
    }

    /** @throws InvalidArgumentException when there is no such number, or this person may not ask for it */
    protected function metric(string $metric): string
    {
        if (! MetricRegistry::has($metric)) {
            throw new InvalidArgumentException("There is no number called '{$metric}'. List them with the catalog tool.");
        }

        if (! $this->policy()->allowsMetric($metric)) {
            throw new InvalidArgumentException("'{$metric}' is not available to your account. The catalog tool lists the numbers you can ask for.");
        }

        return $metric;
    }
}
