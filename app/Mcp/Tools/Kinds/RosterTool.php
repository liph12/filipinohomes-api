<?php

namespace App\Mcp\Tools\Kinds;

use App\Mcp\Support\McpActor;
use App\Mcp\Support\McpTeams;
use App\Mcp\Tools\FhTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;

/**
 * The people in the teams a person leads, by name and id (the id is what the
 * other team tools take as agent_id). Names and ids only, never contact
 * details. Only a team leader has a policy that shows it.
 */
abstract class RosterTool extends FhTool
{
    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'team_id' => $schema->integer()->min(1)->description('Only this team, when you lead more than one.'),
        ];
    }

    protected function rules(): array
    {
        return ['team_id' => ['nullable', 'integer', 'min:1']];
    }

    protected function answer(array $input, McpActor $actor): array
    {
        $only = isset($input['team_id']) ? (int) $input['team_id'] : null;

        if ($only !== null && ! in_array($only, $actor->teamIds, true)) {
            throw new InvalidArgumentException('team_id has to be a team you lead ('.implode(', ', $actor->teamIds).').');
        }

        return [
            'teams' => McpTeams::roster($actor->user, $only),
            'note' => 'The reports credit a person who sits in two teams to the lower-numbered one only, so the other team\'s numbers leave them out (counted_in_team_numbers is false). A member who has left can still be listed until their place in the team is closed.',
        ];
    }
}
