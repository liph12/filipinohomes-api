<?php

use App\Support\IslandMap;
use App\Support\ProvinceCanonicalizer;

test('canonical id map folds the real duplicate province rows', function () {
    // The exact shape the local DB produces for the known trouble rows.
    $idToName = [
        53 => 'Northern Samar',
        65 => 'Northern Samar',   // full duplicate row
        82 => 'Southern Samar',   // legacy spelling of 83
        83 => 'Samar',
        81 => 'Metro Manila',
        25 => 'Cebu',
    ];

    $map = ProvinceCanonicalizer::idMap($idToName);
    ksort($map);

    $expected = [25 => 25, 53 => 53, 65 => 53, 81 => 81, 82 => 83, 83 => 83];

    expect($map)->toBe($expected);
});

test('the Samar fold picks the row actually named Samar, not the lower id', function () {
    // Guards the rule that matters: a plain "lowest id wins" would canonicalize
    // onto 82 and label the whole province "Southern Samar" on the map.
    $map = ProvinceCanonicalizer::idMap([82 => 'Southern Samar', 83 => 'Samar']);

    expect($map[82])->toBe(83)
        ->and($map[83])->toBe(83);
});

test('groupIds returns every row sharing a canonical province', function () {
    $idToName = [53 => 'Northern Samar', 65 => 'Northern Samar', 82 => 'Southern Samar', 83 => 'Samar', 25 => 'Cebu'];

    expect(ProvinceCanonicalizer::groupIds($idToName, 83))->toBe([82, 83])
        ->and(ProvinceCanonicalizer::groupIds($idToName, 82))->toBe([82, 83])
        ->and(ProvinceCanonicalizer::groupIds($idToName, 65))->toBe([53, 65])
        ->and(ProvinceCanonicalizer::groupIds($idToName, 25))->toBe([25]);
});

test('groupIds passes an unknown province id straight through', function () {
    expect(ProvinceCanonicalizer::groupIds([25 => 'Cebu'], 999))->toBe([999]);
});

test('ADM2 boundary names key to the province row that owns their listings', function () {
    $cases = [
        // The boundary file splits the capital region into four districts.
        'NCR, City of Manila, First District' => 'metro manila',
        'NCR, Second District' => 'metro manila',
        'NCR, Third District' => 'metro manila',
        'NCR, Fourth District' => 'metro manila',
        'NCR' => 'metro manila',
        'National Capital Region' => 'metro manila',
        // Independent cities the file ranks as ADM2; here they are cities.
        'City of Isabela' => 'basilan',        // city 129 under province 9
        'Cotabato City' => 'maguindanao',      // city 901 under province 45
        // Provinces with no row of their own here.
        'Davao Occidental' => 'davao del sur',      // towns 536-539 under 29
        'Dinagat Islands' => 'surigao del norte',   // row 31 owns zero cities
        // Legacy Samar spellings.
        'Southern Samar' => 'samar',
        'Western Samar' => 'samar',
        // An ordinary name must survive untouched.
        'Cebu' => 'cebu',
    ];

    foreach ($cases as $name => $expected) {
        // Pair the name in so a failure names the culprit.
        expect([$name, ProvinceCanonicalizer::key($name)])->toBe([$name, $expected]);
    }
});

test('MIRROR GUARD: every province alias target is a province IslandMap knows', function () {
    foreach (ProvinceCanonicalizer::aliases() as $source => $target) {
        // An alias pointing at a province the island map cannot place would
        // drop that province out of every island-grouped tile.
        expect([$source, $target, IslandMap::islandOf($target)])
            ->not->toBe([$source, $target, null]);
    }
});

test('MIRROR GUARD: no province alias moves listings to a different island', function () {
    foreach (ProvinceCanonicalizer::aliases() as $source => $target) {
        $sourceIsland = IslandMap::islandOf($source);

        if ($sourceIsland === null) {
            continue; // Source is not a province IslandMap lists; nothing to contradict.
        }

        expect([$source, $sourceIsland])->toBe([$source, IslandMap::islandOf($target)]);
    }
});

test('CONTRACT: creating a Davao Occidental province row must delete its alias, or its listings are swallowed by Davao del Sur', function () {
    // This documents the hazard rather than detecting it — a unit test has no
    // provinces table. The matching DB-backed trip-wire lives in
    // Tests\Feature\ListingHeatmapTest::
    // test_mirror_guard_the_only_province_row_an_alias_folds_away_is_the_documented_one,
    // which builds a provinces table and fails the day a second alias source
    // gains a row of its own.
    $withNewRow = ProvinceCanonicalizer::idMap([29 => 'Davao del Sur', 84 => 'Davao Occidental']);

    // 84 is folded onto 29: real, correct today (towns 536-539 sit under 29),
    // and silently wrong the moment 84 owns cities of its own.
    expect($withNewRow[84])->toBe(29);

    // Same hazard for Dinagat Islands (row 31 exists today but owns no cities).
    $dinagat = ProvinceCanonicalizer::idMap([31 => 'Dinagat Islands', 73 => 'Surigao del Norte']);
    expect($dinagat[31])->toBe(73);
});

test('the 2023 PSA barangay file\'s ADM2 vocabulary keys to the province rows this DB has', function () {
    // Verified 2026-10-02: no `provinces` row carries any of these names, so
    // every one is a pure spelling fold and none is a swallowed row (the
    // DB-backed mirror guard in ListingHeatmapTest still expects exactly two).
    $cases = [
        // The capital region under the file's newer heading.
        'Metropolitan Manila, First District' => 'metro manila',
        'Metropolitan Manila, Second District' => 'metro manila',
        'Metropolitan Manila, Third District' => 'metro manila',
        'Metropolitan Manila, Fourth District' => 'metro manila',
        // Renamed in 2019; row 26 keeps the old name. The parenthetical the
        // file adds is dropped by the normalizer before the alias applies.
        'Davao de Oro (Compostela Valley)' => 'compostela valley',
        'Davao de Oro' => 'compostela valley',
        // Split in 2022; row 45 is the single pre-split province.
        'Maguindanao del Norte' => 'maguindanao',
        'Maguindanao del Sur' => 'maguindanao',
        // BARMM's Special Geographic Area: barangays carved out of Cotabato towns.
        'Special Geographic Area' => 'cotabato',
        // Spellings the same file uses for provinces that needed no alias.
        'Cotabato (North Cotabato)' => 'cotabato',
        'Samar (Western Samar)' => 'samar',
        'City of Isabela (Not a Province)' => 'basilan',
    ];

    foreach ($cases as $name => $expected) {
        expect([$name, ProvinceCanonicalizer::key($name)])->toBe([$name, $expected]);
    }
});
