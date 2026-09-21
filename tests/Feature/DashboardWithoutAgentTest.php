<?php

namespace Tests\Feature;

use App\Http\Controllers\ListingController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Regression for "Attempt to read property 'id' on null" in
 * ListingController::dashboard — a non-admin user with no agents row
 * (client/editor/secretary) calls the shared /user/dashboard endpoint.
 * Built in-memory (no DB) so it runs without migrations.
 */
class DashboardWithoutAgentTest extends TestCase
{
    public function test_user_without_agent_row_gets_zeroed_dashboard(): void
    {
        $user = new User();
        $user->setRelation('role', (new Role())->forceFill(['name' => 'client']));
        $user->setRelation('agent', null);

        $request = Request::create('/api/user/dashboard', 'GET');
        $request->setUserResolver(fn () => $user);

        $response = app(ListingController::class)->dashboard($request);
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $data['total']);
        $this->assertSame(0, $data['active']);
        $this->assertSame(0, $data['agent']);
        $this->assertSame(['For Sale' => 0, 'For Rent' => 0, 'Foreclosure' => 0], $data['category']);
    }
}
