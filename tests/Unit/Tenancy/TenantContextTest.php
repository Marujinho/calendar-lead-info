<?php

declare(strict_types=1);

namespace Tests\Unit\Tenancy;

use App\Exceptions\MissingTenantContextException;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TenantContextTest extends TestCase
{
    #[Test]
    public function current_throws_when_nothing_is_set(): void
    {
        $this->expectException(MissingTenantContextException::class);

        (new TenantContext)->current();
    }

    #[Test]
    public function set_and_clear_toggle_the_context(): void
    {
        $context = new TenantContext;
        $tenant = (new Tenant)->forceFill(['id' => 42]);

        self::assertFalse($context->has());
        $context->set($tenant);
        self::assertTrue($context->has());
        self::assertSame(42, $context->currentId());
        $context->clear();
        self::assertFalse($context->has());
    }

    #[Test]
    public function run_restores_the_previous_tenant_even_on_exception(): void
    {
        $context = new TenantContext;
        $outer = (new Tenant)->forceFill(['id' => 1]);
        $inner = (new Tenant)->forceFill(['id' => 2]);
        $context->set($outer);

        $seen = $context->run($inner, static fn (Tenant $t): int => (int) $t->getKey());
        self::assertSame(2, $seen);
        self::assertSame(1, $context->currentId());

        $this->expectException(\RuntimeException::class);

        try {
            $context->run($inner, static function (): never {
                throw new \RuntimeException('boom');
            });
        } finally {
            self::assertSame($outer, $context->current());
        }
    }

    #[Test]
    public function the_container_hands_out_one_scoped_instance(): void
    {
        $a = $this->app->make(TenantContext::class);
        $b = $this->app->make(TenantContext::class);

        self::assertSame($a, $b);
    }
}
