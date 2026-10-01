<?php

namespace Tests\Feature;

use App\Http\Controllers\ListingController;
use App\Models\Role;
use App\Models\User;
use App\Services\Listing\BoundaryGeoJsonRepository;
use App\Services\Listing\HeatmapBoundaryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * GET /listings/insights/heatmap/boundaries — the polygons the counts are
 * painted onto.
 *
 * Every ST_* call lives in {@see BoundaryGeoJsonRepository} precisely so this
 * test can replace it: sqlite has no spatial functions, and the behaviour worth
 * testing here is not the SQL but the contract around it — the FeatureCollection
 * the canvas parses, and the caching that stops ~1 MB of coordinates being
 * rebuilt on a 60-second poll.
 */
class ListingHeatmapBoundariesTest extends TestCase
{
    private FakeBoundaryGeoJsonRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->default('');
        });
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('team_agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('agent_id');
            $table->boolean('is_leader')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        DB::table('provinces')->insert([
            ['id' => 25, 'name' => 'Cebu', 'code' => 'CEB'],
            ['id' => 82, 'name' => 'Southern Samar', 'code' => 'SSA'],
            ['id' => 83, 'name' => 'Samar', 'code' => 'SAM'],
        ]);
        DB::table('agents')->insert([['id' => 8, 'user_id' => 800, 'status' => 'active']]);

        $this->repository = new FakeBoundaryGeoJsonRepository;
        $this->app->instance(BoundaryGeoJsonRepository::class, $this->repository);
    }

    private function boundaries(array $query = [], array $headers = [], string $role = 'admin', int $userId = 1)
    {
        $user = new User;
        $user->id = $userId;
        $user->setRelation('role', (new Role)->forceFill(['name' => $role]));

        $request = Request::create('/api/listings/insights/heatmap/boundaries', 'GET', $query);
        $request->setUserResolver(fn () => $user);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return app(ListingController::class)->insightsHeatmapBoundaries($request, app(HeatmapBoundaryService::class));
    }

    public function test_it_returns_a_feature_collection_the_canvas_can_parse(): void
    {
        $response = $this->boundaries(['level' => 'province']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));

        $body = json_decode($response->getContent(), true);
        $this->assertSame('FeatureCollection', $body['type']);
        $this->assertCount(3, $body['features']);

        $feature = $body['features'][0];
        $this->assertSame('Feature', $feature['type']);
        $this->assertSame(['id', 'boundary_id', 'name', 'centroid'], array_keys($feature['properties']));
        $this->assertSame(25, $feature['properties']['id']);
        $this->assertSame(901, $feature['properties']['boundary_id']);
        $this->assertSame('Cebu', $feature['properties']['name']);
        $this->assertSame([123.9, 10.3], $feature['properties']['centroid']);
        // The geometry is spliced in verbatim, never decoded and re-encoded.
        $this->assertSame('Polygon', $feature['geometry']['type']);

        // A polygon with no linked area still ships, with a null id: leaving it
        // out would punch a hole in the land, which reads as "nothing here".
        $unlinked = $body['features'][2];
        $this->assertNull($unlinked['properties']['id']);
        $this->assertSame('Unlinked Town', $unlinked['properties']['name']);

        // A geometry MySQL could not produce is dropped rather than emitted as
        // null — deck.gl fails the whole collection on a null geometry.
        $this->assertNotContains('Broken Island', array_column(array_column($body['features'], 'properties'), 'name'));

        // A missing centroid is null, not a guess; the canvas falls back to the
        // feature's bbox centre.
        $this->assertNull($body['features'][1]['properties']['centroid']);
    }

    public function test_geometry_is_cached_for_a_day_and_revalidated_with_an_etag(): void
    {
        $first = $this->boundaries(['level' => 'province']);
        $etag = $first->headers->get('ETag');

        $this->assertSame('max-age=86400, private', $this->cacheControl($first));
        $this->assertNotNull($etag);
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);

        $conditional = $this->boundaries(['level' => 'province'], ['If-None-Match' => $etag]);

        $this->assertSame(304, $conditional->getStatusCode());
        $this->assertSame('', $conditional->getContent());
        $this->assertSame($etag, $conditional->headers->get('ETag'));
        $this->assertSame('max-age=86400, private', $this->cacheControl($conditional));

        // A weak validator added by a compressing proxy must still match, or
        // the admin re-downloads a megabyte on every page view.
        $weak = $this->boundaries(['level' => 'province'], ['If-None-Match' => 'W/'.$etag]);
        $this->assertSame(304, $weak->getStatusCode());

        // A stale tag is a full response, not a 304.
        $stale = $this->boundaries(['level' => 'province'], ['If-None-Match' => '"deadbeef"']);
        $this->assertSame(200, $stale->getStatusCode());
    }

    public function test_the_expensive_query_runs_once_per_boundaries_version(): void
    {
        $this->boundaries(['level' => 'province']);
        $this->boundaries(['level' => 'province']);

        $this->assertSame(1, $this->repository->calls, 'a second request must be served from cache');

        // An import or relink bumps the version; the next request must see the
        // new geometry rather than a day-old copy of the old one.
        Cache::forever('heatmap:boundaries:ver', 7);
        $this->boundaries(['level' => 'province']);

        $this->assertSame(2, $this->repository->calls, 'bumping the version must invalidate the cached payload');
    }

    public function test_city_level_scopes_to_the_canonical_province_group(): void
    {
        $this->boundaries(['level' => 'city', 'province_id' => 82]);

        $this->assertSame('city', $this->repository->lastLevel);
        // 82 and 83 are one Samar, so both ids reach the query.
        $this->assertSame([82, 83], $this->repository->lastProvinceIds);

        // …and the two spellings share one cache entry, not two.
        $this->boundaries(['level' => 'city', 'province_id' => 83]);
        $this->assertSame(1, $this->repository->calls);
    }

    public function test_the_province_layer_is_always_nationwide(): void
    {
        // A province_id alongside level=province would otherwise cache the same
        // 80 polygons under 80 keys.
        $this->boundaries(['level' => 'province', 'province_id' => 25]);
        $this->boundaries(['level' => 'province']);

        $this->assertSame(1, $this->repository->calls);
        $this->assertSame([], $this->repository->lastProvinceIds);
    }

    public function test_a_user_who_leads_nobody_is_refused(): void
    {
        $this->expectException(HttpException::class);

        $this->boundaries(['level' => 'province'], [], 'agent', 800);
    }

    /** Symfony re-orders Cache-Control directives; compare them as a set. */
    private function cacheControl($response): string
    {
        $parts = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        sort($parts);

        return implode(', ', $parts);
    }
}

