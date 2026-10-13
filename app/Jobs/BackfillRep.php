<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\CalendarProviderFactory;
use App\Contracts\TenantScopedJob;
use App\Data\Enums\CallPurpose;
use App\Exceptions\ProviderCallCapReachedException;
use App\Jobs\Middleware\SetTenantContext;
use App\Models\Rep;
use App\Models\RepWeekSync;
use App\Services\Sync\LeadClassifier;
use App\Services\Sync\MeetingUpserter;
use App\Services\Sync\WeekSyncRecorder;
use App\Support\JobLog;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One-time history backfill for a newly created rep (PRD FR-14).
 *
 * Fetches every ISO week intersecting [first digest − 30 days, today] so the
 * 30-day counts are right from the first digest, then sets `backfilled_at`.
 *
 * Invariants:
 *  - At most 6 provider calls (RepClock::backfillWeekStarts), all tagged
 *    `CallPurpose::Backfill` so they never count towards steady-state cost.
 *  - A week whose `rep_week_syncs.last_success_at` is set is never fetched
 *    again, so a retry resumes where the last attempt failed, and a re-run
 *    after completion makes 0 calls.
 *  - A cap rejection is not retried (the cap holds for the rest of the UTC day).
 */
final class BackfillRep implements ShouldQueue, TenantScopedJob
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $repId,
    ) {
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
     * Same spacing as the daily sync: a provider outage is not hammered.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [900, 2700];
    }

    public function handle(
        CalendarProviderFactory $factory,
        MeetingUpserter $upserter,
        WeekSyncRecorder $recorder,
        TenantContext $context,
        JobLog $log,
    ): void {
        $rep = Rep::query()->find($this->repId);

        if ($rep === null || ! $rep->active || $rep->backfilled_at !== null) {
            $reason = match (true) {
                $rep === null => 'rep_missing',
                ! $rep->active => 'rep_inactive',
                default => 'already_backfilled',
            };
            $log->info('backfill.skipped', $this->ids() + ['reason' => $reason]);

            return;
        }

        $startedAt = hrtime(true);
        $clock = $rep->clock();
        $pending = $this->pendingWeeks($rep->id, $clock->backfillWeekStarts($clock->today()->addDay()));

        $tenant = $context->current();
        $provider = $factory->forTenant($tenant, CallPurpose::Backfill);
        $classifier = LeadClassifier::forTenant($tenant);
        $meetings = 0;
        $leads = 0;

        foreach ($pending as $weekStart) {
            $sync = $recorder->attempt($rep->id, $weekStart->toDateString());

            try {
                $result = $upserter->apply($rep, $provider->fetchWeek($rep->email, $weekStart), $classifier);
            } catch (Throwable $e) {
                $recorder->failed($sync, $e);
                $log->warning('backfill.failed', $this->ids() + [
                    'week_start' => $weekStart->toDateString(),
                    'exception' => $e::class,
                    'attempt' => $this->attempts(),
                ]);

                if ($e instanceof ProviderCallCapReachedException) {
                    $this->fail($e);

                    return;
                }

                throw $e;
            }

            $recorder->succeeded($sync);
            $meetings += $result->meetingsUpserted;
            $leads += $result->leadsUpserted;
        }

        $rep->update(['backfilled_at' => CarbonImmutable::now()]);

        $log->info('backfill.completed', $this->ids() + [
            'calls' => count($pending),
            'meeting_count' => $meetings,
            'lead_count' => $leads,
            'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
            'attempt' => $this->attempts(),
        ]);
    }

    /** Alert: the rep's history could not be backfilled. Runs without a tenant context. */
    public function failed(Throwable $e): void
    {
        app(JobLog::class)->error('backfill.exhausted', $this->ids() + ['exception' => $e::class]);
    }

    /**
     * Weeks not yet fetched successfully, oldest first (one query).
     *
     * @param  list<CarbonImmutable>  $weeks
     * @return list<CarbonImmutable>
     */
    private function pendingWeeks(int $repId, array $weeks): array
    {
        $dates = array_map(static fn (CarbonImmutable $w): string => $w->toDateString(), $weeks);

        $done = RepWeekSync::query()
            ->where('rep_id', $repId)
            ->whereIn('week_start', $dates)
            ->whereNotNull('last_success_at')
            ->pluck('week_start')
            ->map(static fn (CarbonImmutable $w): string => $w->toDateString())
            ->all();

        return array_values(array_filter($weeks, static fn (CarbonImmutable $w): bool => ! in_array($w->toDateString(), $done, true)));
    }

    /**
     * @return array{tenant_id: int, rep_id: int}
     */
    private function ids(): array
    {
        return ['tenant_id' => $this->tenantId, 'rep_id' => $this->repId];
    }
}
