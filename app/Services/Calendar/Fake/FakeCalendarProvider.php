<?php

declare(strict_types=1);

namespace App\Services\Calendar\Fake;

use App\Contracts\CalendarProvider;
use App\Data\WeekSchedule;
use App\Exceptions\ProviderTimeoutException;
use App\Exceptions\ProviderUnavailableException;
use App\Services\Calendar\ArrayPayloadMapper;
use Carbon\CarbonImmutable;

/**
 * The only CalendarProvider of v1 (PRD FR-2): deterministic, realistic weeks
 * for local runs, demos and tests, with failure modes for A10 drills.
 *
 * Invariants:
 *  - Same (seed, rep email, week) => same WeekSchedule (apart from fetchedAt).
 *  - Data goes raw array -> ArrayPayloadMapper -> DTOs, the same validation
 *    path a real adapter uses, so `malformed` mode exercises real rejection.
 *  - Never metered here: the factory wraps it in MeteredCalendarProvider.
 *  - Never selectable in production unless explicitly allowed (FakeProviderGuard).
 */
final class FakeCalendarProvider implements CalendarProvider
{
    public function __construct(
        private readonly string $seed,
        private readonly int $timeoutSeconds,
        private readonly FakeMode $mode,
        private readonly int $latencyMs,
        private readonly ArrayPayloadMapper $mapper,
        private readonly FakeScenario $scenario,
    ) {}

    public function seed(): string
    {
        return $this->seed;
    }

    public function fetchWeek(string $repEmail, CarbonImmutable $anyDayInWeek): WeekSchedule
    {
        if ($this->latencyMs > 0) {
            usleep($this->latencyMs * 1000);
        }

        if ($this->mode === FakeMode::Fail) {
            throw ProviderUnavailableException::because('fake_mode_fail');
        }

        if ($this->mode === FakeMode::Timeout) {
            throw ProviderTimeoutException::after($this->timeoutSeconds);
        }

        $weekStart = $anyDayInWeek->startOfWeek(CarbonImmutable::MONDAY);
        $payload = $this->scenario->weekPayload($this->seed, $repEmail, $weekStart);

        if ($this->mode === FakeMode::Malformed) {
            $payload['items'] = [...$payload['items'], ...$this->malformedItems($weekStart)];
        }

        return $this->mapper->map($payload, $repEmail, $weekStart)->schedule;
    }

    /**
     * One meeting with an invalid attendee email and one without `start`;
     * the mapper must reject both and keep every other meeting.
     *
     * @return list<array<string, mixed>>
     */
    private function malformedItems(CarbonImmutable $weekStart): array
    {
        $day = CarbonImmutable::parse($weekStart->toDateString(), 'UTC')->setTime(12, 0);
        $week = $day->format('Ymd');

        return [
            [
                'id' => 'malformed-bad-email-'.$week,
                'summary' => 'Malformed attendee',
                'start' => $day->toIso8601String(),
                'end' => $day->addMinutes(30)->toIso8601String(),
                'status' => 'confirmed',
                'attendees' => [['email' => 'not-an-email', 'displayName' => 'Broken', 'responseStatus' => 'accepted']],
            ],
            [
                'id' => 'malformed-no-start-'.$week,
                'summary' => 'Malformed start',
                'end' => $day->addMinutes(30)->toIso8601String(),
                'status' => 'confirmed',
                'attendees' => [],
            ],
        ];
    }
}
