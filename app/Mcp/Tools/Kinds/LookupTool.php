<?php

namespace App\Mcp\Tools\Kinds;

use App\Mcp\Support\McpActor;
use App\Mcp\Tools\FhTool;
use App\Services\FhOne\LookupService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;

/**
 * A name to the id a filter needs ("Mantra" to project 12). The kinds of name
 * open to this person are the policy's: an agent can look up places, projects
 * and property types, not teams.
 */
abstract class LookupTool extends FhTool
{
    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum($this->policy()->lookups())->required()->description('What kind of name.'),
            'text' => $schema->string()->min(LookupService::MIN_CHARS)->max(60)->required()->description('Part of the name, at least '.LookupService::MIN_CHARS.' letters.'),
        ];
    }

    protected function rules(): array
    {
        return [
            'type' => ['required', Rule::in($this->policy()->lookups())],
            'text' => ['required', 'string', 'min:'.LookupService::MIN_CHARS, 'max:60'],
        ];
    }

    protected function answer(array $input, McpActor $actor): array
    {
        return app(LookupService::class)->search((string) $input['type'], (string) $input['text']);
    }
}
