<?php

declare(strict_types=1);

namespace App\Services\Digest;

use App\Data\Enums\ResponseStatus;
use App\Models\Meeting;
use App\Models\Tenant;
use App\Support\RepClock;
use Carbon\CarbonImmutable;

/**
 * Counts, per lead, the distinct held meetings the lead attended with anyone
 * in the tenant during the 30 rep-local days before the digest date (PRD FR-9).
 *
 * Invariants:
 *  - One grouped query for any number of leads (NFR-2).
 *  - The tenant filter is explicit on both `meetings` and `meeting_lead`, on
 *    top of the TenantScope, so another tenant's rows can never be counted (A01).
 *  - "Held" comes from `Meeting::held()` only; declined links never count.
 *  - The window is `RepClock::historyWindowUtc()`: today is excluded.
 *  - Every requested lead id is in the result; leads with no rows map to 0.
 */
final class LeadHistoryCounter
{
    /**
     * @param  list<int>  $leadIds
     * @return array<int, int> lead_id => count
     */
    public function countFor(Tenant|int $tenantId, array $leadIds, RepClock $clock, CarbonImmutable $localDate): array
    {
        if ($leadIds === []) {
            return [];
        }

        $tenantId = $tenantId instanceof Tenant ? (int) $tenantId->getKey() : $tenantId;
        [$windowStart, $windowEnd] = $clock->historyWindowUtc($localDate);

        $rows = Meeting::query()
            ->held()
            ->join('meeting_lead', 'meeting_lead.meeting_id', '=', 'meetings.id')
            ->where('meetings.tenant_id', $tenantId)
            ->where('meeting_lead.tenant_id', $tenantId)
            ->whereIn('meeting_lead.lead_id', $leadIds)
            ->where('meeting_lead.response_status', '<>', ResponseStatus::Declined->value)
            ->where('meetings.start_at', '>=', $windowStart)
            ->where('meetings.start_at', '<', $windowEnd)
            ->groupBy('meeting_lead.lead_id')
            ->select('meeting_lead.lead_id')
            ->selectRaw('COUNT(DISTINCT meetings.id) AS meetings_count')
            ->toBase()
            ->get();

        $counts = array_fill_keys($leadIds, 0);
        foreach ($rows as $row) {
            /** @var object{lead_id: int|string, meetings_count: int|string} $row */
            $counts[(int) $row->lead_id] = (int) $row->meetings_count;
        }

        return $counts;
    }
}
