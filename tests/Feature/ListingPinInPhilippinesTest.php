<?php

use App\Http\Requests\StoreListingRequest;
use App\Http\Requests\UpdateListingRequest;
use App\Support\PhilippinePin;
use Illuminate\Support\Facades\Validator;

/**
 * Creating or editing a listing refuses a map pin outside the Philippines,
 * with the rules the request classes really use (only the pin's, so the test
 * needs no database).
 */
function pinErrors(string $request, array $data): array
{
    $rules = array_filter(
        (new $request)->rules(),
        fn ($key) => str_starts_with($key, 'geo_coordinates'),
        ARRAY_FILTER_USE_KEY,
    );

    return Validator::make($data, $rules, (new $request)->messages())->errors()->all();
}

const LISTING_REQUESTS = ['create' => [StoreListingRequest::class], 'edit' => [UpdateListingRequest::class]];

it('accepts a pin in the Philippines', function (string $request) {
    foreach ([[10.3157, 123.8854], [14.5995, 120.9842], [7.0731, 125.6128], [20.45, 121.97], [9.74, 118.73]] as [$lat, $lng]) {
        expect(pinErrors($request, ['geo_coordinates' => ['lat' => $lat, 'lng' => $lng]]))->toBe([]);
    }
})->with(LISTING_REQUESTS);

it('accepts no pin at all', function (string $request) {
    expect(pinErrors($request, []))->toBe([])
        ->and(pinErrors($request, ['geo_coordinates' => null]))->toBe([]);
})->with(LISTING_REQUESTS);

it('refuses a pin outside the Philippines', function (string $request, float $lat, float $lng) {
    expect(pinErrors($request, ['geo_coordinates' => ['lat' => $lat, 'lng' => $lng]]))->toContain(PhilippinePin::MESSAGE);
})->with(LISTING_REQUESTS)->with([
    'zero' => [0, 0],
    'swapped (Cebu)' => [123.8854, 10.3157],
    'Tokyo' => [35.68, 139.76],
    'Jakarta' => [-6.2, 106.8],
]);

it('refuses a pin missing its lat or lng', function (string $request) {
    expect(pinErrors($request, ['geo_coordinates' => ['lat' => 10.3]]))->not->toBe([]);
})->with(LISTING_REQUESTS);
