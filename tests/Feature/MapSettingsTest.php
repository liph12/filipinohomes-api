<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/** System Settings → Maps: the nearby-facilities provider switch (Google / OpenStreetMap). */
class MapSettingsTest extends TestCase
{
    use CreatesAuthTables;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.guest_api_secret' => 'guest-secret']);
        $this->createAuthTables();
        DB::table('roles')->insert([['id' => 1, 'name' => 'admin'], ['id' => 3, 'name' => 'client']]);
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->longText('value')->nullable();
            $t->timestamps();
        });
    }

    private function guest(): static
    {
        $payload = json_encode(['exp' => time() + 600]);

        return $this->withHeader('X-Guest-Token', base64_encode($payload).'.'.hash_hmac('sha256', $payload, 'guest-secret'));
    }

    public function test_defaults_to_google_and_the_public_config_follows_the_admin_switch(): void
    {
        $this->guest()->getJson('/api/maps/config')->assertOk()->assertExactJson(['nearby_provider' => 'google', 'auto_switch' => true, 'auto_fallback' => false]);

        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'role_id' => 1]));
        $this->getJson('/api/admin/map-settings')->assertOk()->assertJsonPath('nearby_provider', 'google');
        $this->putJson('/api/admin/map-settings', ['nearby_provider' => 'osm'])->assertOk()->assertJsonPath('nearby_provider', 'osm');
        $this->putJson('/api/admin/map-settings', ['nearby_provider' => 'bing'])->assertUnprocessable();

        // Picked by hand: not an automatic fallback.
        $this->guest()->getJson('/api/maps/config')->assertOk()->assertExactJson(['nearby_provider' => 'osm', 'auto_switch' => true, 'auto_fallback' => false]);
    }

    public function test_google_billing_off_falls_back_to_openstreetmap_and_a_manual_switch_clears_the_note(): void
    {
        config(['app.build_token' => 'build-secret']);

        // Wrong / missing token: refused, nothing changes.
        $this->postJson('/api/maps/fallback')->assertUnauthorized();
        $this->withHeader('X-Build-Token', 'nope')->postJson('/api/maps/fallback')->assertUnauthorized();
        $this->guest()->getJson('/api/maps/config')->assertExactJson(['nearby_provider' => 'google', 'auto_switch' => true, 'auto_fallback' => false]);

        // Google selected + billing off → saved as OpenStreetMap, with the time.
        $this->withHeader('X-Build-Token', 'build-secret')->postJson('/api/maps/fallback')
            ->assertOk()->assertJsonPath('nearby_provider', 'osm');
        $this->guest()->getJson('/api/maps/config')->assertExactJson(['nearby_provider' => 'osm', 'auto_switch' => true, 'auto_fallback' => true]);

        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'role_id' => 1]));
        $at = $this->getJson('/api/admin/map-settings')->assertOk()->assertJsonPath('nearby_provider', 'osm')->json('auto_fallback_at');
        $this->assertNotNull($at);

        // Another report while already on OpenStreetMap keeps the original time.
        $this->travel(5)->minutes();
        $this->withHeader('X-Build-Token', 'build-secret')->postJson('/api/maps/fallback')->assertOk()->assertJsonPath('auto_fallback_at', $at);

        // A manual switch back to Google clears the fallback note.
        $this->putJson('/api/admin/map-settings', ['nearby_provider' => 'google'])
            ->assertOk()->assertJsonPath('nearby_provider', 'google')->assertJsonPath('auto_fallback_at', null);
    }

    public function test_only_admins_can_change_it_and_the_public_read_needs_the_guest_token(): void
    {
        $this->getJson('/api/maps/config')->assertUnauthorized();

        Sanctum::actingAs(User::forceCreate(['name' => 'Client', 'email' => 'client@example.com', 'password' => 'x', 'role_id' => 3]));
        $this->putJson('/api/admin/map-settings', ['nearby_provider' => 'osm'])->assertForbidden();
    }

    public function test_billing_on_again_switches_an_automatic_fallback_back_to_google(): void
    {
        config(['app.build_token' => 'build-secret']);
        $web = fn () => $this->withHeader('X-Build-Token', 'build-secret');

        $this->postJson('/api/maps/restore')->assertUnauthorized();

        $web()->postJson('/api/maps/fallback')->assertOk()->assertJsonPath('nearby_provider', 'osm');
        $web()->postJson('/api/maps/restore')->assertOk()
            ->assertJsonPath('nearby_provider', 'google')
            ->assertJsonPath('auto_fallback_at', null);

        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'role_id' => 1]));
        $this->assertNotNull($this->getJson('/api/admin/map-settings')->json('auto_restored_at'));
        $this->guest()->getJson('/api/maps/config')->assertExactJson(['nearby_provider' => 'google', 'auto_switch' => true, 'auto_fallback' => false]);

        // Falling back again clears the "switched back" note.
        $web()->postJson('/api/maps/fallback')->assertJsonPath('auto_restored_at', null);
    }

    public function test_an_openstreetmap_picked_by_an_admin_is_never_switched_back(): void
    {
        config(['app.build_token' => 'build-secret']);

        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'role_id' => 1]));
        $this->putJson('/api/admin/map-settings', ['nearby_provider' => 'osm'])->assertOk();

        $this->withHeader('X-Build-Token', 'build-secret')->postJson('/api/maps/restore')->assertOk()
            ->assertJsonPath('nearby_provider', 'osm')
            ->assertJsonPath('auto_restored_at', null);
    }

    public function test_with_automatic_switching_off_the_provider_only_changes_by_hand(): void
    {
        config(['app.build_token' => 'build-secret']);
        $web = fn () => $this->withHeader('X-Build-Token', 'build-secret');

        // Fall back while on, so there's an automatic OpenStreetMap to (not) restore.
        $web()->postJson('/api/maps/fallback')->assertJsonPath('nearby_provider', 'osm');

        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'role_id' => 1]));
        $this->putJson('/api/admin/map-settings', ['auto_switch' => false])->assertOk()
            ->assertJsonPath('auto_switch', false)
            ->assertJsonPath('nearby_provider', 'osm');   // toggling doesn't change the provider
        $this->putJson('/api/admin/map-settings', [])->assertUnprocessable();

        $web()->postJson('/api/maps/restore')->assertJsonPath('nearby_provider', 'osm');
        $this->putJson('/api/admin/map-settings', ['nearby_provider' => 'google'])->assertOk();
        $web()->postJson('/api/maps/fallback')->assertJsonPath('nearby_provider', 'google');
        $this->guest()->getJson('/api/maps/config')->assertExactJson(['nearby_provider' => 'google', 'auto_switch' => false, 'auto_fallback' => false]);

        // Back on: switching works again.
        $this->putJson('/api/admin/map-settings', ['auto_switch' => true])->assertJsonPath('auto_switch', true);
        $web()->postJson('/api/maps/fallback')->assertJsonPath('nearby_provider', 'osm');
    }
}
