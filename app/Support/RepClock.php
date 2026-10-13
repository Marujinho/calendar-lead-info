<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * All rep-local time arithmetic in one place (PRD §13 "Do").
 *
 * Invariants:
 *  - Inputs/outputs that represent an instant are UTC CarbonImmutable values.
 *  - Inputs/outputs that represent a rep-local calendar day are CarbonImmutable
 *    values at 00:00 in the rep's zone (use `localDate*` methods).
 *  - ISO weeks start on Monday.
 *  - "Week to sync" for a local day D is the ISO week containing D + 1 day,
 *    so Sunday's call fetches next week and Monday always has fallback data
 *    (ADR-0002).
 */
final readonly class RepClock
{
    public const HISTORY_DAYS = 30;

    private DateTimeZone $zone;

    public function __construct(public string $timezone)
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException(sprintf('Unknown IANA timezone "%s"', $timezone));
        }

        $this->zone = new DateTimeZone($timezone);
    }

    /** Current instant expressed in the rep's zone (honours Carbon::setTestNow). */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->zone);
    }

    /** Rep-local today at 00:00. */
    public function today(): CarbonImmutable
    {
        return $this->now()->startOfDay();
    }

    /** The rep-local calendar day (00:00 local) an instant falls on. */
    public function localDateOf(CarbonImmutable $instant): CarbonImmutable
    {
        return $instant->setTimezone($this->zone)->startOfDay();
    }

    /** Rep-local day `$localDate` at `HH:MM` local, returned as a UTC instant. */
    public function atLocalTime(CarbonImmutable $localDate, string $time): CarbonImmutable
    {
        if (preg_match('/^(\d{2}):(\d{2})$/', $time, $m) !== 1) {
            throw new InvalidArgumentException(sprintf('Time must be HH:MM, got "%s"', $time));
        }

        return $localDate->setTimezone($this->zone)->startOfDay()
            ->setTime((int) $m[1], (int) $m[2])
            ->utc();
    }

    /**
     * UTC half-open bounds [start, end) of one rep-local day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function utcBoundsOfLocalDay(CarbonImmutable $localDate): array
    {
        $start = $localDate->setTimezone($this->zone)->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    /**
     * UTC half-open bounds of the 30-day history window for a digest on
     * `$localDate`: [localDate - 30 days 00:00, localDate 00:00). Today is
     * excluded (PRD FR-9).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function historyWindowUtc(CarbonImmutable $localDate): array
    {
        $end = $localDate->setTimezone($this->zone)->startOfDay();

        return [$end->subDays(self::HISTORY_DAYS)->utc(), $end->utc()];
    }

    /** Monday 00:00 (rep-local) of the ISO week containing `$localDate`. */
    public function isoWeekStart(CarbonImmutable $localDate): CarbonImmutable
    {
        return $localDate->setTimezone($this->zone)->startOfDay()->startOfWeek(CarbonImmutable::MONDAY);
    }

    /** The ISO week the daily sync fetches on local day `$localDate` (week of tomorrow). */
    public function weekToSyncOn(CarbonImmutable $localDate): CarbonImmutable
    {
        return $this->isoWeekStart($localDate->setTimezone($this->zone)->startOfDay()->addDay());
    }

    /**
     * Monday 00:00 of every ISO week the backfill must fetch so that a digest
     * on `$firstDigestLocalDate` has a complete 30-day history: all weeks
     * intersecting [firstDigest - 30 days, today]. At most 6 (PRD FR-14).
     *
     * @return list<CarbonImmutable>
     */
    public function backfillWeekStarts(CarbonImmutable $firstDigestLocalDate): array
    {
        $from = $this->isoWeekStart($firstDigestLocalDate->setTimezone($this->zone)->startOfDay()->subDays(self::HISTORY_DAYS));
        $to = $this->isoWeekStart($this->today());

        $weeks = [];
        for ($week = $from; $week->lessThanOrEqualTo($to); $week = $week->addWeek()) {
            $weeks[] = $week;
        }

        return $weeks;
    }

    /**
     * True when `$instant`, seen in the rep's zone, is within
     * [HH:00, HH:00 + minutes) of its local day.
     */
    public function isWithinLocalWindow(CarbonImmutable $instant, int $hour, int $minutes): bool
    {
        $local = $instant->setTimezone($this->zone);
        $start = $local->startOfDay()->setTime($hour, 0);

        return $local->greaterThanOrEqualTo($start) && $local->lessThan($start->addMinutes($minutes));
    }

    /** True when `$instant`, seen in the rep's zone, is at or after `HH:MM` of its local day. */
    public function isPastLocalTime(CarbonImmutable $instant, string $time): bool
    {
        $localDay = $this->localDateOf($instant);

        return $instant->utc()->greaterThanOrEqualTo($this->atLocalTime($localDay, $time));
    }
}
