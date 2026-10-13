<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\WeekSchedule;
use App\Exceptions\InvalidProviderPayloadException;
use App\Exceptions\ProviderUnavailableException;
use Carbon\CarbonImmutable;

/**
 * Strategy seam for the calendar source (PRD FR-1).
 *
 * Implementations only fetch and map. Call logging, the daily cap and outcome
 * classification are added by the MeteredCalendarProvider decorator that the
 * factory wraps around every instance, so a real adapter never re-implements them.
 *
 * Invariant: the returned week is the ISO week (Monday-Sunday, rep timezone)
 * containing `$anyDayInWeek`, with past and future days of that week included.
 */
interface CalendarProvider
{
    /**
     * @throws ProviderUnavailableException when the provider cannot be reached or errors
     * @throws InvalidProviderPayloadException when the whole payload is unusable
     */
    public function fetchWeek(string $repEmail, CarbonImmutable $anyDayInWeek): WeekSchedule;
}
