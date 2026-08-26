<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but a test database.
     *
     * `RefreshDatabase` runs `migrate:fresh`, which drops every table. The
     * database it targets comes from config, and a cached config silently wins
     * over phpunit.xml's <env> entries — `env()` is never consulted once
     * `php artisan config:cache` has run. So a developer who caches config in
     * their checkout and then runs the suite destroys their working data, with
     * no warning and nothing in the output to explain it.
     *
     * This turns that into a loud failure on the first test instead.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();

        if (! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException(
                "Refusing to run tests against \"{$database}\": the name must end with _test. ".
                'This usually means a cached config is overriding phpunit.xml — run '.
                '`php artisan config:clear` and try again.'
            );
        }
    }
}
