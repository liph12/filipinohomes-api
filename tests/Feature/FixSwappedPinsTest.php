<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach (['listings', 'properties', 'projects'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('projects', function ($t) {
        $t->id();
        $t->string('name');
        $t->string('latitude')->nullable();
        $t->string('longitude')->nullable();
    });
    Schema::create('properties', function ($t) {
        $t->id();
        $t->unsignedBigInteger('project_id')->nullable();
        $t->json('geo_coordinates')->nullable();
        $t->timestamp('updated_at')->nullable();
    });
    Schema::create('listings', function ($t) {
        $t->id();
        $t->unsignedBigInteger('property_id');
        $t->timestamp('deleted_at')->nullable();
    });

    DB::table('projects')->insert([
        ['id' => 1, 'name' => 'Tambuli', 'latitude' => '124.007182', 'longitude' => '10.290229'], // swapped
        ['id' => 2, 'name' => 'Right', 'latitude' => '14.5995', 'longitude' => '120.9842'],
        ['id' => 3, 'name' => 'Zero', 'latitude' => '0', 'longitude' => '0'],
        ['id' => 4, 'name' => 'Blank', 'latitude' => '', 'longitude' => ''],
    ]);
    DB::table('properties')->insert([
        ['id' => 10, 'project_id' => 1, 'geo_coordinates' => '{"lat": 124.007182, "lng": 10.290229}', 'updated_at' => '2026-01-01 00:00:00'],
        ['id' => 11, 'project_id' => null, 'geo_coordinates' => '{"lat": 10.3157, "lng": 123.8854}', 'updated_at' => '2026-01-01 00:00:00'],
        ['id' => 12, 'project_id' => null, 'geo_coordinates' => '{"lat": 0, "lng": 0}', 'updated_at' => '2026-01-01 00:00:00'],
        ['id' => 13, 'project_id' => null, 'geo_coordinates' => '{"lat": 35.68, "lng": 139.76}', 'updated_at' => '2026-01-01 00:00:00'],
    ]);
    DB::table('listings')->insert([['property_id' => 10], ['property_id' => 11]]);
});

it('only lists the swapped pins without --apply', function () {
    $this->artisan('pins:fix-swapped')
        ->expectsOutputToContain('Projects with swapped pins: 1')
        ->expectsOutputToContain('Listing pins (properties) swapped: 1, used by 1 listings')
        ->expectsOutputToContain('Preview only')
        ->assertSuccessful();

    expect(DB::table('projects')->find(1)->latitude)->toBe('124.007182')
        ->and(json_decode(DB::table('properties')->find(10)->geo_coordinates, true)['lat'])->toBe(124.007182);
});

it('swaps them back with --apply and leaves every other pin alone', function () {
    $this->artisan('pins:fix-swapped --apply')->assertSuccessful();

    $project = DB::table('projects')->find(1);
    expect([$project->latitude, $project->longitude])->toBe(['10.290229', '124.007182']);
    expect(json_decode(DB::table('properties')->find(10)->geo_coordinates, true))->toBe(['lat' => 10.290229, 'lng' => 124.007182]);

    // Untouched: already right, 0,0, blank, abroad — and no updated_at bump.
    expect(DB::table('projects')->find(2)->latitude)->toBe('14.5995')
        ->and(DB::table('projects')->find(3)->latitude)->toBe('0')
        ->and(json_decode(DB::table('properties')->find(11)->geo_coordinates, true)['lat'])->toBe(10.3157)
        ->and(json_decode(DB::table('properties')->find(12)->geo_coordinates, true)['lat'])->toBe(0)
        ->and(json_decode(DB::table('properties')->find(13)->geo_coordinates, true)['lat'])->toBe(35.68)
        ->and(DB::table('properties')->find(10)->updated_at)->toBe('2026-01-01 00:00:00');

    // Running it again finds nothing left to fix.
    $this->artisan('pins:fix-swapped')->expectsOutputToContain('Projects with swapped pins: 0')->assertSuccessful();
});
