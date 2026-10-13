<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\CalendarProvider;
use App\Data\WeekSchedule;
use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * Programmable provider for tests. Returns whatever WeekSchedule the test
 * queued (or builds an empty one), counts calls, and can throw on demand.
 */
final class StubCalendarProvider implements CalendarProvider
{
    /** @var list<array{repEmail: string, day: CarbonImmutable}> */
    public array $calls = [];

    /** @var list<WeekSchedule> */
    private array $queued = [];

    private ?Throwable $throws = null;

    /** @var (Closure(string, CarbonImmutable): WeekSchedule)|null */
    private ?Closure $resolver = null;

    public function willReturn(WeekSchedule ...$schedules): self
    {
        $this->queued = [...$this->queued, ...$schedules];

        return $this;
    }

    /**
     * @param  Closure(string, CarbonImmutable): WeekSchedule  $resolver
     */
    public function resolveWith(Closure $resolver): self
    {
        $this->resolver = $resolver;

        return $this;
    }

    public function willThrow(Throwable $exception): self
    {
        $this->throws = $exception;

        return $this;
    }

    public function fetchWeek(string $repEmail, CarbonImmutable $anyDayInWeek): WeekSchedule
    {
        $this->calls[] = ['repEmail' => $repEmail, 'day' => $anyDayInWeek];

        if ($this->throws !== null) {
            throw $this->throws;
        }

        if ($this->queued !== []) {
            return array_shift($this->queued);
        }

        if ($this->resolver !== null) {
            return ($this->resolver)($repEmail, $anyDayInWeek);
        }

        return new WeekSchedule(
            repEmail: $repEmail,
            weekStart: $anyDayInWeek->startOfWeek(CarbonImmutable::MONDAY),
            meetings: [],
            fetchedAt: CarbonImmutable::now(),
        );
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
