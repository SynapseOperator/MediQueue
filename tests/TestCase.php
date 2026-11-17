<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Verify the actual database before any test migration or fixture DDL.
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('mediqueue_test', DB::selectOne('SELECT DATABASE() AS name')->name);

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }
}
