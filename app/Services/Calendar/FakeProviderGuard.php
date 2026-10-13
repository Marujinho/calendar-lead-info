<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Services\Calendar\Fake\FakeCalendarProvider;
use RuntimeException;

/**
 * Production guard for the fake calendar provider (PRD A02).
 *
 * Invariant: with APP_ENV=production the fake provider (by alias or class
 * name) is refused unless CALENDAR_ALLOW_FAKE=true, so a missing provider
 * setting can never send demo data to real reps.
 */
final class FakeProviderGuard
{
    public static function assertAllowed(string $environment, string $provider, bool $allowFake): void
    {
        $isFake = $provider === ConfigCalendarProviderFactory::FAKE
            || ltrim($provider, '\\') === FakeCalendarProvider::class;

        if ($environment === 'production' && $isFake && ! $allowFake) {
            throw new RuntimeException(
                'The fake calendar provider is not allowed in production. Set CALENDAR_PROVIDER to the real provider, '
                .'or set CALENDAR_ALLOW_FAKE=true to override deliberately (e.g. staging run as production).'
            );
        }
    }
}
