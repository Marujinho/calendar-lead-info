<?php

declare(strict_types=1);

namespace Tests\Integration\Sync\Concerns;

use App\Data\AttendeeData;
use App\Data\Enums\MeetingStatus;
use App\Data\MeetingData;
use App\Data\WeekSchedule;
use Carbon\CarbonImmutable;

/**
 * Hand-built provider payloads for sync tests.
 */
trait BuildsSchedules
{
    /**
     * @param  list<MeetingData>  $meetings
     */
    protected function week(string $repEmail, string $monday, array $meetings, string $timezone = 'UTC'): WeekSchedule
    {
        return new WeekSchedule($repEmail, CarbonImmutable::parse($monday, $timezone), $meetings, CarbonImmutable::now());
    }

    /**
     * @param  list<AttendeeData>  $attendees
     */
    protected function meeting(
        string $externalId,
        string $startUtc,
        array $attendees,
        MeetingStatus $status = MeetingStatus::Confirmed,
        string $title = 'Intro call',
    ): MeetingData {
        $start = CarbonImmutable::parse($startUtc, 'UTC');

        return new MeetingData($externalId, $title, $start, $start->addMinutes(30), $status, $attendees);
    }
}
