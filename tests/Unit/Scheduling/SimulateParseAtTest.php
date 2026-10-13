<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduling;

use App\Console\Commands\Digest\Simulate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SimulateParseAtTest extends TestCase
{
    #[Test]
    public function it_parses_a_local_wall_time_in_the_given_zone(): void
    {
        $at = Simulate::parseAt('2026-10-14 08:00 Europe/Lisbon');

        self::assertSame('Europe/Lisbon', $at->getTimezone()->getName());
        self::assertSame('2026-10-14 07:00:00', $at->utc()->toDateTimeString());
    }

    #[Test]
    public function the_zone_defaults_to_utc(): void
    {
        $at = Simulate::parseAt('  2026-10-14   08:00 ');

        self::assertSame('2026-10-14 08:00:00', $at->utc()->toDateTimeString());
        self::assertSame('UTC', $at->getTimezone()->getName());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidValues(): array
    {
        return [
            'date only' => ['2026-10-14'],
            'unknown zone' => ['2026-10-14 08:00 Mars/Olympus'],
            'bad time' => ['2026-10-14 25:00 UTC'],
            'bad date' => ['2026-02-30 08:00'],
            'seconds' => ['2026-10-14 08:00:00 UTC'],
            'too many parts' => ['2026-10-14 08:00 Europe/Lisbon extra'],
        ];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function it_rejects_anything_else(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Simulate::parseAt($value);
    }
}
