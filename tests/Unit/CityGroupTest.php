<?php

use App\Support\CityGroup;
use App\Support\ProvinceCanonicalizer;

test('key is one canonical province plus one normalized town name', function () {
    expect(CityGroup::key(25, 'Cebu City'))->toBe('25|cebu')
        ->and(CityGroup::key(25, 'City of Cebu'))->toBe('25|cebu')
        ->and(CityGroup::key(83, 'Catbalogan'))->toBe('83|catbalogan')
        // No province keys under "none", never under 0 or an empty string.
        ->and(CityGroup::key(null, 'Carmen'))->toBe('none|carmen')
        // Two provinces, same name: different towns.
        ->and(CityGroup::key(25, 'Carmen'))->not->toBe(CityGroup::key(26, 'Carmen'));
});

test('groupIds returns every cities row that is the same town, across a province\'s duplicate rows', function () {
    // Mirrors the heatmap fixture: Catbalogan is filed once under Samar (83)
    // and once under its legacy twin "Southern Samar" (82).
    $provinceMap = ProvinceCanonicalizer::idMap([25 => 'Cebu', 82 => 'Southern Samar', 83 => 'Samar']);
    $cities = [
        ['id' => 1, 'name' => 'Cebu City', 'province_id' => 25],
        ['id' => 2, 'name' => 'Talisay City', 'province_id' => 25],
        ['id' => 3, 'name' => 'Catbalogan', 'province_id' => 83],
        ['id' => 4, 'name' => 'Calbayog', 'province_id' => 82],
        ['id' => 5, 'name' => 'Catbalogan', 'province_id' => 82],
        ['id' => 6, 'name' => 'Carmen', 'province_id' => 25],
        ['id' => 7, 'name' => 'Carmen', 'province_id' => 83],
    ];

    expect(CityGroup::groupIds($cities, $provinceMap, 5))->toBe([3, 5])
        ->and(CityGroup::groupIds($cities, $provinceMap, 3))->toBe([3, 5])
        ->and(CityGroup::groupIds($cities, $provinceMap, 1))->toBe([1])
        // A same-name town in ANOTHER province is a different town.
        ->and(CityGroup::groupIds($cities, $provinceMap, 6))->toBe([6])
        ->and(CityGroup::groupIds($cities, $provinceMap, 7))->toBe([7]);
});

test('groupIds accepts the stdClass rows DB::table() returns and passes an unknown id through', function () {
    $cities = [
        (object) ['id' => '1', 'name' => 'Cebu City', 'province_id' => '25'],
        (object) ['id' => '9', 'name' => 'City of Cebu', 'province_id' => '25'],
    ];

    expect(CityGroup::groupIds($cities, [25 => 25], 9))->toBe([1, 9])
        ->and(CityGroup::groupIds($cities, [25 => 25], 999))->toBe([999]);
});

test('groupIds treats a city with no province as its own group under "none"', function () {
    $cities = [
        ['id' => 1, 'name' => 'Carmen', 'province_id' => null],
        ['id' => 2, 'name' => 'Carmen', 'province_id' => null],
        ['id' => 3, 'name' => 'Carmen', 'province_id' => 25],
    ];

    expect(CityGroup::groupIds($cities, [25 => 25], 1))->toBe([1, 2])
        ->and(CityGroup::groupIds($cities, [25 => 25], 3))->toBe([3]);
});

test('canonicalId is the lowest LINKED id, else the lowest id — offerCityId\'s rule', function () {
    // The polygon points at the higher twin: that row is the one the map can
    // shade, so it is the one that holds the count and the cache key.
    expect(CityGroup::canonicalId([3, 5], [5 => true]))->toBe(5)
        // Both linked → lowest linked.
        ->and(CityGroup::canonicalId([5, 3], [3 => true, 5 => true]))->toBe(3)
        // Neither linked → lowest id.
        ->and(CityGroup::canonicalId([5, 3], []))->toBe(3)
        // A linked id outside the group is irrelevant.
        ->and(CityGroup::canonicalId([3, 5], [9 => true]))->toBe(3)
        // Single-member group.
        ->and(CityGroup::canonicalId([448], []))->toBe(448);
});

test('canonicalId accepts the linked ids as a plain list too', function () {
    expect(CityGroup::canonicalId([3, 5], [5]))->toBe(5)
        ->and(CityGroup::canonicalId([3, 5], ['5']))->toBe(5)
        ->and(CityGroup::canonicalId([3, 5], [3, 5]))->toBe(3);
});
