<?php

namespace Tests\Feature;

use App\Services\Location\BoundaryEnvelopeRepository;
use Tests\TestCase;

/**
 * GET /locations/geo — where a picked barangay / city / province is, from our boundary shapes,
 * so the location pickers don't geocode. The spatial SQL (MySQL-only) is faked here; its
 * parsing is covered by test_envelope_geojson_parses_to_a_box.
 */
class LocationGeoTest extends TestCase
{
    /** Shapes keyed like the repository's lookups. */
    private array $shapes = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.guest_api_secret' => 'guest-secret', 'cache.default' => 'array']);

        $shapes = &$this->shapes;
        $this->app->instance(BoundaryEnvelopeRepository::class, new class($shapes) extends BoundaryEnvelopeRepository
        {
            public function __construct(private array &$shapes) {}

            public function barangay(int $barangayId): ?array
            {
                return $this->shapes['barangay'][$barangayId] ?? null;
            }

            public function city(int $cityId): ?array
            {
                return $this->shapes['city'][$cityId] ?? null;
            }

            public function province(int $provinceId): ?array
            {
                return $this->shapes['province'][$provinceId] ?? null;
            }

            public function barangaysOfCity(int $cityId): array
            {
                return $this->shapes['barangaysOfCity'][$cityId] ?? [];
            }

            public function citiesOfProvince(int $provinceId): array
            {
                return $this->shapes['citiesOfProvince'][$provinceId] ?? [];
            }
        });
    }

    private function guest(): static
    {
        $payload = json_encode(['exp' => time() + 600]);

        return $this->withHeader('X-Guest-Token', base64_encode($payload).'.'.hash_hmac('sha256', $payload, 'guest-secret'));
    }

    private static function env(float $s, float $n, float $w, float $e, ?float $lat = null, ?float $lng = null): array
    {
        return ['north' => $n, 'south' => $s, 'east' => $e, 'west' => $w, 'lat' => $lat ?? ($n + $s) / 2, 'lng' => $lng ?? ($e + $w) / 2];
    }

    public function test_a_barangay_is_framed_by_its_own_shape(): void
    {
        // Poblacion (Carmen), Agusan del Norte — the barangay OpenStreetMap doesn't know.
        $this->shapes['barangay'][21031] = self::env(8.98, 9.0, 125.28, 125.31, 8.991, 125.296);

        $this->guest()->getJson('/api/locations/geo?barangay_id=21031')->assertOk()->assertExactJson([
            'found' => true,
            'level' => 'barangay',
            'lat' => 8.991,
            'lng' => 125.296,
            'bounds' => ['north' => 9, 'south' => 8.98, 'east' => 125.31, 'west' => 125.28],
        ]);
    }

    public function test_a_city_without_its_own_shape_is_framed_around_its_barangays(): void
    {
        $this->shapes['city'][33] = self::env(8.9, 9.1, 125.2, 125.4, 9.0, 125.3);
        $this->guest()->getJson('/api/locations/geo?city_id=33')->assertOk()
            ->assertJsonPath('found', true)->assertJsonPath('lat', 9)->assertJsonPath('bounds.west', 125.2);

        $this->shapes['barangaysOfCity'][34] = [self::env(10.0, 10.2, 123.8, 123.9), self::env(10.1, 10.4, 123.85, 124.0)];
        $this->guest()->getJson('/api/locations/geo?city_id=34')->assertOk()->assertExactJson([
            'found' => true,
            'level' => 'city',
            'lat' => 10.2,
            'lng' => 123.9,
            'bounds' => ['north' => 10.4, 'south' => 10, 'east' => 124, 'west' => 123.8],
        ]);
    }

    public function test_a_province_uses_its_own_shape_when_it_has_one(): void
    {
        $this->shapes['province'][7] = self::env(9.5, 10.5, 123.3, 124.1, 10.2, 123.8);
        $this->shapes['citiesOfProvince'][7] = [self::env(0, 1, 0, 1)]; // ignored: the province shape wins

        $this->guest()->getJson('/api/locations/geo?province_id=7')->assertOk()
            ->assertJsonPath('lat', 10.2)->assertJsonPath('lng', 123.8)->assertJsonPath('bounds.north', 10.5);
    }

    public function test_a_province_without_its_own_shape_is_framed_around_its_cities(): void
    {
        $this->shapes['citiesOfProvince'][2] = [self::env(8.5, 9.0, 125.0, 125.5), self::env(9.0, 9.3, 125.4, 125.8)];

        $this->guest()->getJson('/api/locations/geo?province_id=2')->assertOk()
            ->assertJsonPath('level', 'province')
            ->assertJsonPath('bounds', ['north' => 9.3, 'south' => 8.5, 'east' => 125.8, 'west' => 125])
            ->assertJsonPath('lat', 8.9)->assertJsonPath('lng', 125.4);
    }

    public function test_most_specific_id_wins_and_no_shape_says_not_found(): void
    {
        $this->shapes['barangay'][5] = self::env(1, 2, 3, 4);
        $this->guest()->getJson('/api/locations/geo?province_id=99&city_id=98&barangay_id=5')->assertOk()->assertJsonPath('level', 'barangay');

        $this->guest()->getJson('/api/locations/geo?city_id=12345')->assertOk()->assertExactJson(['found' => false, 'level' => 'city']);
    }

    public function test_it_needs_an_id_and_the_guest_token(): void
    {
        // First: once guest() sets the header it stays on later requests in this test.
        $this->getJson('/api/locations/geo?city_id=1')->assertUnauthorized();
        $this->guest()->getJson('/api/locations/geo')->assertStatus(422);
        $this->guest()->getJson('/api/locations/geo?city_id=abc')->assertStatus(422);
    }

    public function test_envelope_geojson_parses_to_a_box(): void
    {
        // What MySQL returns for ST_AsGeoJSON(ST_Envelope(geom)) — (lng lat) order.
        $box = '{"type": "Polygon", "coordinates": [[[125.28, 8.98], [125.31, 8.98], [125.31, 9.0], [125.28, 9.0], [125.28, 8.98]]]}';
        $this->assertSame(
            ['north' => 9.0, 'south' => 8.98, 'east' => 125.31, 'west' => 125.28, 'lat' => 8.99, 'lng' => 125.295],
            BoundaryEnvelopeRepository::parse($box, 125.295, 8.99),
        );
        // A degenerate (single point) shape still gives a usable box.
        $this->assertSame(10.0, BoundaryEnvelopeRepository::parse('{"type": "Point", "coordinates": [123.9, 10.0]}', null, null)['north']);
        $this->assertNull(BoundaryEnvelopeRepository::parse(null, null, null));
    }
}
