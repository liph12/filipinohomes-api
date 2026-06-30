<?php

use App\Support\OfficeRegionMap;

/** Read a (private) const array off a class via reflection. */
function officeMapConst(string $name): array
{
    return (new ReflectionClass(OfficeRegionMap::class))->getConstant($name);
}

test('regionOf maps representative LR states to their office region', function () {
    $cases = [
        // grouped: province / city → region
        'Cebu' => 'cebu',
        'Mactan' => 'cebu',
        'Cordova' => 'cebu',
        'Negros Oriental' => 'dumaguete',
        'Siquijor' => 'dumaguete',
        'Negros Occidental' => 'bacolod',
        'Bacolod' => 'bacolod',
        'Iloilo' => 'iloilo',
        'Aklan' => 'iloilo',
        'Leyte' => 'leyte',
        'Tacloban' => 'leyte',
        'Metro Manila' => 'metro-manila',
        'Manila' => 'metro-manila',
        'Bulacan' => 'metro-manila',
        // cagayan office: the superior's headline requirement.
        'Bukidnon' => 'cagayan',
        'Misamis Oriental' => 'cagayan',
        'Butuan' => 'cagayan',
        // gensan office.
        'General Santos' => 'gensan',
        'Sarangani' => 'gensan',
        // davao office covers the whole Davao region — LR returns province-level
        // states (real casing "Davao Del Norte"/"Davao Del Sur"), not just "Davao".
        'Davao' => 'davao',
        'Davao Del Norte' => 'davao',
        'Davao Del Sur' => 'davao',
        'Davao Oriental' => 'davao',
        // standalone regions match their own name.
        'Bohol' => 'bohol',
        'Iligan' => 'iligan',
        'Palawan' => 'palawan',
    ];

    foreach ($cases as $state => $expected) {
        expect([$state, OfficeRegionMap::regionOf($state)])->toBe([$state, $expected]);
    }
});

test('the three Lapu-lapu spellings all collapse to cebu', function () {
    foreach (['Lapu-lapu', 'Lapu-lapu City', 'Lapu- lapu City', 'Lapu - lapu City'] as $variant) {
        expect([$variant, OfficeRegionMap::regionOf($variant)])->toBe([$variant, 'cebu']);
    }
});

test('overlap precedence: a standalone region name wins over a grouped membership', function () {
    // "Pampanga" appears inside metro-manila's data AND is its own standalone
    // region — the standalone region must win.
    expect(OfficeRegionMap::regionOf('Pampanga'))->toBe('pampanga');

    // "Cotabato" / "Cotabato City" live only under gensan (no standalone Cotabato
    // region), so they resolve deterministically to gensan.
    expect(OfficeRegionMap::regionOf('Cotabato'))->toBe('gensan');
    expect(OfficeRegionMap::regionOf('Cotabato City'))->toBe('gensan');
});

test('this is NOT the PSA RegionMap taxonomy', function () {
    // PSA RegionMap puts Bukidnon under northern-mindanao; the office map must
    // disagree on purpose (Cagayan de Oro office).
    expect(OfficeRegionMap::regionOf('Bukidnon'))->toBe('cagayan');
    expect(OfficeRegionMap::regionOf('Bukidnon'))->not->toBe('northern-mindanao');
});

test('regionOf returns null for empty / unmapped states', function () {
    expect(OfficeRegionMap::regionOf(null))->toBeNull();
    expect(OfficeRegionMap::regionOf(''))->toBeNull();
    expect(OfficeRegionMap::regionOf('   '))->toBeNull();
    expect(OfficeRegionMap::regionOf('Atlantis'))->toBeNull();
});

test('label returns the human form and isValid guards keys', function () {
    expect(OfficeRegionMap::label('metro-manila'))->toBe('Metro Manila');
    expect(OfficeRegionMap::label('cebu'))->toBe('Cebu');
    expect(OfficeRegionMap::label('unknown-key'))->toBe('Unknown-key');

    expect(OfficeRegionMap::isValid('cebu'))->toBeTrue();
    expect(OfficeRegionMap::isValid('cagayan'))->toBeTrue();
    expect(OfficeRegionMap::isValid('nope'))->toBeFalse();
});

test('REGIONS, LABELS, and the GROUPS/STANDALONE keys stay consistent', function () {
    $regions = OfficeRegionMap::REGIONS;
    sort($regions);

    $labelKeys = array_keys(officeMapConst('LABELS'));
    sort($labelKeys);
    expect($labelKeys)->toBe($regions);

    // Every grouped + standalone key is a known region.
    foreach (array_keys(officeMapConst('GROUPS')) as $k) {
        expect([$k, OfficeRegionMap::isValid($k)])->toBe([$k, true]);
    }
    foreach (array_keys(officeMapConst('STANDALONE')) as $k) {
        expect([$k, OfficeRegionMap::isValid($k)])->toBe([$k, true]);
    }

    // Every name in both tables resolves to a non-null region.
    foreach (officeMapConst('GROUPS') as $names) {
        foreach ($names as $name) {
            expect([$name, OfficeRegionMap::regionOf($name) !== null])->toBe([$name, true]);
        }
    }
    foreach (officeMapConst('STANDALONE') as $region => $name) {
        // A standalone region's own name must resolve to itself (overlap win).
        expect([$name, OfficeRegionMap::regionOf($name)])->toBe([$name, $region]);
    }
});
