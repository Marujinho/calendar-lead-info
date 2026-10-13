<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Base class for tests that hit MySQL. Every tenant-scoped query needs a tenant
 * in context, so most tests start with `$this->actingAsTenant()`.
 */
abstract class IntegrationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** Create (or use) a tenant and make it the current one for the rest of the test. */
    protected function actingAsTenant(?Tenant $tenant = null): Tenant
    {
        $tenant ??= Tenant::factory()->create();

        $this->app->make(TenantContext::class)->set($tenant);

        return $tenant;
    }

    /** Freeze the clock at a wall time in a zone, e.g. freezeAt('2026-10-14 07:59', 'Europe/Lisbon'). */
    protected function freezeAt(string $dateTime, string $timezone = 'UTC'): CarbonImmutable
    {
        $instant = CarbonImmutable::parse($dateTime, $timezone)->utc();

        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);

        return $instant;
    }
}
