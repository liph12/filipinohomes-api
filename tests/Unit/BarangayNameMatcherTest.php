<?php

use App\Support\BarangayNameMatcher;

// ───────────────────────────── normalisation ─────────────────────────────

test('normalize folds the Poblacion marker, numbering and abbreviations the two sources disagree on', function () {
    $cases = [
        // Cebu City: 11 of its 80 polygons link only because "(Pob.)" goes.
        'Lahug (Pob.)' => 'lahug',
        'Lahug' => 'lahug',
        'Kamagayan (Pob.)' => 'kamagayan',
        // A bare Poblacion in any of its spellings IS the name.
        'Poblacion' => 'poblacion',
        'Pob.' => 'poblacion',
        'Población' => 'poblacion',
        // With anything else left, the marker is dropped and the rest is the
        // key; the pob-kept variant "poblacion1" survives as a second outer
        // key (see the tiers test), and tier 5 fans this onto a bare row.
        'Poblacion I' => '1',
        // Roman vs arabic numbering, with and without the marker.
        'Sambag I (Pob.)' => 'sambag1',
        'Sambag 1' => 'sambag1',
        'Sambag II' => 'sambag2',
        'Zone II-A' => 'zone2a',
        'Barangay 01' => '1',
        'Brgy. 1' => '1',
        // "Proper" is a droppable suffix, not a marker.
        'Minuyan Proper' => 'minuyan',
        'Minuyan' => 'minuyan',
        // Abbreviation family; "St." expands to "San" on purpose (the two
        // sources write the same saint either way), while "Sto." stays "Santo".
        'Sto. Niño' => 'santonino',
        'St. Niño' => 'sannino',
        'San Niño' => 'sannino',
        'Sta. Cruz' => 'santacruz',
        'Gen. Luna' => 'generalluna',
        'Pres. Roxas' => 'presidentroxas',
        'Mariano Badelles Sr.' => 'marianobadellessenior',
        // Apostrophes vanish rather than separating tokens.
        "Don Pedro's" => 'donpedros',
        // Parentheticals never reach the outer key.
        'San Isidro (Jaro)' => 'sanisidro',
        'Tejero (Villa Gonzalo)' => 'tejero',
    ];

    foreach ($cases as $name => $expected) {
        expect([$name, BarangayNameMatcher::normalize($name)])->toBe([$name, $expected]);
    }
});

test('tiers separate the outer text from the inner text with a bar', function () {
    // Cabatuan numbers its zones "Zone I Pob. (Barangay 1)" … "Zone XI Pob.
    // (Barangay 11)"; without the "|" "zone1" + "1" would be "zone11".
    $one = BarangayNameMatcher::tiers('Zone I Pob. (Barangay 1)');
    $eleven = BarangayNameMatcher::tiers('Zone XI Pob. (Barangay 11)');

    expect($one['full'])->toBe(['zone1|1'])
        ->and($eleven['full'])->toBe(['zone11|11'])
        ->and($one['outer'])->toContain('zone1')
        ->and($eleven['outer'])->toContain('zone11')
        ->and($one['is_pob'])->toBeTrue()
        ->and(array_intersect($one['full'], $eleven['full']))->toBe([])
        ->and(array_intersect($one['outer'], $eleven['outer']))->toBe([]);

    // The pob-kept variant is an outer key too, so two names that both spell
    // out Poblacion still meet at tier 2.
    expect(BarangayNameMatcher::tiers('Asinan Poblacion')['outer'])->toBe(['asinanpoblacion', 'asinan'])
        ->and(BarangayNameMatcher::tiers('Poblacion I')['outer'])->toBe(['poblacion1', '1'])
        ->and(BarangayNameMatcher::tiers('Poblacion I')['is_pob'])->toBeTrue();

    // A name without a parenthetical has no full key at all.
    expect(BarangayNameMatcher::tiers('Lahug')['full'])->toBe([])
        ->and(BarangayNameMatcher::tiers('Tejero (Villa Gonzalo)')['inner'])->toBe(['villagonzalo']);
});

