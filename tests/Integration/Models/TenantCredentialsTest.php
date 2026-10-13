<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

final class TenantCredentialsTest extends IntegrationTestCase
{
    #[Test]
    public function provider_credentials_are_encrypted_at_rest(): void
    {
        $tenant = Tenant::factory()->withCredentials(['client_secret' => 'plain-secret-value'])->create();

        $raw = (string) DB::table('tenants')->where('id', $tenant->id)->value('provider_credentials');

        self::assertNotSame('', $raw);
        self::assertStringNotContainsString('plain-secret-value', $raw);
        self::assertSame('plain-secret-value', $tenant->fresh()?->credentials()->get('client_secret'));
    }

    #[Test]
    public function credentials_are_hidden_from_serialisation(): void
    {
        $tenant = Tenant::factory()->withCredentials(['client_secret' => 's'])->create();

        self::assertArrayNotHasKey('provider_credentials', $tenant->toArray());
        self::assertStringNotContainsString('client_secret', $tenant->toJson());
    }

    #[Test]
    public function a_tenant_without_credentials_yields_an_empty_bag(): void
    {
        $tenant = Tenant::factory()->create();

        self::assertTrue($tenant->credentials()->isEmpty());
    }

    #[Test]
    public function internal_domains_are_normalised(): void
    {
        $tenant = Tenant::factory()->create(['internal_domains' => [' Acme.TEST ', 'ourco.com']]);

        self::assertSame(['acme.test', 'ourco.com'], $tenant->internalDomains());
    }
}
