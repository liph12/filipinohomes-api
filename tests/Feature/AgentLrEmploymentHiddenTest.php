<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Listing;
use Tests\TestCase;

/**
 * What LR says about an agent's employment (agents.lr_employment_*) is nobody
 * else's business. Agent::$hidden is the only thing keeping it out of the
 * responses that serialise a whole agent — FullListingController::show returns
 * the listing with its agent to any agent or admin, and EmailChangeController
 * returns the model — so it is tested rather than trusted.
 */
class AgentLrEmploymentHiddenTest extends TestCase
{
    private const COLUMNS = [
        'lr_employment_lookup',
        'lr_employment_status',
        'lr_employment_checked_at',
        'lr_employment_changed_at',
    ];

    private function agent(): Agent
    {
        return (new Agent())->forceFill([
            'id' => 1,
            'first_name' => 'Ana',
            'status' => 'active',
            'lr_employment_lookup' => 'found',
            'lr_employment_status' => 'terminated',
            'lr_employment_checked_at' => now(),
            'lr_employment_changed_at' => now(),
        ]);
    }

    public function test_the_bookkeeping_is_never_serialised(): void
    {
        $agent = $this->agent();

        foreach (['toArray' => $agent->toArray(), 'toJson' => json_decode($agent->toJson(), true)] as $how => $data) {
            foreach (self::COLUMNS as $column) {
                $this->assertArrayNotHasKey($column, $data, "{$how} leaks {$column}");
            }
            $this->assertSame('Ana', $data['first_name'], 'everything else is still serialised');
        }

        $this->assertSame('terminated', $agent->lr_employment_status, 'and it is still readable in code');
    }

    public function test_it_stays_hidden_when_the_agent_is_nested_in_a_listing(): void
    {
        // The shape FullListingController::show returns: a listing with its agent loaded.
        $listing = (new Listing())->forceFill(['id' => 9, 'name' => 'Studio']);
        $listing->setRelation('agent', $this->agent());

        $nested = $listing->toArray()['agent'];

        foreach (self::COLUMNS as $column) {
            $this->assertArrayNotHasKey($column, $nested, "a listing's agent leaks {$column}");
        }
    }
}
