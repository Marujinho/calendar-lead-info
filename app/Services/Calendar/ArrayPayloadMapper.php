<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Data\AttendeeData;
use App\Data\Enums\MeetingStatus;
use App\Data\Enums\ResponseStatus;
use App\Data\MeetingData;
use App\Data\WeekSchedule;
use App\Exceptions\InvalidProviderPayloadException;
use App\Support\JobLog;
use Carbon\CarbonImmutable;

/**
 * Validates a raw, JSON-shaped provider week payload into DTOs (PRD A08).
 *
 * Expected shape (Google Calendar `events.list` field names, PRD §15):
 * `['items' => [['id', 'summary', 'start', 'end', 'status', 'attendees' => [['email',
 * 'displayName', 'company', 'linkedin', 'responseStatus'], ...]], ...]]`.
 *
 * Invariants:
 *  - Each meeting is validated on its own. An invalid meeting is rejected and
 *    reported; the rest of the week survives. Only a payload without an
 *    `items` list is rejected as a whole.
 *  - Rejections carry field, reason and the meeting id, never the offending
 *    value, and are logged through JobLog as reason codes only (no PII).
 *  - Timestamps must carry an explicit UTC offset and are converted to UTC.
 */
final class ArrayPayloadMapper
{
    public const NO_TITLE = '(no title)';

    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/';

    public function __construct(private readonly JobLog $log) {}

    /**
     * @param  array<mixed>  $payload
     *
     * @throws InvalidProviderPayloadException when the payload has no `items` list
     */
    public function map(array $payload, string $repEmail, CarbonImmutable $weekStart): MappedWeek
    {
        $items = $payload['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            throw new InvalidProviderPayloadException('items', 'missing');
        }

        $meetings = [];
        $rejected = [];
        foreach ($items as $item) {
            try {
                $meetings[] = $this->meeting($item);
            } catch (InvalidProviderPayloadException $e) {
                $rejected[] = $e;
                $this->log->warning('calendar.payload.meeting_rejected', [
                    'reason' => $e->field.':'.$e->reason,
                ]);
            }
        }

        return new MappedWeek(
            new WeekSchedule($repEmail, $weekStart, $meetings, CarbonImmutable::now()),
            $rejected,
        );
    }

    private function meeting(mixed $item): MeetingData
    {
        if (! is_array($item)) {
            throw new InvalidProviderPayloadException('items', 'not_object');
        }

        $id = $item['id'] ?? null;
        if (! is_string($id) || trim($id) === '') {
            throw new InvalidProviderPayloadException('id', 'missing');
        }

        $summary = $item['summary'] ?? '';
        if (! is_string($summary)) {
            throw new InvalidProviderPayloadException('summary', 'invalid', $id);
        }

        $start = $this->instant($item, 'start', $id);
        $end = $this->instant($item, 'end', $id);
        if (! $end->greaterThan($start)) {
            throw new InvalidProviderPayloadException('end', 'not_after_start', $id);
        }

        $status = $item['status'] ?? MeetingStatus::Confirmed->value;
        $status = is_string($status) ? MeetingStatus::tryFrom($status) : null;
        if ($status === null) {
            throw new InvalidProviderPayloadException('status', 'unknown', $id);
        }

        $attendees = $item['attendees'] ?? [];
        if (! is_array($attendees) || ! array_is_list($attendees)) {
            throw new InvalidProviderPayloadException('attendees', 'invalid', $id);
        }

        return new MeetingData(
            externalId: $id,
            title: trim($summary) === '' ? self::NO_TITLE : $summary,
            startAt: $start,
            endAt: $end,
            status: $status,
            attendees: array_map(fn (mixed $a): AttendeeData => $this->attendee($a, $id), $attendees),
        );
    }

    /**
     * @param  array<mixed>  $item
     */
    private function instant(array $item, string $field, string $id): CarbonImmutable
    {
        $value = $item[$field] ?? null;
        if ($value === null) {
            throw new InvalidProviderPayloadException($field, 'missing', $id);
        }

        if (! is_string($value) || preg_match(self::ISO_8601, $value) !== 1) {
            throw new InvalidProviderPayloadException($field, 'invalid', $id);
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            throw new InvalidProviderPayloadException($field, 'invalid', $id);
        }
    }

    private function attendee(mixed $raw, string $id): AttendeeData
    {
        if (! is_array($raw)) {
            throw new InvalidProviderPayloadException('attendees', 'invalid', $id);
        }

        $email = $raw['email'] ?? null;
        if (! is_string($email) || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidProviderPayloadException('attendees.email', 'invalid', $id);
        }
        $email = trim($email);

        $name = $raw['displayName'] ?? null;
        if ($name !== null && ! is_string($name)) {
            throw new InvalidProviderPayloadException('attendees.displayName', 'invalid', $id);
        }
        if ($name === null || trim($name) === '') {
            $name = substr($email, 0, (int) strrpos($email, '@'));
        }

        $response = $raw['responseStatus'] ?? ResponseStatus::Accepted->value;
        $response = is_string($response) ? ResponseStatus::tryFrom($response) : null;
        if ($response === null) {
            throw new InvalidProviderPayloadException('attendees.responseStatus', 'unknown', $id);
        }

        return new AttendeeData(
            email: $email,
            fullName: trim($name),
            companyName: $this->optionalString($raw, 'company', $id),
            linkedinUrl: $this->optionalString($raw, 'linkedin', $id),
            responseStatus: $response,
        );
    }

    /**
     * @param  array<mixed>  $raw
     */
    private function optionalString(array $raw, string $field, string $id): ?string
    {
        $value = $raw[$field] ?? null;
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidProviderPayloadException('attendees.'.$field, 'invalid', $id);
        }

        return trim($value) === '' ? null : trim($value);
    }
}
