<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\CalendarProviderFactory;
use App\Contracts\TenantScopedJob;
use App\Exceptions\ProviderCallCapReachedException;
use App\Jobs\Middleware\SetTenantContext;
use App\Models\Rep;
use App\Models\Tenant;
use App\Services\Sync\LeadClassifier;
use App\Services\Sync\MeetingUpserter;
use App\Services\Sync\WeekSyncRecorder;
use App\Support\JobLog;
use App\Support\RepClock;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

/**
 * The daily calendar sync for one rep and one ISO week (PRD FR-4, FR-5, NFR-4).
 *
 * Invariants:
 *  - At most `$tries` (3) attempts, spaced +15 min and +60 min, and no attempt
 *    starts after the rep's local `digest.sync_cutoff` (07:45). Laravel ignores
 *    `$tries` once `retryUntil()` is set, so `handle()` fails the job itself on
 *    the last attempt instead of rethrowing.
 *  - A cap rejection is never retried: the cap holds for the rest of the UTC day.
 *  - Unique per (rep, week) until the cutoff, so the every-minute dispatcher can
 *    re-dispatch freely and the queue still holds one job.
 *  - Touches only this rep's `rep_week_syncs` row and this rep's `meeting_rep`
 *    links, so one rep's failure never affects another rep's data.
 *  - Payload is ids and a date only (PRD A08).
 */
final class SyncRepWeek implements ShouldBeUnique, ShouldQueue, TenantScopedJob
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $repId,
        public readonly string $weekStart,
    ) {
        self::localDate($weekStart, 'UTC');

        $this->onQueue(config('queue.queues.sync'));
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [app(SetTenantContext::class)];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [900, 2700];
    }

    public function uniqueId(): string
    {
        return $this->repId.':'.$this->weekStart;
    }

    /** Seconds until the rep's local cutoff today (at least 1). */
    public function uniqueFor(): int
    {
        return max(1, (int) CarbonImmutable::now()->diffInSeconds($this->retryUntil(), false));
    }

    /** The rep's local sync cutoff today, or now when that instant has passed. */
    public function retryUntil(): DateTimeInterface
    {
        $now = CarbonImmutable::now();
        $clock = $this->repClock();

        if ($clock === null) {
            return $now;
        }

        $cutoff = $clock->atLocalTime($clock->localDateOf($now), (string) config('digest.sync_cutoff'));

        return $cutoff->greaterThan($now) ? $cutoff : $now;
    }

    public function handle(
        CalendarProviderFactory $factory,
        MeetingUpserter $upserter,
        WeekSyncRecorder $recorder,
        TenantContext $context,
        JobLog $log,
    ): void {
        $rep = Rep::query()->find($this->repId);

        if ($rep === null || ! $rep->active) {
            $log->info('sync.skipped', $this->ids() + ['reason' => $rep === null ? 'rep_missing' : 'rep_inactive']);

            return;
        }

        $tenant = $context->current();
        $startedAt = hrtime(true);
        $sync = $recorder->attempt($this->repId, $this->weekStart);

        try {
            $schedule = $factory->forTenant($tenant)->fetchWeek(
                $rep->email,
                $rep->clock()->isoWeekStart(self::localDate($this->weekStart, $rep->timezone)),
            );
            $result = $upserter->apply($rep, $schedule, LeadClassifier::forTenant($tenant));
        } catch (Throwable $e) {
            $recorder->failed($sync, $e);
            $log->warning('sync.failed', $this->ids() + ['exception' => $e::class, 'attempt' => $this->attempts()]);

            if ($e instanceof ProviderCallCapReachedException || $this->attempts() >= $this->tries) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

        $recorder->succeeded($sync);

        $log->info('sync.completed', $this->ids() + [
            'meeting_count' => $result->meetingsUpserted,
            'lead_count' => $result->leadsUpserted,
            'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
            'attempt' => $this->attempts(),
        ]);
    }

    /** Alert: the rep's week could not be synced today. Runs without a tenant context. */
    public function failed(Throwable $e): void
    {
        app(JobLog::class)->error('sync.exhausted', $this->ids() + ['exception' => $e::class]);
    }

    /**
     * Resolves the rep's zone outside of `handle()` (dispatch time), where a
     * tenant context may not be set, by entering the job's own tenant.
     */
    private function repClock(): ?RepClock
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return null;
        }

        $timezone = app(TenantContext::class)->run(
            $tenant,
            fn (): ?string => Rep::query()->whereKey($this->repId)->value('timezone'),
        );

        return is_string($timezone) ? new RepClock($timezone) : null;
    }

    /** Parses a strict Y-m-d date as 00:00 in `$timezone`. */
    private static function localDate(string $date, string $timezone): CarbonImmutable
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)
            : null;

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('weekStart must be a Y-m-d date');
        }

        return $parsed;
    }

    /**
     * @return array{tenant_id: int, rep_id: int, week_start: string}
     */
    private function ids(): array
    {
        return ['tenant_id' => $this->tenantId, 'rep_id' => $this->repId, 'week_start' => $this->weekStart];
    }
}
