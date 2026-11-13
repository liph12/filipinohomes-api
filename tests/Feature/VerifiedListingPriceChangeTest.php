<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Listing;
use App\Models\User;
use App\Services\Agent\AgentCachePurger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * A Verified / Fully Verified listing goes back to pending review when its
 * OWN agent changes the price. Admin edits (the audit page) keep the status.
 */
class VerifiedListingPriceChangeTest extends TestCase
{
    use CreatesAuthTables;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.indexnow.enabled' => false, 'audit.enabled' => false]);

        $this->createAuthTables();
        DB::table('roles')->insert([['id' => 1, 'name' => 'admin'], ['id' => 2, 'name' => 'agent']]);
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('property_id')->nullable();
            $table->string('name')->default('Listing');
            $table->string('slug')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->string('verification_status')->nullable();
            $table->json('agent_edited_fields')->nullable();
            $table->timestamp('re_submitted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->mock(AgentCachePurger::class)->shouldReceive('purgeAgent')->andReturnNull();
    }

    /** A user with an Agent record; returns [user, agentId]. */
    private function person(string $role): array
    {
        $user = User::forceCreate([
            'name' => ucfirst($role), 'email' => uniqid($role, true).'@example.com', 'password' => 'secret',
            'role_id' => DB::table('roles')->where('name', $role)->value('id'),
        ]);
        $agent = Agent::forceCreate(['user_id' => $user->id, 'first_name' => 'T', 'last_name' => $role, 'status' => 'active']);

        return [$user, $agent->id];
    }

    private function listing(int $agentId, ?string $status, string $price = '1500000.00'): Listing
    {
        $id = DB::table('listings')->insertGetId(['agent_id' => $agentId, 'price' => $price, 'verification_status' => $status]);

        return Listing::findOrFail($id);
    }

    public function test_the_owning_agent_changing_the_price_reopens_review(): void
    {
        [$agentUser, $agentId] = $this->person('agent');
        Auth::login($agentUser);

        foreach (['verified', 'fully_verified'] as $status) {
            $listing = $this->listing($agentId, $status);
            $listing->update(['price' => 1600000]);

            $listing->refresh();
            $this->assertSame('pending_review', $listing->verification_status);
            $this->assertNotNull($listing->re_submitted_at);
            $this->assertSame([['label' => 'Price', 'original' => '1500000.00', 'current' => '1600000.00']], $listing->agent_edited_fields);
        }
    }

    public function test_admin_price_edits_and_other_changes_keep_the_status(): void
    {
        [$agentUser, $agentId] = $this->person('agent');
        [$adminUser] = $this->person('admin');

        // Admin changes the price (audit page) → stays verified.
        Auth::login($adminUser);
        $a = $this->listing($agentId, 'verified');
        $a->update(['price' => 1700000]);
        $this->assertSame('verified', $a->fresh()->verification_status);

        Auth::login($agentUser);
        // Same price in another format → no change.
        $b = $this->listing($agentId, 'fully_verified');
        $b->update(['price' => '1500000']);
        $this->assertSame('fully_verified', $b->fresh()->verification_status);

        // Agent edits something else → stays verified.
        $c = $this->listing($agentId, 'verified');
        $c->update(['name' => 'New title']);
        $this->assertSame('verified', $c->fresh()->verification_status);

        // Not verified (unaudited / flagged) → untouched by this rule.
        $d = $this->listing($agentId, null);
        $d->update(['price' => 1900000]);
        $this->assertNull($d->fresh()->verification_status);

        // Another agent's listing (e.g. reassigned) → not the owner → unchanged.
        [, $otherAgentId] = $this->person('agent');
        $e = $this->listing($otherAgentId, 'verified');
        $e->update(['price' => 2000000]);
        $this->assertSame('verified', $e->fresh()->verification_status);
    }

    public function test_no_signed_in_user_never_triggers_it(): void
    {
        [, $agentId] = $this->person('agent');
        $listing = $this->listing($agentId, 'verified');
        $listing->update(['price' => 1800000]); // scheduler / jobs

        $this->assertSame('verified', $listing->fresh()->verification_status);
    }
}
