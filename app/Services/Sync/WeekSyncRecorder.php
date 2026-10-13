<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\RepWeekSync;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Bookkeeping of `rep_week_syncs` for the sync and backfill jobs (PRD FR-11
 * reads it to decide freshness).
 *
 * Invariants:
 *  - Every provider attempt increments `attempts` and stamps `last_attempt_at`
 *    before the call, so a crash mid-call is still visible.
 *  - `last_success_at` only moves forward on success; a failure records the
 *    exception class name (never its message, which may contain PII) and
 *    leaves the previous success in place for the stale-data fallback.
 */
final class WeekSyncRecorder
{
    /** @param string $weekStart Y-m-d, the rep-local Monday */
    public function attempt(int $repId, string $weekStart): RepWeekSync
    {
        $sync = RepWeekSync::query()->firstOrCreate(
            ['rep_id' => $repId, 'week_start' => $weekStart],
            ['attempts' => 0],
        );
        $sync->increment('attempts', 1, ['last_attempt_at' => CarbonImmutable::now()]);

        return $sync;
    }

    public function succeeded(RepWeekSync $sync): void
    {
        $sync->update(['last_success_at' => CarbonImmutable::now(), 'last_error_class' => null]);
    }

    public function failed(RepWeekSync $sync, Throwable $e): void
    {
        $sync->update(['last_error_class' => $e::class]);
    }
}
