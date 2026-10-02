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

test('normalize knows the 2023 PSA barangay file\'s town spellings', function () {
    $cases = [
        // "Pres." expands like the Gen./Sta. family — cities 396/1663 "Pres.
        // Roxas", 1411 "Pres. Quirino" vs the file's spelled-out form.
        'Pres. Roxas' => 'presidentroxas',
        'President Roxas' => 'presidentroxas',
        'Pres. Quirino' => 'presidentquirino',
        'Pres. Manuel A. Roxas' => 'presidentmanuelaroxas',
        // …but only as a whole token: Presentacion (city 375) is left alone.
        'Presentacion (Parubcan)' => 'presentacion',
        'Presentacion' => 'presentacion',
        // "Science City of" is a status phrase, like "Island Garden City of".
        'Science City of Muñoz' => 'munoz',
        'Munoz' => 'munoz',
    ];

    foreach ($cases as $name => $expected) {
        expect([$name, CityNameMatcher::normalize($name)])->toBe([$name, $expected]);
    }
});

test('the barangay file\'s renamed towns reach their cities rows through aliases', function () {
    // Pampanga: file "Sasmuan (Sexmoan)" — normalize() drops the parenthetical.
    expect(CityNameMatcher::match([1195 => 'Sexmoan', 1196 => 'Guagua'], 'Sasmuan (Sexmoan)'))->toBe(1195);

    // Antique: file "San Jose (Capital)" vs cities 104 "San Jose de Buenavista".
    expect(CityNameMatcher::match([104 => 'San Jose de Buenavista', 105 => 'Sibalom'], 'San Jose (Capital)'))->toBe(104);

    // Nueva Ecija: both routes land on 1109 "Munoz".
    expect(CityNameMatcher::match([1109 => 'Munoz'], 'Science City of Muñoz'))->toBe(1109)
        ->and(CityNameMatcher::aliasKey('Science Muñoz'))->toBe('munoz');
});

test('REGRESSION: the San Jose alias is a second chance only — a plain San Jose row still wins', function () {
    // Ten provinces have a plain "San Jose"; none of them is Antique's capital.
    $batangas = [200 => 'San Jose', 201 => 'Lipa City'];

    expect(CityNameMatcher::match($batangas, 'San Jose'))->toBe(200);

    // And a province with neither row gets nothing, not Antique's city.
    expect(CityNameMatcher::match([201 => 'Lipa City'], 'San Jose'))->toBeNull();
});

test('rewriteSga reduces a Special Geographic Area group to the Cotabato town it was carved from', function () {
    $cases = [
        'Special Geographic Area - Pikit II' => 'Pikit',
        'Special Geographic Area - Pikit III' => 'Pikit',
        'Special Geographic Area - Midsayap I' => 'Midsayap',
        'Special Geographic Area - Kabacan' => 'Kabacan',
        'Special Geographic Area - Carmen' => 'Carmen',
        'Special Geographic Area - Pigkawayan' => 'Pigkawayan',
        // Anything else is returned untouched, including a town whose name
        // merely ends in roman-numeral letters.
        'Cebu City' => 'Cebu City',
        'Tondo I / II' => 'Tondo I / II',
        'Davao' => 'Davao',
    ];

    foreach ($cases as $name => $expected) {
        expect([$name, CityNameMatcher::rewriteSga($name)])->toBe([$name, $expected]);
    }

    // The rewritten name then matches like any other town.
    expect(CityNameMatcher::match([1660 => 'Pikit', 1661 => 'Kabacan'], CityNameMatcher::rewriteSga('Special Geographic Area - Pikit II')))->toBe(1660);
});
