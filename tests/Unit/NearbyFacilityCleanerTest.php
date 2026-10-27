<?php

use App\Services\Listing\NearbyFacilityCleaner;

$place = fn (string $name, string $address = 'Naga City', int $m = 500) => ['name' => $name, 'address' => $address, 'lat' => 13.6, 'lng' => 123.2, 'distance_meters' => $m];

it('strips Plus Codes from addresses but keeps the locality', function () use ($place) {
    $out = NearbyFacilityCleaner::clean(['school' => [$place('Camalig High School', '4JVC+88, Camalig')]]);
    expect($out['school'][0]['address'])->toBe('Camalig');
});

it('drops wrong-category places but keeps real ones named "Memorial"', function () use ($place) {
    $out = NearbyFacilityCleaner::clean([
        'school' => [$place('MAF Coop Integrated Farm'), $place('Ramon Magsaysay Memorial Elementary School'), $place('RAWIS LTFRB')],
        'park' => [$place('Golden Haven Naga City'), $place('Cebu Memorial Park'), $place('Buffalo Creek Farm'), $place('Plaza Rizal'), $place('San Alfonso Homes Mini Park')],
        'hospital' => [$place('Naga'), $place('Vicente Sotto Memorial Medical Center'), $place('Ames Circle Subdivision')],
    ]);
    expect(array_column($out['school'], 'name'))->toBe(['Ramon Magsaysay Memorial Elementary School'])
        ->and(array_column($out['park'], 'name'))->toBe(['Plaza Rizal', 'San Alfonso Homes Mini Park'])
        ->and(array_column($out['hospital'], 'name'))->toBe(['Vicente Sotto Memorial Medical Center']);
});

it('groups doctors into their building and drops doctors with no building', function () use ($place) {
    $out = NearbyFacilityCleaner::clean(['clinic' => [
        $place('Maria Rosalinda C. Espiritu, MD', 'Mother Seton Hospital, Department Family Medicine, Nursery Road, Naga City', 2100),
        $place('Dr. Ma. Sara Padua Labilles', 'Mother Seton Hospital, Nursery Road, Naga City', 2000),
        $place('Rico P. Nebres, MD', 'Department Surgery, Roxas Avenue, Naga City', 1900),
        $place('Bicol Family Clinic', 'Roxas Avenue, Naga City', 2500),
    ]]);
    expect(array_column($out['clinic'], 'name'))->toBe(['Mother Seton Hospital · 2 doctors', 'Bicol Family Clinic'])
        ->and($out['clinic'][0]['distance_meters'])->toBe(2000);
});

it('sorts nearest first and dedupes by name', function () use ($place) {
    $out = NearbyFacilityCleaner::clean(['pharmacy' => [$place('Mercury Drug', 'A', 900), $place('Watsons', 'B', 300), $place('mercury drug', 'C', 1200)]]);
    expect(array_column($out['pharmacy'], 'name'))->toBe(['Watsons', 'Mercury Drug']);
});

it('keeps named transport stations and drops generic stops', function () use ($place) {
    $out = NearbyFacilityCleaner::clean(['transport_station' => [
        $place('Bus Stop', 'Naga City', 100),
        $place('Loading and Unloading Zone', 'Naga City', 150),
        $place('Jeepney Terminal', 'Naga City', 200),
        $place('Naga Central Bus Terminal', 'Naga City', 1800),
        $place('MRT-3 Ayala Station', 'Makati', 900),
    ]]);
    expect(array_column($out['transport_station'], 'name'))->toBe(['MRT-3 Ayala Station', 'Naga Central Bus Terminal']);
});

it('returns every category, empty when a property has none stored', function () {
    expect(array_keys(NearbyFacilityCleaner::clean([])))->toBe(NearbyFacilityCleaner::CATEGORIES)
        ->and(NearbyFacilityCleaner::clean([])['transport_station'])->toBe([]);
});
