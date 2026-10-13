<?php

declare(strict_types=1);

namespace App\Services\Digest;

use App\Contracts\DigestBuilder;
use App\Data\DigestData;
use App\Data\DigestLead;
use App\Data\DigestMeeting;
use App\Data\Enums\ResponseStatus;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Rep;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Builds one rep's digest for one rep-local date from stored rows (PRD FR-7).
 *
 * Invariants:
 *  - Reads MySQL only; never touches a CalendarProvider (FR-3).
 *  - Only meetings linked to this rep through `meeting_rep` appear (A01).
 *  - Only held meetings (`Meeting::held()`) starting inside the rep-local day
 *    appear, ordered by start; declined leads are dropped and a meeting left
 *    with no leads is dropped.
 *  - Exactly three queries whatever the number of meetings or leads (NFR-2):
 *    meetings, their leads (eager load), and one grouped history count.
 */
final class EloquentDigestBuilder implements DigestBuilder
{
    public function __construct(private readonly LeadHistoryCounter $historyCounter) {}

    public function build(Rep $rep, CarbonImmutable $localDate): DigestData
    {
        $clock = $rep->clock();
        $localDay = $clock->localDateOf($localDate);
        [$dayStart, $dayEnd] = $clock->utcBoundsOfLocalDay($localDay);

        $meetings = $rep->meetings()
            ->held()
            ->where('meetings.start_at', '>=', $dayStart)
            ->where('meetings.start_at', '<', $dayEnd)
            ->orderBy('meetings.start_at')
            ->orderBy('meetings.id')
            ->with(['leads' => static function (BelongsToMany $query): void {
                $query->wherePivot('response_status', '<>', ResponseStatus::Declined->value)
                    ->orderBy('leads.full_name')
                    ->orderBy('leads.id');
            }])
            ->get()
            ->filter(static fn (Meeting $meeting): bool => $meeting->leads->isNotEmpty())
            ->values();

        $leadIds = $meetings
            ->flatMap(static fn (Meeting $meeting): array => $meeting->leads->map(static fn (Lead $lead): int => $lead->id)->all())
            ->unique()
            ->values()
            ->all();

        $counts = $this->historyCounter->countFor($rep->tenant_id, $leadIds, $clock, $localDay);

        $digestMeetings = $meetings
            ->map(static fn (Meeting $meeting): DigestMeeting => new DigestMeeting(
                externalId: $meeting->external_id,
                title: $meeting->title,
                startAtLocal: $meeting->start_at->setTimezone($clock->timezone),
                endAtLocal: $meeting->end_at->setTimezone($clock->timezone),
                leads: $meeting->leads
                    ->map(static fn (Lead $lead): DigestLead => new DigestLead(
                        email: $lead->email,
                        fullName: $lead->full_name,
                        companyName: $lead->company_name,
                        linkedinUrl: $lead->linkedin_url,
                        meetingsLast30Days: $counts[$lead->id] ?? 0,
                    ))
                    ->values()
                    ->all(),
            ))
            ->values()
            ->all();

        return new DigestData(
            repId: $rep->id,
            localDate: $localDay,
            timezone: $clock->timezone,
            meetings: $digestMeetings,
        );
    }
}