test('fullKey keeps the Iloilo pair apart and still folds identical twins', function () {
    expect(BarangayNameMatcher::fullKey('San Isidro (Jaro)'))->toBe('sanisidro|jaro')
        ->and(BarangayNameMatcher::fullKey('San Isidro (La Paz)'))->toBe('sanisidro|lapaz')
        ->and(BarangayNameMatcher::fullKey('Lagtang'))->toBe('lagtang')
        ->and(BarangayNameMatcher::fullKey('Lahug (Pob.)'))->toBe('lahug');
});

test('foldKey folds identical twins but keeps a Proper / Poblacion sibling apart', function () {
    // Identical rows of one town still fold (Talisay's two "Lagtang").
    expect(BarangayNameMatcher::foldKey('Lagtang'))->toBe(BarangayNameMatcher::foldKey('Lagtang'));

    // Pairs the PSA file lists as SEPARATE barangays must stay separate keys,
    // which is exactly where fullKey() — built to reconcile two sources —
    // would collapse them.
    $pairs = [
        ['Asinan Poblacion', 'Asinan Proper'],       // Subic #31695 / #31696
        ['Minuyan', 'Minuyan Proper'],               // San Jose del Monte #24625 / #24660
        ['Ilian', 'Ilian Poblacion'],                // Piagapo #22926 / #22902
        ['San Isidro (Jaro)', 'San Isidro (La Paz)'],
    ];

    foreach ($pairs as [$a, $b]) {
        expect([$a, $b, BarangayNameMatcher::foldKey($a) === BarangayNameMatcher::foldKey($b)])
            ->toBe([$a, $b, false]);
    }

    expect(BarangayNameMatcher::fullKey('Asinan Poblacion'))->toBe(BarangayNameMatcher::fullKey('Asinan Proper'));
});

test('qualifierInners flags an inner text shared by two names, and the city name', function () {
    $iloilo = ['San Isidro (Jaro)', 'Tabucan (Jaro)', 'San Isidro (La Paz)', 'Tejero (Villa Gonzalo)'];

    expect(BarangayNameMatcher::qualifierInners($iloilo))->toBe(['jaro' => true])
        ->and(BarangayNameMatcher::qualifierInners($iloilo, 'Iloilo City'))->toBe(['jaro' => true, 'iloilocity' => true])
        ->and(BarangayNameMatcher::qualifierInners(['Poblacion (Dumalneg)'], 'Dumalneg'))->toBe(['dumalneg' => true]);
});

// ──────────────────────────────── matching ────────────────────────────────

test('tier 2 links the (Pob.) polygon to the bare registry row', function () {
    $registry = [803 => 'Lahug', 455 => 'Mabolo'];

    expect(BarangayNameMatcher::match($registry, 'Lahug (Pob.)'))->toBe(['id' => 803, 'how' => 'exact_outer'])
        ->and(BarangayNameMatcher::match($registry, 'Sambag 1'))->toBeNull();
});

test('every spelling of a bare Poblacion reaches the Poblacion row', function () {
    $registry = [10 => 'Poblacion', 11 => 'Lahug'];

    foreach (['Poblacion', 'Pob.', 'Población', 'Poblacion (Pob.)'] as $spelling) {
        expect([$spelling, BarangayNameMatcher::match($registry, $spelling)['id'] ?? null])->toBe([$spelling, 10]);
    }
});

test('roman and arabic numbering meet: Sambag I (Pob.) is Sambag 1', function () {
    $registry = [1 => 'Sambag 1', 2 => 'Sambag 2'];

    expect(BarangayNameMatcher::match($registry, 'Sambag I (Pob.)'))->toBe(['id' => 1, 'how' => 'exact_outer'])
        ->and(BarangayNameMatcher::match($registry, 'Sambag II (Pob.)'))->toBe(['id' => 2, 'how' => 'exact_outer']);
});

test('REGRESSION: Cabatuan Zone I is never Zone XI', function () {
    $registry = [1 => 'Zone I Pob. (Barangay 1)', 11 => 'Zone XI Pob. (Barangay 11)'];
    $polygons = ['PH1' => 'Zone I Pob. (Barangay 1)', 'PH11' => 'Zone XI Pob. (Barangay 11)'];

    $linked = BarangayNameMatcher::matchCity($registry, $polygons, 'Cabatuan');

    expect($linked['PH1'])->toBe(['id' => 1, 'how' => 'exact_full'])
        ->and($linked['PH11'])->toBe(['id' => 11, 'how' => 'exact_full']);

    // And with only Zone XI in the registry, Zone I stays unmatched rather
    // than taking it (keys are short enough that fuzzy is refused anyway).
    expect(BarangayNameMatcher::match([11 => 'Zone XI Pob. (Barangay 11)'], 'Zone I Pob. (Barangay 1)'))->toBeNull();
});

