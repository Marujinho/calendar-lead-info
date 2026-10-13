<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Owned by the calendar-provider module: binds CalendarProviderFactory to the
 * config-selected implementation and enforces the production guard (A02).
 * Intentionally empty until that module lands.
 */
final class CalendarServiceProvider extends ServiceProvider
{
    public function register(): void {}
}
