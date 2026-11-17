<?php

namespace Tests\Feature;

use App\Support\FhOneKeys;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** FH One's API keys: how they are made, read and matched, and the two commands that handle them. */
class FhOneKeysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 14:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_generated_key_is_long_random_and_easy_to_spot(): void
    {
        $a = FhOneKeys::generate();
        $b = FhOneKeys::generate();

        $this->assertStringStartsWith('fhone_', $a);
        $this->assertSame(54, strlen($a));
        $this->assertMatchesRegularExpression('/^fhone_[A-Za-z0-9]{48}$/', $a);
        $this->assertNotSame($a, $b);
    }

    public function test_an_entry_stores_the_hash_never_the_key(): void
    {
        $key = FhOneKeys::generate();
        $entry = FhOneKeys::entry('fh-one', $key, '2027-01-31');

        $this->assertSame('fh-one:'.hash('sha256', $key).':2027-01-31', $entry);
        $this->assertStringNotContainsString($key, $entry);
    }

    public function test_a_key_matches_only_its_own_entry(): void
    {
        $key = FhOneKeys::generate();
        config(['fhone.keys' => FhOneKeys::entry('fh-one', $key, '2026-12-31')]);

        $match = FhOneKeys::match($key);
        $this->assertSame('fh-one', $match['name']);
        $this->assertFalse($match['expired']);

        foreach (['', substr($key, 0, -1), $key.' ', strtoupper($key), hash('sha256', $key)] as $other) {
            $this->assertNull(FhOneKeys::match($other), "'{$other}' must not match");
        }
    }

    public function test_expiry_counts_manila_days_and_includes_the_last_one(): void
    {
        $key = FhOneKeys::generate();

        config(['fhone.keys' => FhOneKeys::entry('a', $key, '2026-11-10')]);
        $this->assertFalse(FhOneKeys::match($key)['expired']);
        $this->assertSame(0, FhOneKeys::daysLeft('2026-11-10'));

        config(['fhone.keys' => FhOneKeys::entry('a', $key, '2026-11-09')]);
        $this->assertTrue(FhOneKeys::match($key)['expired']);
        $this->assertSame(-1, FhOneKeys::daysLeft('2026-11-09'));

        // 11 PM UTC on Nov 10 is already Nov 11 in Manila.
        Carbon::setTestNow(Carbon::parse('2026-11-10 23:00', 'UTC'));
        config(['fhone.keys' => FhOneKeys::entry('a', $key, '2026-11-10')]);
        $this->assertTrue(FhOneKeys::match($key)['expired']);
    }

    public function test_only_well_formed_entries_count(): void
    {
        $hash = hash('sha256', 'x');

        config(['fhone.keys' => implode(',', [
            "good:{$hash}:2026-12-31", " spaced-ok.1_2:{$hash}:2026-12-31 ",
            "Bad:{$hash}:2026-12-31", "-lead:{$hash}:2026-12-31", "a b:{$hash}:2026-12-31", ':'.$hash.':2026-12-31',
            'short:abc:2026-12-31', "x:{$hash}:2026-13-01", "x:{$hash}:tomorrow", "x:{$hash}", "x:{$hash}:2026-12-31:extra", '',
        ])]);

        $this->assertSame(['good', 'spaced-ok.1_2'], array_column(FhOneKeys::configured(), 'name'));
    }

    public function test_make_key_prints_a_key_that_works_with_the_entry_it_prints(): void
    {
        $this->assertSame(0, Artisan::call('fhone:make-key', ['name' => 'fh-one-2026-11', '--days' => 30]));
        $output = Artisan::output();

        preg_match('/fhone_[A-Za-z0-9]{48}/', $output, $key);
        preg_match('/fh-one-2026-11:[a-f0-9]{64}:\d{4}-\d{2}-\d{2}/', $output, $entry);

        $this->assertNotEmpty($key);
        $this->assertNotEmpty($entry);
        $this->assertStringContainsString('2026-12-10', $output); // 30 days from Nov 10, Manila

        // Paste the entry into the config, and the key printed beside it opens the door.
        config(['fhone.keys' => $entry[0]]);
        $this->assertSame('fh-one-2026-11', FhOneKeys::match($key[0])['name']);

        // The hash of the key is in the entry; the key itself appears once, on its own line.
        $this->assertSame(1, substr_count($output, $key[0]));
    }

    public function test_make_key_refuses_a_bad_name_or_a_silly_lifetime(): void
    {
        foreach ([
            ['name' => 'Has Spaces'], ['name' => 'UPPER'], ['name' => str_repeat('a', 41)],
            ['name' => 'ok', '--days' => 0], ['name' => 'ok', '--days' => 400], ['name' => 'ok', '--days' => -5],
        ] as $arguments) {
            $this->assertSame(1, Artisan::call('fhone:make-key', $arguments), json_encode($arguments));
            $this->assertStringNotContainsString('fhone_', Artisan::output());
        }
    }

    public function test_the_key_list_shows_names_and_dates_but_never_a_key_or_a_hash(): void
    {
        $hash = hash('sha256', 'a');
        config(['fhone.keys' => "soon:{$hash}:2026-11-20,old:".hash('sha256', 'b').':2026-11-01,fine:'.hash('sha256', 'c').':2027-06-30']);

        $this->assertSame(0, Artisan::call('fhone:keys'));
        $output = Artisan::output();

        $this->assertStringContainsString('soon', $output);
        $this->assertStringContainsString('expires soon', $output);
        $this->assertStringContainsString('EXPIRED', $output);
        $this->assertStringContainsString('ok', $output);
        $this->assertStringNotContainsString($hash, $output);
        $this->assertStringNotContainsString('fhone_', $output);

        config(['fhone.keys' => '']);
        Artisan::call('fhone:keys');
        $this->assertStringContainsString('No usable key', Artisan::output());
    }
}
