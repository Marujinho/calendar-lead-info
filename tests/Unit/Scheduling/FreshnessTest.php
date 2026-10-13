<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduling;

use App\Services\Digest\Freshness;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FreshnessTest extends TestCase
{
    #[Test]
    public function no_data_has_no_timestamp_and_no_notice(): void
    {
        $freshness = Freshness::noData();

        self::assertFalse($freshness->hasData());
        self::assertFalse($freshness->isStale());
        self::assertNull($freshness->refreshedAt);
        self::assertNull($freshness->noticeTimestamp());
        self::assertSame('no_data', $freshness->label());
    }

    #[Test]
    public function stale_passes_its_timestamp_to_the_notice(): void
    {
        $at = CarbonImmutable::parse('2026-10-13 05:02:00', 'UTC');
        $freshness = Freshness::stale($at);

        self::assertTrue($freshness->hasData());
        self::assertTrue($freshness->isStale());
        self::assertTrue($at->equalTo($freshness->noticeTimestamp()));
        self::assertSame('stale', $freshness->label());
    }

    #[Test]
    public function fresh_keeps_its_timestamp_but_has_no_notice(): void
    {
        $at = CarbonImmutable::parse('2026-10-14 04:02:00', 'UTC');
        $freshness = Freshness::fresh($at);

        self::assertTrue($freshness->hasData());
        self::assertFalse($freshness->isStale());
        self::assertTrue($at->equalTo($freshness->refreshedAt));
        self::assertNull($freshness->noticeTimestamp());
        self::assertSame('fresh', $freshness->label());
    }
}
