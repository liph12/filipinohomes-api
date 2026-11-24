<?php

namespace App\Mcp\Tools\Kinds;

use App\Mcp\Support\McpActor;
use App\Mcp\Support\McpRole;
use App\Mcp\Tools\FhTool;
use App\Services\FhOne\MetricCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The numbers this person may ask for, with what each counts and which filters
 * and splits it takes: where a question starts. Only what the policy allows is
 * listed, so an agent is never told about a number they cannot ask for.
 */
abstract class MetricsCatalogTool extends FhTool
{
    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->max(60)->description('Words to look for in a number\'s id, name or definition, e.g. "sold" or "waiting". Leave out to list them all.'),
        ];
    }

    protected function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:60']];
    }

    protected function answer(array $input, McpActor $actor): array
    {
        $policy = $this->policy();
        $catalog = app(MetricCatalog::class)->describe();
        $needle = mb_strtolower(trim((string) ($input['search'] ?? '')));
        $metrics = [];

        foreach ((array) $catalog['metrics'] as $metric) {
            if (! $policy->allowsMetric((string) $metric['id'])) {
                continue;
            }

            if ($needle !== '' && ! str_contains(mb_strtolower($metric['id'].' '.$metric['label'].' '.($metric['definition'] ?? '')), $needle)) {
                continue;
            }

            $metrics[] = [
                'id' => $metric['id'],
                'label' => $metric['label'],
                'definition' => $metric['definition'],
                'unit' => $metric['unit'],
                'kind' => $metric['kind'],
                'measured' => $metric['measured'],
                'trust' => $metric['trust'],
                'filters' => array_values(array_intersect((array) $metric['filters'], $policy->filters())),
                'breakdowns' => array_values(array_intersect((array) $metric['breakdowns'], $policy->dimensions())),
            ];
        }

        return [
            'count' => count($metrics),
            'metrics' => $metrics,
            'trust' => $catalog['trust'] ?? null,
            'measured' => '"range" numbers count the days asked for; "now" numbers are as of this moment, whatever the days.',
            // Website traffic is not a catalog number (it has no team, agent or listing to filter by): it has its own tool.
            ...($policy->role() === McpRole::Admin
                ? ['website_traffic' => 'Website visitors, traffic sources (google, facebook, direct, chatgpt...), referral sites and visitor locations are not in this catalog: use fh_traffic. For AI assistants viewing, crawling and sending people to the site (AI visibility), use fh_ai_visibility. For the client journey, clients who inquired more than once, inquiries per client and what a missed reply costs, use fh_clients.']
                : []),
        ];
    }
}
