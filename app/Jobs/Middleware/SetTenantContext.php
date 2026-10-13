<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Contracts\TenantScopedJob;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;

/**
 * Job middleware: loads the job's tenant and runs `handle()` inside
 * TenantContext::run(), so every Eloquent query in the job is scoped.
 *
 * A job whose tenant no longer exists fails (ModelNotFoundException) rather
 * than running unscoped.
 */
final class SetTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(TenantScopedJob $job, Closure $next): mixed
    {
        $tenant = Tenant::query()->findOrFail($job->tenantId());

        return $this->context->run($tenant, static fn (): mixed => $next($job));
    }
}
