<?php

use App\Support\CityNameMatcher;

test('normalize strips status words so the file and the DB spell a city the same', function () {
    $cases = [
        // Abbreviation vs full name — cities 428 "Gen. Trias" / file "City of General Trias".
        'City of General Trias' => 'generaltrias',
        'Gen. Trias' => 'generaltrias',
        'Gen Trias' => 'generaltrias',
        // The DB's disambiguating parenthetical — cities 1171 / file "Narra".
        'Narra (Panacan)' => 'narra',
        'Narra' => 'narra',
        'Montalban (Rodriguez)' => 'montalban',
        // The longest status phrase must win over the bare "city".
        'Island Garden City of Samal' => 'samal',
        'Samal' => 'samal',
        // Ordinary status suffixes/prefixes.
        'City of Talisay' => 'talisay',
        'Talisay City' => 'talisay',
        'General Santos City' => 'generalsantos',
        'Municipality of Carmen' => 'carmen',
        // Abbreviation family, and a word that merely starts with one.
        'Sta. Cruz' => 'santacruz',
        'Sto. Tomas' => 'santotomas',
        'Mt. Province' => 'mountprovince',
        'General Luna' => 'generalluna',
        // Transliteration must not lose the name.
        'Dasmariñas' => 'dasmarinas',
    ];

    foreach ($cases as $name => $expected) {
        expect([$name, CityNameMatcher::normalize($name)])->toBe([$name, $expected]);
    }
});

test('aliasKey folds the names the two sources genuinely disagree on', function () {
    $cases = [
        'Bacong' => 'bacung',                  // cities 1057 "Bacung"
        'Rodriguez' => 'montalban',            // cities 1313 "Montalban (Rodriguez)"
        'Salvador Benedicto' => 'donsalvador', // cities 1716 "Don Salvador"
        'Don Salvador Benedicto' => 'donsalvador',
        'Enrique B. Magalona' => 'enriquemagalona', // cities 1026
        'Lupon' => 'lopon',                    // cities 548 "Lopon" (Davao Oriental)
        // Manila's 16 districts all resolve to the single row 1555 "Manila City".
        'Binondo' => 'manila',
        'Tondo I / II' => 'manila',
        'Port Area' => 'manila',
        'Santa Mesa' => 'manila',
        'Manila City' => 'manila',
        // A name with no alias comes back as its plain normalized form.
        'Cebu City' => 'cebu',
    ];

    foreach ($cases as $name => $expected) {
        expect([$name, CityNameMatcher::aliasKey($name)])->toBe([$name, $expected]);
    }
});

test('pass A takes an exact match inside the province group', function () {
    $candidates = [100 => 'Gen. Trias', 101 => 'Tagaytay City'];

    expect(CityNameMatcher::match($candidates, 'City of General Trias'))->toBe(100);
});

test('pass A lets many boundaries claim one city, in the order the caller prefers', function () {
    // Manila's districts are the real case: 16 polygons, one city row.
    $candidates = [1555 => 'Manila City'];

    expect(CityNameMatcher::match($candidates, 'Binondo', [1555]))->toBe(1555)
        ->and(CityNameMatcher::match($candidates, 'Tondo I / II', [1555]))->toBe(1555);

    // Duplicate rows in one group: candidate ORDER is the caller's tie-break.
    $duplicates = [900 => 'Carmen', 300 => 'Carmen'];
    expect(CityNameMatcher::match($duplicates, 'Carmen'))->toBe(900);
});

test('REGRESSION: a provincial Santa Cruz keeps its own city and never becomes Manila', function () {
    // 8 of the 16 Manila district names are ordinary municipalities elsewhere
    // (the ADM3 file alone has 7 "Santa Cruz" and 8 "San Miguel"). The alias is
    // a second chance, so the plain spelling must win inside the group.
    $laguna = [770 => 'Santa Cruz', 771 => 'San Miguel', 772 => 'Pagsanjan'];

    expect(CityNameMatcher::match($laguna, 'Santa Cruz'))->toBe(770)
        ->and(CityNameMatcher::match($laguna, 'San Miguel'))->toBe(771);

    // And a district with no provincial twin must not grab one of them either.
    expect(CityNameMatcher::match($laguna, 'Binondo'))->toBeNull();
});

test('REGRESSION: a candidate city name is never alias-folded', function () {
    // If the DB side were run through aliasKey(), "Santa Cruz" would key to
    // "manila" and this boundary would wrongly claim it.
    expect(CityNameMatcher::match([770 => 'Santa Cruz'], 'Manila City'))->toBeNull();
});

test('pass B only accepts a nationwide name the caller certified unique', function () {
    $candidates = [100 => 'Argao', 101 => 'Dalaguete'];
    $unique = ['generalsantos' => 777];

    expect(CityNameMatcher::match($candidates, 'General Santos City', [], $unique))->toBe(777);
    // Without the index there is no nationwide fallback at all.
    expect(CityNameMatcher::match($candidates, 'General Santos City'))->toBeNull();
});

test('pass C refuses short names: Leon must never match Oton', function () {
    // "leon" -> "oton" is Levenshtein 2, i.e. inside the fuzzy budget. The
    // 6-character floor is the only thing stopping it, so assert it directly.
    expect(levenshtein('leon', 'oton'))->toBe(2)
        ->and(CityNameMatcher::match([666 => 'Oton'], 'Leon'))->toBeNull();
});

test('an alias rescues a near miss the length floor locks out', function () {
    // "Lupon" (boundary file) vs "Lopon" (cities 548, Davao Oriental) is
    // Levenshtein 1 — but both are 5 characters, and pass C refuses anything
    // under 6. Without the alias this town's polygon is unreachable.
    expect(levenshtein('lupon', 'lopon'))->toBe(1)
        ->and(CityNameMatcher::match([548 => 'Lopon'], 'Lupon'))->toBe(548);
});

test('pass C matches a near miss once the name is long enough', function () {
    expect(CityNameMatcher::match([500 => 'Talisayan'], 'Talisayon'))->toBe(500);
});

test('pass C never returns a city another boundary already claimed', function () {
    $candidates = [500 => 'Talisayan'];

    expect(CityNameMatcher::match($candidates, 'Talisayon', [500]))->toBeNull();
});

test('match returns null when nothing is close enough', function () {
    expect(CityNameMatcher::match([500 => 'Talisayan'], 'Zamboanga'))->toBeNull()
        ->and(CityNameMatcher::match([], 'Cebu City'))->toBeNull()
        ->and(CityNameMatcher::match([500 => 'Talisayan'], 'City'))->toBeNull();
});
