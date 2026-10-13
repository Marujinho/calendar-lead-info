<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Contracts\CalendarProvider;
use App\Data\Enums\CallOutcome;
use App\Data\Enums\CallPurpose;
use App\Data\WeekSchedule;
use App\Exceptions\ProviderCallCapReachedException;
use App\Exceptions\ProviderTimeoutException;
use App\Models\ProviderCall;
use App\Models\Rep;
use App\Models\Tenant;
use App\Support\JobLog;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use LogicException;
use Throwable;

/**
 * Decorator the factory wraps around every CalendarProvider (ADR-0001, PRD P2).
 * Owns call logging to `provider_calls`, the per-tenant daily cap (A06) and
 * outcome classification, so implementations only fetch and map.
 *
 * Invariants:
 *  - Every fetchWeek writes exactly one `provider_calls` row for this tenant,
 *    carrying the purpose given at construction, whatever the outcome.
 *  - When the cap is reached the inner provider is not called: the row has
 *    outcome `rejected_cap`, `calendar.cap_reached` is logged at error level
 *    and ProviderCallCapReachedException is thrown.
 *  - Exceptions from the inner provider are rethrown unchanged.
 *  - It does not enforce a timeout (PHP cannot interrupt a synchronous call);
 *    the implementation must, using the timeout it was constructed with.
 *  - It only runs with its own tenant in TenantContext.
 */
final class MeteredCalendarProvider implements CalendarProvider
{
    /** Extra calls allowed per rep created today (backfill, PRD FR-14). */
    public const BACKFILL_CALLS_PER_NEW_REP = 6;

    public function __construct(
        private readonly CalendarProvider $inner,
        private readonly Tenant $tenant,
        private readonly CallPurpose $purpose,
        private readonly int $capMultiplier,
        private readonly JobLog $log,
        private readonly TenantContext $context,
    ) {}

    public function inner(): CalendarProvider
    {
        return $this->inner;
    }

    public function purpose(): CallPurpose
    {
        return $this->purpose;
    }

    public function fetchWeek(string $repEmail, CarbonImmutable $anyDayInWeek): WeekSchedule
    {
        $tenantId = (int) $this->tenant->getKey();
        if ($this->context->currentId() !== $tenantId) {
            throw new LogicException(sprintf('MeteredCalendarProvider for tenant %d used while tenant %d is in context', $tenantId, $this->context->currentId()));
        }

        $calledAt = CarbonImmutable::now();
        $weekStart = $anyDayInWeek->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $repId = Rep::query()->where('email', mb_strtolower(trim($repEmail)))->value('id');
        $repId = $repId === null ? null : (int) $repId;

        [$calls, $cap] = [$this->callsToday($calledAt), $this->cap($calledAt)];
        if ($calls >= $cap) {
            $this->record($repId, $weekStart, CallOutcome::RejectedCap, 0, $calledAt);
            $this->log->error('calendar.cap_reached', ['tenant_id' => $tenantId, 'calls' => $calls, 'cap' => $cap]);

            throw new ProviderCallCapReachedException($tenantId, $cap);
        }

        $started = hrtime(true);
        try {
            $schedule = $this->inner->fetchWeek($repEmail, $anyDayInWeek);
        } catch (Throwable $e) {
            $outcome = $e instanceof ProviderTimeoutException ? CallOutcome::Timeout : CallOutcome::Failure;
            $duration = $this->elapsedMs($started);
            $this->record($repId, $weekStart, $outcome, $duration, $calledAt);
            $this->log->info('calendar.provider.call', [
                'tenant_id' => $tenantId, 'rep_id' => $repId, 'week_start' => $weekStart,
                'outcome' => $outcome->value, 'duration_ms' => $duration, 'exception' => $e::class,
            ]);

            throw $e;
        }

        $duration = $this->elapsedMs($started);
        $this->record($repId, $weekStart, CallOutcome::Success, $duration, $calledAt);
        $this->log->info('calendar.provider.call', [
            'tenant_id' => $tenantId, 'rep_id' => $repId, 'week_start' => $weekStart,
            'outcome' => CallOutcome::Success->value, 'duration_ms' => $duration, 'meeting_count' => count($schedule->meetings),
        ]);

        return $schedule;
    }

    /** Calls that reached the provider today (UTC) for this tenant. */
    private function callsToday(CarbonImmutable $now): int
    {
        [$from, $to] = $this->utcDay($now);

        return ProviderCall::query()
            ->where('tenant_id', $this->tenant->getKey())
            ->where('outcome', '!=', CallOutcome::RejectedCap->value)
            ->where('called_at', '>=', $from)
            ->where('called_at', '<', $to)
            ->count();
    }

    /** cap_multiplier x active reps + 6 x reps created today (UTC), PRD A06. */
    private function cap(CarbonImmutable $now): int
    {
        [$from, $to] = $this->utcDay($now);

        $active = Rep::query()->where('tenant_id', $this->tenant->getKey())->active()->count();
        $createdToday = Rep::query()
            ->where('tenant_id', $this->tenant->getKey())
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->count();

        return $this->capMultiplier * $active + self::BACKFILL_CALLS_PER_NEW_REP * $createdToday;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function utcDay(CarbonImmutable $now): array
    {
        $from = $now->utc()->startOfDay();

        return [$from, $from->addDay()];
    }

    private function record(?int $repId, string $weekStart, CallOutcome $outcome, int $durationMs, CarbonImmutable $calledAt): void
    {
        ProviderCall::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'rep_id' => $repId,
            'week_start' => $weekStart,
            'purpose' => $this->purpose,
            'outcome' => $outcome,
            'duration_ms' => $durationMs,
            'called_at' => $calledAt,
        ]);
    }

    private function elapsedMs(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
