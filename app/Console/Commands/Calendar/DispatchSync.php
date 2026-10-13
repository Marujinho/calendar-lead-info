<?php

declare(strict_types=1);

namespace App\Console\Commands\Calendar;

use App\Jobs\SyncRepWeek;
use App\Models\Rep;
use App\Models\Tenant;
use App\Support\JobLog;
use App\Support\RepClock;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Every-minute dispatcher of the daily calendar sync (PRD FR-4).
 *
 * For each tenant and each distinct rep timezone whose local time is inside
 * [sync_hour:00, sync_cutoff), dispatches SyncRepWeek for the week of the
 * rep's local tomorrow (ADR-0002) for every active rep with no attempt and no
 * success on that week today.
 *
 * Invariants:
 *  - The window is evaluated once per zone, not per rep.
 *  - Re-running in the same minute never queues a second job: SyncRepWeek is
 *    ShouldBeUnique per (rep, week) until the cutoff, and once it has run its
 *    `last_attempt_at` excludes the rep for the rest of the local day.
 *  - A failure in one tenant is logged and the others are still dispatched.
 */
final class DispatchSync extends Command
{
    protected $signature = 'calendar:dispatch-sync';

    protected $description = 'Dispatch SyncRepWeek for reps whose local sync window is open';

    public function handle(TenantContext $context, JobLog $log): int
    {
        $now = CarbonImmutable::now();
        $failed = false;

        foreach (Tenant::all() as $tenant) {
            try {
                $calls = $context->run($tenant, fn (Tenant $t): int => $this->dispatchForTenant($t, $now));
                $log->info('sync.dispatched', ['tenant_id' => $tenant->id, 'calls' => $calls]);
            } catch (Throwable $e) {
                $failed = true;
                $log->error('sync.dispatch_failed', ['tenant_id' => $tenant->id, 'exception' => $e::class]);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function dispatchForTenant(Tenant $tenant, CarbonImmutable $now): int
    {
        $calls = 0;

        /** @var list<string> $zones */
        $zones = Rep::query()->active()->distinct()->orderBy('timezone')->pluck('timezone')->all();

        foreach ($zones as $zone) {
            $clock = new RepClock($zone);
            $today = $clock->localDateOf($now);

            if (! $this->isInsideSyncWindow($clock, $today, $now)) {
                continue;
            }

            $week = $clock->weekToSyncOn($today)->toDateString();
            [$dayStart] = $clock->utcBoundsOfLocalDay($today);

            /** @var list<int> $repIds */
            $repIds = Rep::query()
                ->active()
                ->where('timezone', $zone)
                ->whereDoesntHave('weekSyncs', static fn (Builder $q): Builder => $q
                    ->where('week_start', $week)
                    ->where(static fn (Builder $q): Builder => $q
                        ->where('last_attempt_at', '>=', $dayStart)
                        ->orWhere('last_success_at', '>=', $dayStart)))
                ->orderBy('id')
                ->pluck('id')
                ->all();

            foreach ($repIds as $repId) {
                SyncRepWeek::dispatch($tenant->id, $repId, $week);
                $calls++;
            }
        }

        return $calls;
    }

    private function isInsideSyncWindow(RepClock $clock, CarbonImmutable $today, CarbonImmutable $now): bool
    {
        $opens = $clock->atLocalTime($today, sprintf('%02d:00', (int) config('digest.sync_hour')));
        $closes = $clock->atLocalTime($today, (string) config('digest.sync_cutoff'));

        return $now->greaterThanOrEqualTo($opens) && $now->lessThan($closes);
    }
}
