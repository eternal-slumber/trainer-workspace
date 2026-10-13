<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GenerationTransactionTestCase extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql'
            || ! preg_match('/^testing(?:_test_\d+)?$/', DB::connection()->getDatabaseName())) {
            $this->markTestSkipped('Real transaction tests require PostgreSQL testing.');
        }
    }
}
