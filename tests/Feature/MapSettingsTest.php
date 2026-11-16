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
        $this->guest()->getJson('/api/maps/config')->assertOk()->assertExactJson(['nearby_provider' => 'google']);

        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'role_id' => 1]));
        $this->getJson('/api/admin/map-settings')->assertOk()->assertJsonPath('nearby_provider', 'google');
        $this->putJson('/api/admin/map-settings', ['nearby_provider' => 'osm'])->assertOk()->assertJsonPath('nearby_provider', 'osm');
        $this->putJson('/api/admin/map-settings', ['nearby_provider' => 'bing'])->assertUnprocessable();

        $this->guest()->getJson('/api/maps/config')->assertOk()->assertExactJson(['nearby_provider' => 'osm']);
    }

    public function test_google_billing_off_falls_back_to_openstreetmap_and_stays_until_switched_back_manually(): void
    {
        config(['app.build_token' => 'build-secret']);

        // Wrong / missing token: refused, nothing changes.
        $this->postJson('/api/maps/fallback')->assertUnauthorized();
        $this->withHeader('X-Build-Token', 'nope')->postJson('/api/maps/fallback')->assertUnauthorized();
        $this->guest()->getJson('/api/maps/config')->assertExactJson(['nearby_provider' => 'google']);

        // Google selected + billing off → saved as OpenStreetMap, with the time.
        $this->withHeader('X-Build-Token', 'build-secret')->postJson('/api/maps/fallback')
            ->assertOk()->assertJsonPath('nearby_provider', 'osm');
        $this->guest()->getJson('/api/maps/config')->assertExactJson(['nearby_provider' => 'osm']);

        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'role_id' => 1]));
        $at = $this->getJson('/api/admin/map-settings')->assertOk()->assertJsonPath('nearby_provider', 'osm')->json('auto_fallback_at');
        $this->assertNotNull($at);

        // Another report while already on OpenStreetMap keeps the original time.
        $this->travel(5)->minutes();
        $this->withHeader('X-Build-Token', 'build-secret')->postJson('/api/maps/fallback')->assertOk()->assertJsonPath('auto_fallback_at', $at);

        // Only a manual switch goes back to Google, and it clears the fallback note.
        $this->putJson('/api/admin/map-settings', ['nearby_provider' => 'google'])
            ->assertOk()->assertJsonPath('nearby_provider', 'google')->assertJsonPath('auto_fallback_at', null);
    }

    public function test_only_admins_can_change_it_and_the_public_read_needs_the_guest_token(): void
    {
        $this->getJson('/api/maps/config')->assertUnauthorized();

        Sanctum::actingAs(User::forceCreate(['name' => 'Client', 'email' => 'client@example.com', 'password' => 'x', 'role_id' => 3]));
        $this->putJson('/api/admin/map-settings', ['nearby_provider' => 'osm'])->assertForbidden();
    }
}
