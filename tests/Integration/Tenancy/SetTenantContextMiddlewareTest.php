<?php

declare(strict_types=1);

namespace Tests\Integration\Tenancy;

use App\Contracts\TenantScopedJob;
use App\Jobs\Middleware\SetTenantContext;
use App\Models\Rep;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

final class SetTenantContextMiddlewareTest extends IntegrationTestCase
{
    #[Test]
    public function it_runs_the_job_inside_the_jobs_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $this->app->make(TenantContext::class)->run($tenant, fn () => Rep::factory()->for($tenant)->count(2)->create());
        $other = Tenant::factory()->create();
        $this->app->make(TenantContext::class)->run($other, fn () => Rep::factory()->for($other)->create());

        $job = $this->jobFor($tenant->id);
        $seen = $this->app->make(SetTenantContext::class)->handle($job, static fn (TenantScopedJob $j): int => Rep::query()->count());

        self::assertSame(2, $seen);
        self::assertFalse($this->app->make(TenantContext::class)->has(), 'context is restored after the job');
    }

    #[Test]
    public function a_missing_tenant_fails_the_job_instead_of_running_unscoped(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->app->make(SetTenantContext::class)->handle($this->jobFor(999_999), static fn (): int => 1);
    }

    private function jobFor(int $tenantId): TenantScopedJob
    {
        return new class($tenantId) implements TenantScopedJob
        {
            public function __construct(private readonly int $tenantId) {}

            public function tenantId(): int
            {
                return $this->tenantId;
            }
        };
    }
}
