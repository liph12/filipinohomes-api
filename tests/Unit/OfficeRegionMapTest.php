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

/**
 * The NATCON admin resolves a region by classifying the event's OWN distinct
 * `state` values, precisely because LR's casing is not consistent — both of
 * these are live in natcon_recipients today. Matching the taxonomy's spelling
 * instead would find one and silently lose the other.
 */
test('LR casing drift does not change which office a province belongs to', function () {
    foreach (['Agusan del Sur', 'Agusan Del Sur', 'AGUSAN DEL SUR', 'agusan del sur'] as $variant) {
        expect([$variant, OfficeRegionMap::regionOf($variant)])->toBe([$variant, 'cagayan']);
    }

    foreach (['Agusan del Norte', 'Agusan Del Norte'] as $variant) {
        expect([$variant, OfficeRegionMap::regionOf($variant)])->toBe([$variant, 'cagayan']);
    }

    foreach (['Surigao del Norte', 'Surigao Del Norte', 'Lanao del Sur', 'Lanao Del Sur'] as $variant) {
        expect([$variant, OfficeRegionMap::regionOf($variant)])->toBe([$variant, 'cagayan']);
    }
});

/**
 * ⚠️ This class is MIRRORED into natcon-api-v2, which serves the Awardees
 *    screen and cannot call this app for a static province table. The two
 *    copies drifting would reroute provinces between offices on one screen and
 *    not the other, which is the kind of bug nobody reports as a bug — they
 *    just quietly stop trusting the numbers.
 *
 * Best-effort: the sibling checkout is not guaranteed to be present (CI clones
 * one repo), so this SKIPS rather than fails when it is missing. It still
 * catches the drift on the machine where the edit is being made, which is
 * where it can still be cheap to fix.
 */
test('the natcon-api-v2 mirror carries the identical taxonomy', function () {
    $root = dirname(__DIR__, 2);
    $mirror = $root.'/../natcon-api-v2/app/Support/OfficeRegionMap.php';

    if (! is_file($mirror)) {
        test()->markTestSkipped('natcon-api-v2 is not checked out beside this repo.');
    }

    // Everything from REGIONS through the end of GROUPS — the data, not the
    // docblocks, which differ on purpose (each points at the other).
    $taxonomy = function (string $path): string {
        preg_match('/public const REGIONS.*?\];\s*\n\s*\/\*\* Lazily-built/s', file_get_contents($path), $m);

        return $m[0] ?? '';
    };

    $ours = $taxonomy($root.'/app/Support/OfficeRegionMap.php');

    expect($ours)->not->toBe('')
        ->and($taxonomy($mirror))->toBe($ours);
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
