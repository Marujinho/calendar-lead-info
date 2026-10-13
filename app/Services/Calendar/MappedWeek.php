<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Data\WeekSchedule;
use App\Exceptions\InvalidProviderPayloadException;

/**
 * Result of mapping one raw provider week: the valid meetings as a
 * WeekSchedule plus one exception per meeting that failed validation.
 *
 * Invariant: a rejected meeting never appears in `schedule`, and a rejection
 * never removes any other meeting (PRD A08, NFR-4).
 */
final readonly class MappedWeek
{
    /**
     * @param  list<InvalidProviderPayloadException>  $rejected
     */
    public function __construct(
        public WeekSchedule $schedule,
        public array $rejected,
    ) {}

    public function rejectedCount(): int
    {
        return count($this->rejected);
    }
}
