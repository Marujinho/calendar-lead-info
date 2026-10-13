<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Data\AttendeeData;
use App\Data\MeetingData;
use App\Data\WeekSchedule;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\MeetingRep;
use App\Models\Rep;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Applies one rep's fetched week to MySQL (PRD FR-5).
 *
 * Invariants:
 *  - Idempotent: applying the same WeekSchedule twice changes no row counts.
 *    Writes are `INSERT … ON DUPLICATE KEY UPDATE` keyed on the unique
 *    indexes, so two reps' syncs racing on a shared meeting converge.
 *  - Bounded: a constant number of statements per call, whatever the number
 *    of meetings or attendees (collect, then bulk upsert / `whereIn`).
 *  - Removal never deletes a meeting: a meeting missing from this rep's
 *    payload is detached from this rep only, and gets `removed_at` once no
 *    rep is attached. A meeting that reappears is un-removed.
 *  - Raw upserts bypass Eloquent, so every row carries `tenant_id` explicitly;
 *    every read goes through the tenant-scoped models.
 */
final class MeetingUpserter
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function apply(Rep $rep, WeekSchedule $schedule, LeadClassifier $classifier): UpsertResult
    {
        return DB::transaction(
            fn (): UpsertResult => $this->applyInTransaction($rep, $schedule, $classifier),
            self::TRANSACTION_ATTEMPTS,
        );
    }

    private function applyInTransaction(Rep $rep, WeekSchedule $schedule, LeadClassifier $classifier): UpsertResult
    {
        $tenantId = $rep->tenant_id;
        $now = CarbonImmutable::now()->utc()->format('Y-m-d H:i:s');

        $meetingsByExternalId = $this->meetingsByExternalId($schedule);
        $leadsByMeeting = $this->leadAttendeesByMeeting($meetingsByExternalId, $classifier);
        $leadProfiles = $this->latestLeadProfiles($leadsByMeeting);

        $this->upsertLeads($tenantId, $leadProfiles, $now);
        $this->upsertMeetings($tenantId, $meetingsByExternalId, $now);

        $meetingIds = $meetingsByExternalId === []
            ? []
            : Meeting::query()->whereIn('external_id', array_keys($meetingsByExternalId))->pluck('id', 'external_id')->all();
        $leadIds = $leadProfiles === []
            ? []
            : Lead::query()->whereIn('email', array_keys($leadProfiles))->pluck('id', 'email')->all();

        $this->upsertMeetingRep($tenantId, $rep->id, $meetingIds, $now);
        $this->upsertMeetingLead($tenantId, $leadsByMeeting, $meetingIds, $leadIds, $now);

        [$detached, $removed] = $this->detachMissing($rep, $schedule, array_keys($meetingsByExternalId), $now);

        return new UpsertResult(count($meetingsByExternalId), count($leadProfiles), $detached, $removed);
    }

    /**
     * Last occurrence wins if a payload repeats an id. Sorted by key so
     * concurrent writers lock unique-index rows in the same order.
     *
     * @return array<string, MeetingData>
     */
    private function meetingsByExternalId(WeekSchedule $schedule): array
    {
        $meetings = [];
        foreach ($schedule->meetings as $meeting) {
            $meetings[$meeting->externalId] = $meeting;
        }
        ksort($meetings, SORT_STRING);

        return $meetings;
    }

    /**
     * @param  array<string, MeetingData>  $meetings
     * @return array<string, array<string, AttendeeData>> external id => lead email => attendee
     */
    private function leadAttendeesByMeeting(array $meetings, LeadClassifier $classifier): array
    {
        $byMeeting = [];
        foreach ($meetings as $externalId => $meeting) {
            foreach ($meeting->attendees as $attendee) {
                if ($classifier->isLead($attendee)) {
                    $byMeeting[$externalId][$attendee->email] = $attendee;
                }
            }
        }

        return $byMeeting;
    }

    /**
     * @param  array<string, array<string, AttendeeData>>  $leadsByMeeting
     * @return array<string, AttendeeData>
     */
    private function latestLeadProfiles(array $leadsByMeeting): array
    {
        $profiles = [];
        foreach ($leadsByMeeting as $attendees) {
            foreach ($attendees as $email => $attendee) {
                $profiles[$email] = $attendee;
            }
        }
        ksort($profiles, SORT_STRING);

        return $profiles;
    }

    /**
     * @param  array<string, AttendeeData>  $profiles
     */
    private function upsertLeads(int $tenantId, array $profiles, string $now): void
    {
        if ($profiles === []) {
            return;
        }

        $rows = [];
        foreach ($profiles as $email => $attendee) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'email' => $email,
                'full_name' => $attendee->fullName,
                'company_name' => $attendee->companyName,
                'linkedin_url' => $attendee->linkedinUrl,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('leads')->upsert($rows, ['tenant_id', 'email'], ['full_name', 'company_name', 'linkedin_url', 'updated_at']);
    }

    /**
     * @param  array<string, MeetingData>  $meetings
     */
    private function upsertMeetings(int $tenantId, array $meetings, string $now): void
    {
        if ($meetings === []) {
            return;
        }

        $rows = [];
        foreach ($meetings as $externalId => $meeting) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'external_id' => $externalId,
                'title' => $meeting->title,
                'start_at' => $meeting->startAt->utc()->format('Y-m-d H:i:s'),
                'end_at' => $meeting->endAt->utc()->format('Y-m-d H:i:s'),
                'status' => $meeting->status->value,
                'removed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('meetings')->upsert(
            $rows,
            ['tenant_id', 'external_id'],
            ['title', 'start_at', 'end_at', 'status', 'removed_at', 'updated_at'],
        );
    }

    /**
     * @param  array<string, int>  $meetingIds
     */
    private function upsertMeetingRep(int $tenantId, int $repId, array $meetingIds, string $now): void
    {
        if ($meetingIds === []) {
            return;
        }

        $ids = array_values($meetingIds);
        sort($ids);

        $rows = array_map(static fn (int $meetingId): array => [
            'tenant_id' => $tenantId,
            'meeting_id' => $meetingId,
            'rep_id' => $repId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $ids);

        DB::table('meeting_rep')->upsert($rows, ['meeting_id', 'rep_id'], ['updated_at']);
    }

    /**
     * @param  array<string, array<string, AttendeeData>>  $leadsByMeeting
     * @param  array<string, int>  $meetingIds
     * @param  array<string, int>  $leadIds
     */
    private function upsertMeetingLead(int $tenantId, array $leadsByMeeting, array $meetingIds, array $leadIds, string $now): void
    {
        $rows = [];
        foreach ($leadsByMeeting as $externalId => $attendees) {
            foreach ($attendees as $email => $attendee) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'meeting_id' => $meetingIds[$externalId],
                    'lead_id' => $leadIds[$email],
                    'response_status' => $attendee->responseStatus->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        usort($rows, static fn (array $a, array $b): int => [$a['meeting_id'], $a['lead_id']] <=> [$b['meeting_id'], $b['lead_id']]);

        DB::table('meeting_lead')->upsert($rows, ['meeting_id', 'lead_id'], ['response_status', 'updated_at']);
    }

    /**
     * Shared-meeting removal rule: meetings this rep was linked to, starting
     * inside the synced week, and absent from the payload are detached from
     * this rep; those left with no rep at all get `removed_at`.
     *
     * @param  list<string>  $presentExternalIds
     * @return array{0: int, 1: int} detached, removed
     */
    private function detachMissing(Rep $rep, WeekSchedule $schedule, array $presentExternalIds, string $now): array
    {
        $clock = $rep->clock();
        $weekStart = $clock->isoWeekStart($schedule->weekStart);
        [$fromUtc] = $clock->utcBoundsOfLocalDay($weekStart);
        [$toUtc] = $clock->utcBoundsOfLocalDay($weekStart->addWeek());

        /** @var list<int> $missing */
        $missing = Meeting::query()
            ->whereIn('id', MeetingRep::query()->select('meeting_id')->where('rep_id', $rep->id))
            ->where('start_at', '>=', $fromUtc)
            ->where('start_at', '<', $toUtc)
            ->when($presentExternalIds !== [], static fn (Builder $q): Builder => $q->whereNotIn('external_id', $presentExternalIds))
            ->pluck('id')
            ->all();

        if ($missing === []) {
            return [0, 0];
        }

        $detached = MeetingRep::query()->where('rep_id', $rep->id)->whereIn('meeting_id', $missing)->delete();

        $removed = Meeting::query()
            ->whereIn('id', $missing)
            ->whereNull('removed_at')
            ->whereDoesntHave('reps')
            ->update(['removed_at' => $now]);

        return [(int) $detached, $removed];
    }
}