test('REGRESSION: the parenthetical stays in the key — San Isidro (Jaro) is not San Isidro (La Paz)', function () {
    $registry = [71 => 'San Isidro (La Paz)', 72 => 'San Isidro (Jaro)', 73 => 'Tabucan (Jaro)'];
    $polygons = ['J' => 'San Isidro (Jaro)', 'L' => 'San Isidro (La Paz)', 'T' => 'Tabucan (Jaro)'];

    $linked = BarangayNameMatcher::matchCity($registry, $polygons, 'Iloilo City');

    expect($linked['J'])->toBe(['id' => 72, 'how' => 'exact_full'])
        ->and($linked['L'])->toBe(['id' => 71, 'how' => 'exact_full'])
        ->and($linked['T'])->toBe(['id' => 73, 'how' => 'exact_full']);
});

test('tier 3 takes an alternate name in either direction', function () {
    // Polygon inner ↔ registry outer.
    expect(BarangayNameMatcher::match([5 => 'Villa Gonzalo'], 'Tejero (Villa Gonzalo)'))
        ->toBe(['id' => 5, 'how' => 'alt_name']);

    // Polygon outer ↔ registry inner.
    expect(BarangayNameMatcher::match([5 => 'Tejero (Villa Gonzalo)'], 'Villa Gonzalo'))
        ->toBe(['id' => 5, 'how' => 'alt_name']);
});

test('tier 3 refuses an inner text that is a district qualifier', function () {
    // "(Jaro)" sits on two polygons of the city, so it names a district, not
    // the barangay: the "San Isidro (Jaro)" polygon must not take the "Jaro"
    // row just because its own registry twin is missing.
    $registry = [1 => 'Jaro', 2 => 'Tabucan'];
    $polygons = ['A' => 'San Isidro (Jaro)', 'B' => 'Tabucan (Jaro)'];

    $linked = BarangayNameMatcher::matchCity($registry, $polygons, 'Iloilo City');

    expect($linked['A'])->toBe(['id' => null, 'how' => 'unmatched'])
        ->and($linked['B'])->toBe(['id' => 2, 'how' => 'exact_outer']);

    // Same exclusion through match() when the caller passes the city's names.
    expect(BarangayNameMatcher::match($registry, 'San Isidro (Jaro)', [], [
        'polygon_names' => ['San Isidro (Jaro)', 'Tabucan (Jaro)'],
    ]))->toBeNull();

    // The city's own name is a qualifier too: "Poblacion (Dumalneg)" in
    // Dumalneg must not reach a row called "Dumalneg".
    expect(BarangayNameMatcher::match([9 => 'Dumalneg'], 'Poblacion (Dumalneg)', [], ['city_name' => 'Dumalneg']))->toBeNull();

    // And the registry side: an inner shared by two registry rows is not an
    // alternate name for either, so a bare "Jaro" polygon stays unmatched.
    expect(BarangayNameMatcher::match([1 => 'San Isidro (Jaro)', 2 => 'Tabucan (Jaro)'], 'Jaro'))->toBeNull();
});

test('tier 4 fixes a spelling slip once both keys are long enough', function () {
    expect(BarangayNameMatcher::match([1 => 'Casocos'], 'Cosocos'))->toBe(['id' => 1, 'how' => 'fuzzy'])
        ->and(BarangayNameMatcher::match([2 => 'Calumboyan Norte'], 'Calomboyan Norte'))->toBe(['id' => 2, 'how' => 'fuzzy']);

    // Under six characters edit distance stops telling places apart.
    expect(levenshtein('tubod', 'tubay'))->toBe(2)
        ->and(BarangayNameMatcher::match([3 => 'Tubay'], 'Tubod'))->toBeNull();
});

