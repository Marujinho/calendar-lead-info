<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Contracts\CalendarProvider;
use App\Contracts\CalendarProviderFactory;
use App\Data\Enums\CallPurpose;
use App\Models\Tenant;
use App\Services\Calendar\Fake\FakeCalendarProvider;
use App\Services\Calendar\Fake\FakeMode;
use App\Support\JobLog;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

/**
 * Builds the configured CalendarProvider for one tenant (PRD FR-1, A07).
 *
 * `config('calendar.provider')` is `fake` or the fully-qualified name of a
 * class implementing CalendarProvider. The class is resolved through the
 * container with these named constructor arguments (any it does not declare
 * are ignored): `credentials` (ProviderCredentials), `timeoutSeconds` (int,
 * `config('calendar.timeout')`) and `seed` (string, the tenant id).
 *
 * Invariants:
 *  - A provider is built with the credentials of the tenant passed in, and
 *    no other; instances are never cached or shared across tenants.
 *  - Every returned provider is a MeteredCalendarProvider carrying `$purpose`.
 *  - An unknown provider value fails at construction, not on the first call.
 *  - In production the fake is refused at construction (FakeProviderGuard,
 *    A02). Every path to calendar data goes through this factory, so the fake
 *    can never serve production data. The check lives here rather than in a
 *    service provider's boot() because tooling (composer's package:discover,
 *    Larastan) boots the app with no .env, where APP_ENV defaults to production.
 */
final class ConfigCalendarProviderFactory implements CalendarProviderFactory
{
    public const FAKE = 'fake';

    /** @var class-string<CalendarProvider> */
    private string $providerClass;

    public function __construct(
        private readonly Application $container,
        private readonly Repository $config,
    ) {
        $provider = (string) $config->get('calendar.provider');
        FakeProviderGuard::assertAllowed((string) $container->environment(), $provider, (bool) $config->get('calendar.allow_fake'));
        $this->providerClass = self::resolveClass($provider);
    }

    /**
     * @return class-string<CalendarProvider>
     */
    public static function resolveClass(string $provider): string
    {
        if ($provider === self::FAKE) {
            return FakeCalendarProvider::class;
        }

        if (class_exists($provider) && is_subclass_of($provider, CalendarProvider::class)) {
            return $provider;
        }

        throw new InvalidArgumentException(sprintf(
            'calendar.provider must be "fake" or a class implementing %s',
            CalendarProvider::class,
        ));
    }

    public function forTenant(Tenant $tenant, CallPurpose $purpose = CallPurpose::Sync): CalendarProvider
    {
        $arguments = [
            'credentials' => $tenant->credentials(),
            'timeoutSeconds' => (int) $this->config->get('calendar.timeout'),
            'seed' => (string) $tenant->getKey(),
        ];

        if ($this->providerClass === FakeCalendarProvider::class) {
            $arguments['mode'] = FakeMode::fromConfig((string) $this->config->get('calendar.fake.mode'));
            $arguments['latencyMs'] = (int) $this->config->get('calendar.fake.latency_ms');
        }

        $inner = $this->container->make($this->providerClass, $arguments);

        return new MeteredCalendarProvider(
            inner: $inner,
            tenant: $tenant,
            purpose: $purpose,
            capMultiplier: (int) $this->config->get('calendar.cap_multiplier'),
            log: $this->container->make(JobLog::class),
            context: $this->container->make(TenantContext::class),
        );
    }
}
