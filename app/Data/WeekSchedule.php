<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * Everything a provider returned for one rep and one ISO week.
 *
 * Invariant: `weekStart` is a Monday at 00:00 in the rep's timezone (the
 * provider contract is "give me the week containing this day"). `repEmail`
 * is normalised the same way as AttendeeData::email.
 */
final readonly class WeekSchedule
{
    public string $repEmail;

    /**
     * @param  list<MeetingData>  $meetings
     */
    public function __construct(
        string $repEmail,
        public CarbonImmutable $weekStart,
        public array $meetings,
        public CarbonImmutable $fetchedAt,
    ) {
        $this->repEmail = mb_strtolower(trim($repEmail));
    }

    /**
     * @return list<string>
     */
    public function externalIds(): array
    {
        return array_map(static fn (MeetingData $m): string => $m->externalId, $this->meetings);
    }
}
