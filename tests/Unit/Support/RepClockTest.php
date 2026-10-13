<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\RepClock;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RepClockTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_rejects_an_unknown_timezone(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RepClock('Mars/Olympus');
    }

    #[Test]
    public function today_is_the_local_day_not_the_utc_day(): void
    {
        // 02:00 UTC on Oct 14 is still Oct 13 at 23:00 in São Paulo (UTC-3).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 02:00:00', 'UTC'));

        $clock = new RepClock('America/Sao_Paulo');

        self::assertSame('2026-10-13', $clock->today()->toDateString());
        self::assertSame('2026-10-14', (new RepClock('Europe/Lisbon'))->today()->toDateString());
    }

    #[Test]
    public function at_local_time_returns_a_utc_instant(): void
    {
        $clock = new RepClock('America/Sao_Paulo');
        $localDay = CarbonImmutable::parse('2026-10-14', 'America/Sao_Paulo');

        $instant = $clock->atLocalTime($localDay, '08:00');

        self::assertSame('UTC', $instant->timezoneName);
        self::assertSame('2026-10-14 11:00:00', $instant->toDateTimeString());
    }

    #[Test]
    public function at_local_time_rejects_malformed_time(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RepClock('UTC'))->atLocalTime(CarbonImmutable::parse('2026-10-14'), '8am');
    }

    #[Test]
    public function history_window_is_thirty_days_ending_at_local_midnight_today(): void
    {
        $clock = new RepClock('Europe/Lisbon'); // UTC+1 on 2026-10-14 (WEST)
        $localDay = CarbonImmutable::parse('2026-10-14', 'Europe/Lisbon');

        [$start, $end] = $clock->historyWindowUtc($localDay);

        self::assertSame('2026-09-13 23:00:00', $start->toDateTimeString());
        self::assertSame('2026-10-13 23:00:00', $end->toDateTimeString());
    }

    #[Test]
    public function utc_bounds_of_a_local_day_span_exactly_that_day(): void
    {
        $clock = new RepClock('America/Sao_Paulo');
        $localDay = CarbonImmutable::parse('2026-10-14', 'America/Sao_Paulo');

        [$start, $end] = $clock->utcBoundsOfLocalDay($localDay);

        self::assertSame('2026-10-14 03:00:00', $start->toDateTimeString());
        self::assertSame('2026-10-15 03:00:00', $end->toDateTimeString());
    }

    #[Test]
    #[DataProvider('isoWeekCases')]
    public function iso_week_starts_on_monday(string $localDate, string $expectedMonday): void
    {
        $clock = new RepClock('UTC');

        self::assertSame($expectedMonday, $clock->isoWeekStart(CarbonImmutable::parse($localDate))->toDateString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function isoWeekCases(): iterable
    {
        yield 'monday' => ['2026-10-12', '2026-10-12'];
        yield 'wednesday' => ['2026-10-14', '2026-10-12'];
        yield 'sunday' => ['2026-10-18', '2026-10-12'];
    }

    #[Test]
    #[DataProvider('weekToSyncCases')]
    public function daily_sync_targets_the_week_containing_tomorrow(string $localDate, string $expectedWeekStart): void
    {
        $clock = new RepClock('UTC');

        self::assertSame($expectedWeekStart, $clock->weekToSyncOn(CarbonImmutable::parse($localDate))->toDateString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function weekToSyncCases(): iterable
    {
        yield 'monday fetches this week' => ['2026-10-12', '2026-10-12'];
        yield 'saturday fetches this week' => ['2026-10-17', '2026-10-12'];
        yield 'sunday fetches next week' => ['2026-10-18', '2026-10-19'];
    }

    #[Test]
    public function backfill_covers_at_most_six_weeks_on_a_monday(): void
    {
        // Monday 2026-10-12: window starts 2026-09-12 (Saturday, week of Sep 7).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'));
        $clock = new RepClock('UTC');

        $weeks = array_map(static fn (CarbonImmutable $w): string => $w->toDateString(), $clock->backfillWeekStarts($clock->today()));

        self::assertSame(['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05', '2026-10-12'], $weeks);
    }

    #[Test]
    public function backfill_needs_fewer_weeks_later_in_the_week(): void
    {
        // Sunday 2026-10-18: window starts 2026-09-18 (Friday, week of Sep 14).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 10:00:00', 'UTC'));
        $clock = new RepClock('UTC');

        self::assertCount(5, $clock->backfillWeekStarts($clock->today()));
    }

    #[Test]
    public function local_window_check_uses_the_rep_zone(): void
    {
        $clock = new RepClock('Europe/Lisbon');

        // 07:00 UTC = 08:00 Lisbon on 2026-10-14 (WEST, UTC+1)
        self::assertTrue($clock->isWithinLocalWindow(CarbonImmutable::parse('2026-10-14 07:00:00', 'UTC'), 8, 15));
        self::assertTrue($clock->isWithinLocalWindow(CarbonImmutable::parse('2026-10-14 07:14:59', 'UTC'), 8, 15));
        self::assertFalse($clock->isWithinLocalWindow(CarbonImmutable::parse('2026-10-14 07:15:00', 'UTC'), 8, 15));
        self::assertFalse($clock->isWithinLocalWindow(CarbonImmutable::parse('2026-10-14 06:59:59', 'UTC'), 8, 15));
    }

    #[Test]
    public function is_past_local_time_handles_the_cutoff(): void
    {
        $clock = new RepClock('America/Sao_Paulo'); // UTC-3

        self::assertFalse($clock->isPastLocalTime(CarbonImmutable::parse('2026-10-14 10:44:59', 'UTC'), '07:45'));
        self::assertTrue($clock->isPastLocalTime(CarbonImmutable::parse('2026-10-14 10:45:00', 'UTC'), '07:45'));
    }

    #[Test]
    public function eight_oclock_exists_on_dst_transition_days(): void
    {
        // Europe/Lisbon leaves DST on 2026-10-25 (clocks go back at 02:00).
        $lisbon = new RepClock('Europe/Lisbon');
        $instant = $lisbon->atLocalTime(CarbonImmutable::parse('2026-10-25', 'Europe/Lisbon'), '08:00');
        self::assertSame('2026-10-25 08:00:00', $instant->setTimezone('Europe/Lisbon')->toDateTimeString());
        self::assertSame('2026-10-25 08:00:00', $instant->toDateTimeString()); // WET = UTC after the switch

        // The day before the switch is still UTC+1.
        $before = $lisbon->atLocalTime(CarbonImmutable::parse('2026-10-24', 'Europe/Lisbon'), '08:00');
        self::assertSame('2026-10-24 07:00:00', $before->toDateTimeString());

        // The history window around a DST day is still 30 local days long.
        [$start, $end] = $lisbon->historyWindowUtc(CarbonImmutable::parse('2026-10-26', 'Europe/Lisbon'));
        self::assertSame(30.0, $start->setTimezone('Europe/Lisbon')->diffInDays($end->setTimezone('Europe/Lisbon')));
    }
}
