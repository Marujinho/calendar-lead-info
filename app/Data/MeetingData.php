<?php

declare(strict_types=1);

namespace App\Data;

use App\Data\Enums\MeetingStatus;
use Carbon\CarbonImmutable;

/**
 * One meeting from a provider week payload, already validated.
 *
 * Invariant: `externalId` is the provider's stable meeting id, identical on
 * every attendee's calendar, so history can be deduplicated across reps.
 * Timestamps are UTC instants.
 */
final readonly class MeetingData
{
    /**
     * @param  list<AttendeeData>  $attendees
     */
    public function __construct(
        public string $externalId,
        public string $title,
        public CarbonImmutable $startAt,
        public CarbonImmutable $endAt,
        public MeetingStatus $status,
        public array $attendees,
    ) {}

    /**
     * @return list<string>
     */
    public function attendeeEmails(): array
    {
        return array_map(static fn (AttendeeData $a): string => $a->email, $this->attendees);
    }
}