test('tier 4 is refused when the keys differ only by a trailing numeral', function () {
    // "Sambag I" vs "Sambag II" are one edit apart — siblings, not a typo.
    expect(levenshtein('sambag1', 'sambag2'))->toBe(1)
        ->and(BarangayNameMatcher::match([2 => 'Sambag II'], 'Sambag I'))->toBeNull();

    // "Fatima I" vs "Fatima": a numbered polygon never fuzzy-matches its stem…
    expect(levenshtein('fatima1', 'fatima'))->toBe(1);
    $linked = BarangayNameMatcher::match([7 => 'Fatima'], 'Fatima I');
    // …it fans in on it instead (tier 5), so the "how" is what tells them apart.
    expect($linked)->toBe(['id' => 7, 'how' => 'fanin']);

    // Nor the other way round: a bare polygon does not fuzzy onto a numbered row.
    expect(BarangayNameMatcher::match([8 => 'Fatima I'], 'Fatima'))->toBeNull();
});

test('tier 4 never returns a row another polygon already claimed', function () {
    expect(BarangayNameMatcher::match([1 => 'Casocos'], 'Cosocos', [1]))->toBeNull();
});

test('tier 5 fans numbered siblings into one unclaimed registry row', function () {
    $registry = [7 => 'Fatima', 8 => 'Minuyan Proper', 9 => 'Tanza'];
    $polygons = ['F1' => 'Fatima I', 'F2' => 'Fatima II', 'F3' => 'Fatima III', 'M1' => 'Minuyan I', 'T1' => 'Tanza 1', 'T2' => 'Tanza 2'];

    $linked = BarangayNameMatcher::matchCity($registry, $polygons);

    expect($linked['F1'])->toBe(['id' => 7, 'how' => 'fanin'])
        ->and($linked['F2'])->toBe(['id' => 7, 'how' => 'fanin'])
        ->and($linked['F3'])->toBe(['id' => 7, 'how' => 'fanin'])
        ->and($linked['M1'])->toBe(['id' => 8, 'how' => 'fanin'])
        ->and($linked['T1'])->toBe(['id' => 9, 'how' => 'fanin'])
        ->and($linked['T2'])->toBe(['id' => 9, 'how' => 'fanin']);
});

test('tier 5 fans (Pob.) polygons into a bare Poblacion row, but never a claimed one', function () {
    $registry = [10 => 'Poblacion', 11 => 'Lahug'];

    // A town whose registry has one "Poblacion" for the file's split zones.
    $linked = BarangayNameMatcher::matchCity($registry, ['A' => 'Zona Uno (Pob.)', 'B' => 'Zona Dos (Pob.)', 'C' => 'Lahug']);
    expect($linked['A'])->toBe(['id' => 10, 'how' => 'fanin'])
        ->and($linked['B'])->toBe(['id' => 10, 'how' => 'fanin'])
        ->and($linked['C'])->toBe(['id' => 11, 'how' => 'exact_outer']);

    // Once an exact polygon owns the Poblacion row, nothing fans into it.
    $linked = BarangayNameMatcher::matchCity($registry, ['P' => 'Poblacion', 'A' => 'Zona Uno (Pob.)']);
    expect($linked['P'])->toBe(['id' => 10, 'how' => 'exact_outer'])
        ->and($linked['A'])->toBe(['id' => null, 'how' => 'unmatched']);
});

test('tier 5 needs a stem of at least four characters', function () {
    // "Zone 1" → stem "zone" (4) may fan in; a three-letter stem may not.
    expect(BarangayNameMatcher::match([1 => 'Zone'], 'Zone 1'))->toBe(['id' => 1, 'how' => 'fanin'])
        ->and(BarangayNameMatcher::match([2 => 'Rio'], 'Rio 1'))->toBeNull();
});

test('tier 6 follows a hyphen-prefix rename', function () {
    expect(BarangayNameMatcher::match([1 => 'Acmac'], 'Acmac-Mariano Badelles Sr.'))->toBe(['id' => 1, 'how' => 'prefix'])
        ->and(BarangayNameMatcher::match([2 => 'Linno (Villa Cruz*)'], 'Linno-C (Villa Cruz*)'))->toBe(['id' => 2, 'how' => 'prefix']);

    // A prefix under five characters is too little to name a barangay by.
    expect(BarangayNameMatcher::match([3 => 'Agus'], 'Agus-Mariano Badelles Sr.'))->toBeNull();
});

