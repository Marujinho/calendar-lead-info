<?php

declare(strict_types=1);

namespace App\Services\Digest;

use App\Models\Rep;
use App\Models\RepWeekSync;
use Carbon\CarbonImmutable;

/**
 * Decides whether a rep's stored meetings are good enough to send the digest
 * for rep-local date D (PRD FR-11, §20 P11).
 *
 * Reads the single `rep_week_syncs` row for the ISO week containing D:
 * no row or no `last_success_at` → no data; success before D 00:00 local →
 * stale; success on or after D 00:00 local → fresh.
 *
 * Invariant: never calls a calendar provider and runs exactly one query, so
 * the 08:00 send never waits on (or pays for) a fetch.
 */
final class DataFreshness
{
    /**
     * @param  CarbonImmutable  $localDate  the digest's rep-local day at 00:00 in the rep's zone (RepClock convention)
     */
    public function for(Rep $rep, CarbonImmutable $localDate): Freshness
    {
        $clock = $rep->clock();
        $weekStart = $clock->isoWeekStart($localDate);

        $sync = RepWeekSync::query()
            ->where('rep_id', $rep->id)
            ->where('week_start', $weekStart->toDateString())
            ->first();

        $refreshedAt = $sync?->last_success_at;

        if ($refreshedAt === null) {
            return Freshness::noData();
        }

        [$startOfDayUtc] = $clock->utcBoundsOfLocalDay($localDate);

        return $refreshedAt->lessThan($startOfDayUtc)
            ? Freshness::stale($refreshedAt)
            : Freshness::fresh($refreshedAt);
    }
}
