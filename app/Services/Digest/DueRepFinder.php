<?php

declare(strict_types=1);

namespace App\Services\Digest;

use App\Models\Rep;
use App\Support\RepClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds the reps of the current tenant whose digest is due now (PRD FR-10).
 *
 * A rep is due when it is active, its local time is inside
 * [send_hour:00, send_hour:00 + send_window_minutes) and it has no
 * `digest_deliveries` row for its local today yet. The window, not an exact
 * minute, lets a late or skipped scheduler tick catch up.
 *
 * Invariants:
 *  - Requires a tenant in TenantContext (every query is tenant-scoped).
 *  - The window is evaluated once per distinct timezone, never per rep, and
 *    the whole lookup is two queries regardless of how many reps or zones
 *    there are.
 */
final class DueRepFinder
{
    /**
     * @return Collection<int, Rep>
     */
    public function dueNow(): Collection
    {
        $now = CarbonImmutable::now()->utc();
        $hour = config()->integer('digest.send_hour');
        $minutes = config()->integer('digest.send_window_minutes');

        /** @var array<string, string> $dueZones timezone => local today (Y-m-d) */
        $dueZones = [];

        $zones = Rep::query()->active()->distinct()->pluck('timezone');
        foreach ($zones as $zone) {
            $clock = new RepClock((string) $zone);
            if ($clock->isWithinLocalWindow($now, $hour, $minutes)) {
                $dueZones[(string) $zone] = $clock->localDateOf($now)->toDateString();
            }
        }

        if ($dueZones === []) {
            return new Collection;
        }

        return Rep::query()
            ->active()
            ->where(static function (Builder $query) use ($dueZones): void {
                foreach ($dueZones as $zone => $localToday) {
                    $query->orWhere(static function (Builder $zoneQuery) use ($zone, $localToday): void {
                        $zoneQuery->where('timezone', $zone)
                            ->whereDoesntHave('digestDeliveries', static function (Builder $deliveries) use ($localToday): void {
                                $deliveries->where('local_date', $localToday);
                            });
                    });
                }
            })
            ->orderBy('id')
            ->get();
    }
}
