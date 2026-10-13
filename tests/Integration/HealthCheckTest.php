<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_route_responds_successfully(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_integration_suite_runs_against_mysql(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(1, (int) DB::scalar('select 1'));
    }
}
