<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * ⚠️ Refuse to run against anything but the throwaway sqlite database.
     *
     * phpunit.xml sets DB_CONNECTION=sqlite / DB_DATABASE=:memory:, but a
     * CACHED CONFIG silently outranks it — `bootstrap/cache/config.php` is
     * baked from .env, so once someone runs `php artisan config:cache` on a dev
     * machine the whole suite points at the real MySQL database instead.
     *
     * That happened on 2026-09-16 and was caught only because every test builds
     * its own tables and died on "table already exists", and because the
     * RefreshDatabase line in Pest.php is commented out. Uncomment that line
     * with a config cache in place and running the suite TRUNCATES the
     * development database.
     *
     * So the guard is here rather than in a comment: one clear failure beats a
     * silent one, and `php artisan config:clear` is the fix it names.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || ! in_array($database, [':memory:', null, ''], true)) {
            throw new RuntimeException(
                "Tests are pointed at the {$connection} database '{$database}', not the in-memory ".
                "sqlite one phpunit.xml asks for. A cached config overrides phpunit.xml — run ".
                '`php artisan config:clear` and try again. Do NOT run the suite against a real '.
                'database: tests build their own tables, and RefreshDatabase would empty it.'
            );
        }
    }
}
