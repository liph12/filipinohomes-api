<?php

use App\Support\IslandMap;
use App\Support\RegionMap;

/** Read a (private) const array off a class via reflection. */
function mapConst(string $class, string $name): array
{
    return (new ReflectionClass($class))->getConstant($name);
}

test('every IslandMap province resolves to a region in the same island group', function () {
    $islandGroups = mapConst(IslandMap::class, 'GROUPS'); // island => [province names]

    foreach ($islandGroups as $island => $names) {
        foreach ($names as $name) {
            $region = RegionMap::regionOf($name);
            // Pair the name into the expectation so a failure names the culprit.
            expect([$name, $region !== null])->toBe([$name, true]);
            expect([$name, RegionMap::islandOf($region)])->toBe([$name, $island]);
        }
    }
});

test('every RegionMap province sits in IslandMap under its composed island', function () {
    $regionGroups = mapConst(RegionMap::class, 'GROUPS'); // region => [province names]

    foreach ($regionGroups as $region => $names) {
        $island = RegionMap::REGION_ISLAND[$region];
        foreach ($names as $name) {
            expect([$name, IslandMap::islandOf($name)])->toBe([$name, $island]);
        }
    }
});

test('RegionMap REGIONS list matches REGION_ISLAND + GROUPS keys', function () {
    $regions = RegionMap::REGIONS;
    sort($regions);

    $islandKeys = array_keys(RegionMap::REGION_ISLAND);
    sort($islandKeys);
    expect($islandKeys)->toBe($regions);

    $groupKeys = array_keys(mapConst(RegionMap::class, 'GROUPS'));
    sort($groupKeys);
    expect($groupKeys)->toBe($regions);
});
