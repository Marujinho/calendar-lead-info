<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Enums\CallPurpose;
use App\Models\Tenant;

/**
 * Builds a CalendarProvider for one tenant, with that tenant's credentials only
 * (PRD A07). The implementation class is chosen by `config('calendar.provider')`.
 *
 * Jobs resolve this factory, never a CalendarProvider directly. `$purpose`
 * is recorded on every `provider_calls` row so steady-state cost (G2) can be
 * measured separately from the one-time backfill (FR-14).
 */
interface CalendarProviderFactory
{
    public function forTenant(Tenant $tenant, CallPurpose $purpose = CallPurpose::Sync): CalendarProvider;
}
