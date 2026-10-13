<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Exceptions\MissingTenantContextException;
use App\Models\Tenant;
use Closure;

/**
 * The one tenant the current unit of work belongs to.
 *
 * Registered as a scoped singleton: one instance per request / job / command.
 * Every BelongsToTenant query reads `currentId()`, which throws when nothing is
 * set, so unscoped access to tenant data is impossible rather than discouraged.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function current(): Tenant
    {
        return $this->tenant ?? throw MissingTenantContextException::forModel(Tenant::class);
    }

    public function currentId(): int
    {
        return (int) $this->current()->getKey();
    }

    /**
     * Run `$callback` with `$tenant` as the current tenant, restoring whatever
     * was set before (so nested tenant switches, e.g. the purge loop, are safe).
     *
     * @template T
     *
     * @param  Closure(Tenant): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback($tenant);
        } finally {
            $this->tenant = $previous;
        }
    }
}