/**
 * Stands in for the only class that speaks spatial SQL. Returns the four row
 * shapes the real query can produce: linked + centroid, linked without a
 * centroid, unlinked, and a row whose geometry came back NULL.
 */
class FakeBoundaryGeoJsonRepository extends BoundaryGeoJsonRepository
{
    public int $calls = 0;

    public ?string $lastLevel = null;

    public array $lastProvinceIds = [];

    public function features(string $level, array $provinceIds = []): array
    {
        $this->calls++;
        $this->lastLevel = $level;
        $this->lastProvinceIds = $provinceIds;

        $square = '{"type":"Polygon","coordinates":[[[123.0,10.0],[124.0,10.0],[124.0,11.0],[123.0,11.0],[123.0,10.0]]]}';

        return [
            [
                'boundary_id' => 901,
                'area_id' => 25,
                'name' => 'Cebu',
                'geojson' => $square,
                'centroid' => '{"type":"Point","coordinates":[123.9,10.3]}',
            ],
            [
                'boundary_id' => 902,
                'area_id' => 83,
                'name' => 'Samar',
                'geojson' => $square,
                'centroid' => null,
            ],
            [
                'boundary_id' => 903,
                'area_id' => null,
                'name' => 'Unlinked Town',
                'geojson' => $square,
                'centroid' => '{"type":"Point","coordinates":[125.0,11.5]}',
            ],
            [
                'boundary_id' => 904,
                'area_id' => 42,
                'name' => 'Broken Island',
                'geojson' => null,
                'centroid' => null,
            ],
        ];
    }
}