test('the first exact hit wins in the order the caller lists candidates', function () {
    // Talisay City's "Lagtang" twins: the caller orders by listing count then
    // id, so the row that holds the listings is the one the polygon takes.
    $byListings = [3302 => 'Lagtang', 3101 => 'Lagtang'];
    expect(BarangayNameMatcher::match($byListings, 'Lagtang'))->toBe(['id' => 3302, 'how' => 'exact_outer']);

    $byId = [3101 => 'Lagtang', 3302 => 'Lagtang'];
    expect(BarangayNameMatcher::match($byId, 'Lagtang'))->toBe(['id' => 3101, 'how' => 'exact_outer']);
});

test('claimed ids are never returned, by any tier', function () {
    $registry = [1 => 'Lahug', 2 => 'Villa Gonzalo', 3 => 'Casocos', 4 => 'Acmac'];

    expect(BarangayNameMatcher::match($registry, 'Lahug (Pob.)', [1]))->toBeNull()
        ->and(BarangayNameMatcher::match($registry, 'Tejero (Villa Gonzalo)', [2]))->toBeNull()
        ->and(BarangayNameMatcher::match($registry, 'Cosocos', [3]))->toBeNull()
        ->and(BarangayNameMatcher::match($registry, 'Acmac-Mariano Badelles Sr.', [4]))->toBeNull();

    // With a twin free, the claimed one is skipped rather than shared.
    expect(BarangayNameMatcher::match([5 => 'Lagtang', 6 => 'Lagtang'], 'Lagtang', [5]))->toBe(['id' => 6, 'how' => 'exact_outer']);
});

test('matchCity runs each tier over the whole city before the next, so a guess never steals an exact row', function () {
    // Polygon "Cosocos" comes FIRST and is one edit from "Casocos"; polygon
    // "Casocos" comes later and is exact. Per-polygon ordering would hand the
    // row to the typo; per-tier ordering gives it to the exact match.
    $registry = [1 => 'Casocos', 2 => 'Cosocoz'];
    $polygons = ['X' => 'Cosocos', 'Y' => 'Casocos'];

    $linked = BarangayNameMatcher::matchCity($registry, $polygons);

    expect($linked['Y'])->toBe(['id' => 1, 'how' => 'exact_outer'])
        ->and($linked['X'])->toBe(['id' => 2, 'how' => 'fuzzy'])
        ->and(array_keys($linked))->toBe(['X', 'Y']);
});

test('matchCity reproduces the Cebu City shape: every (Pob.) polygon lands on its bare row', function () {
    $registry = [803 => 'Lahug', 455 => 'Mabolo', 285 => 'Guadalupe', 7 => 'Sambag I', 8 => 'Sambag II', 9 => 'Kalubihan', 118 => 'Banawa'];
    $polygons = [
        'PH0702217001' => 'Lahug (Pob.)',
        'PH0702217002' => 'Mabolo (Pob.)',
        'PH0702217003' => 'Guadalupe',
        'PH0702217004' => 'Sambag I (Pob.)',
        'PH0702217005' => 'Sambag II (Pob.)',
        'PH0702217006' => 'Kalubihan (Pob.)',
    ];

    $linked = BarangayNameMatcher::matchCity($registry, $polygons, 'Cebu City');

    expect(array_column($linked, 'id'))->toBe([803, 455, 285, 7, 8, 9])
        ->and(array_unique(array_column($linked, 'how')))->toBe(['exact_outer']);

    // Banawa (a sitio the registry carries, the file does not) is simply
    // absent from the result: nothing links to it and nothing invents it.
    expect(in_array(118, array_column($linked, 'id'), true))->toBeFalse();
});

test('match returns null for a blank or unknowable name', function () {
    expect(BarangayNameMatcher::match([1 => 'Lahug'], ''))->toBeNull()
        ->and(BarangayNameMatcher::match([], 'Lahug'))->toBeNull()
        ->and(BarangayNameMatcher::match([1 => 'Lahug'], 'Talamban'))->toBeNull();
});
