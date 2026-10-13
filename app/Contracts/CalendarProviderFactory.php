<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Tenant;

/**
 * Builds a CalendarProvider for one tenant, with that tenant's credentials only
 * (PRD A07). The implementation class is chosen by `config('calendar.provider')`.
 *
 * Jobs resolve this factory, never a CalendarProvider directly.
 */
interface CalendarProviderFactory
{
    public function forTenant(Tenant $tenant): CalendarProvider;
}
