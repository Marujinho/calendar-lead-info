<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\CalendarProvider;
use App\Contracts\CalendarProviderFactory;
use App\Data\Enums\CallPurpose;
use App\Models\Tenant;

/**
 * Hands out one StubCalendarProvider per tenant id so tests can assert
 * per-tenant call counts and isolation. Bind it in a test with
 * `$this->app->instance(CalendarProviderFactory::class, $factory)`.
 */
final class StubCalendarProviderFactory implements CalendarProviderFactory
{
    /** @var array<int, StubCalendarProvider> */
    private array $providers = [];

    /** @var list<array{tenantId: int, purpose: CallPurpose}> */
    public array $requests = [];

    public function forTenant(Tenant $tenant, CallPurpose $purpose = CallPurpose::Sync): CalendarProvider
    {
        $this->requests[] = ['tenantId' => (int) $tenant->getKey(), 'purpose' => $purpose];

        return $this->stubFor($tenant);
    }

    public function stubFor(Tenant $tenant): StubCalendarProvider
    {
        $id = (int) $tenant->getKey();

        return $this->providers[$id] ??= new StubCalendarProvider;
    }

    public function totalCalls(): int
    {
        return array_sum(array_map(static fn (StubCalendarProvider $p): int => $p->callCount(), $this->providers));
    }
}
