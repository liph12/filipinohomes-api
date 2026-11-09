<?php

namespace Tests\Feature;

use App\Services\Listing\PriceGuideService;
use App\Services\Listing\ProjectFinder;
use App\Services\OpenAI\DataLayerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Units in Horizon 101" must mean that building: the name a buyer types finds the development, a
 * barangay's name never does, and the building's own words are not search keywords.
 */
class ProjectFinderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('complete_address')->nullable();
            $t->string('latitude')->nullable();
            $t->string('longitude')->nullable();
            $t->text('featured_photo')->nullable();
            $t->integer('views')->default(0);
            $t->unsignedBigInteger('city_id')->nullable();
            $t->unsignedBigInteger('brgy_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        $row = fn (int $id, string $name, string $address, int $views, ?string $deleted = null) => [
            'id' => $id, 'name' => $name, 'complete_address' => $address, 'views' => $views, 'deleted_at' => $deleted,
        ];
        DB::table('projects')->insert([
            $row(1, 'Horizon 101', 'Mango Avenue, Cebu City', 4000),
            $row(2, 'Horizon 101 Tower 2', 'Mango Avenue, Cebu City', 300),
            $row(3, 'Mivesa Garden Residences', 'Lahug, Cebu City', 900),
            $row(4, 'Vista Residences Lahug', 'Lahug, Cebu City', 100),
            $row(5, 'Camella Davao', 'Davao City', 50),
            $row(6, 'Camella Cebu', 'Talisay City, Cebu', 500),
            $row(7, 'Gone Towers', 'Cebu City', 9000, '2026-01-01 00:00:00'),
        ]);
        // The places the Price Guide knows: "Lahug" is a barangay and "Cebu City" a city, nothing else.
        $this->mock(PriceGuideService::class, function ($m) {
            $m->shouldReceive('placeOf')->andReturnUsing(fn (string $n) => in_array(mb_strtolower(trim($n)), ['lahug', 'cebu city'], true) ? ['kind' => 'barangay'] : null);
        });
    }

    public function test_a_typed_name_finds_the_most_viewed_development_carrying_every_word(): void
    {
        $finder = app(ProjectFinder::class);

        $this->assertSame('Horizon 101', $finder->find('horizon 101')?->name);
        $this->assertSame('Mivesa Garden Residences', $finder->find('Mivesa')?->name);
        $this->assertSame('Vista Residences Lahug', $finder->find('vista residences lahug')?->name);
        $this->assertNull($finder->find('Nowhere Heights'));
        $this->assertNull($finder->find('Gone Towers'), 'a deleted development is gone');
    }

    public function test_a_city_hint_prefers_the_development_in_that_city(): void
    {
        $finder = app(ProjectFinder::class);

        $this->assertSame('Camella Cebu', $finder->find('Camella')?->name, 'the most viewed wins without a hint');
        $this->assertSame('Camella Davao', $finder->find('Camella', 'Davao')?->name);
    }

    public function test_a_barangay_or_city_name_is_never_a_development(): void
    {
        $finder = app(ProjectFinder::class);

        $this->assertNull($finder->find('Lahug'), '"listings in Lahug" is the barangay, not Vista Residences Lahug');
        $this->assertNull($finder->find('Cebu City'));
    }

    public function test_the_buildings_own_words_are_not_search_keywords(): void
    {
        $this->assertSame('condo', DataLayerService::keywordsOutside('Horizon 101 condo', 'Horizon 101'));
        $this->assertSame('', DataLayerService::keywordsOutside('horizon 101', 'Horizon 101'));
        $this->assertSame('furnished', DataLayerService::keywordsOutside('furnished', 'Mivesa Garden Residences'));
    }
}
