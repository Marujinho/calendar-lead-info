<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A queued job that runs inside exactly one tenant. The SetTenantContext job
 * middleware reads `tenantId()` and sets the TenantContext before `handle()`.
 *
 * Queue payloads carry ids only (PRD A08), never models or credentials.
 */
interface TenantScopedJob
{
    public function tenantId(): int;
}
