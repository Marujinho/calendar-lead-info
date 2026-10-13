<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\CalendarProviderFactory;
use App\Services\Calendar\ConfigCalendarProviderFactory;
use App\Services\Calendar\Fake\FakeScenario;
use Illuminate\Support\ServiceProvider;

/**
 * Owned by the calendar-provider module: binds CalendarProviderFactory to the
 * config-selected implementation.
 *
 * The production guard (A02) runs when the factory is constructed, not in
 * boot(): see ConfigCalendarProviderFactory for why.
 */
final class CalendarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CalendarProviderFactory::class, ConfigCalendarProviderFactory::class);

        // The scenario file is immutable at runtime: load and validate it once per process.
        $this->app->singleton(FakeScenario::class);
    }
}
