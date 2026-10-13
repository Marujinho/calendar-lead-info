<?php

declare(strict_types=1);

namespace Tests\Integration\Sync;

use App\Data\AttendeeData;
use App\Data\Enums\MeetingStatus;
use App\Data\Enums\ResponseStatus;
use App\Data\MeetingData;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\MeetingLead;
use App\Models\MeetingRep;
use App\Models\Rep;
use App\Models\Tenant;
use App\Services\Sync\LeadClassifier;
use App\Services\Sync\MeetingUpserter;
use App\Services\Sync\UpsertResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Sync\Concerns\BuildsSchedules;
use Tests\Support\IntegrationTestCase;

final class MeetingUpserterTest extends IntegrationTestCase
{
    use BuildsSchedules;

    private Tenant $tenant;

    private Rep $ana;

    private Rep $rui;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt('2026-10-14 06:00');
        $this->tenant = $this->actingAsTenant(Tenant::factory()->create(['internal_domains' => ['ourco.test']]));
        $this->ana = Rep::factory()->for($this->tenant)->create(['email' => 'ana@ourco.test']);
        $this->rui = Rep::factory()->for($this->tenant)->create(['email' => 'rui@gmail.com']);
    }

    #[Test]
    public function it_stores_meetings_leads_and_links_with_the_tenant_id(): void
    {
        $result = $this->apply($this->ana, [
            $this->meeting('evt_1', '2026-10-14 10:00', [
                $this->attendee('ana@ourco.test'),
                $this->attendee('rui@gmail.com'),
                $this->attendee('colleague@ourco.test'),
                new AttendeeData('B@Client.com', 'Bea', 'Client Inc', 'https://www.linkedin.com/in/bea'),
            ]),
        ]);

        self::assertEquals(new UpsertResult(1, 1, 0, 0), $result);
        $meeting = Meeting::query()->sole();
        self::assertSame('evt_1', $meeting->external_id);
        self::assertSame('2026-10-14 10:00:00', $meeting->start_at->format('Y-m-d H:i:s'));
        self::assertSame(MeetingStatus::Confirmed, $meeting->status);
        self::assertNull($meeting->removed_at);

        $lead = Lead::query()->sole();
        self::assertSame(['b@client.com', 'Bea', 'Client Inc', 'https://www.linkedin.com/in/bea'], [$lead->email, $lead->full_name, $lead->company_name, $lead->linkedin_url]);

        self::assertSame([$this->ana->id], MeetingRep::query()->pluck('rep_id')->all());
        foreach (['leads', 'meetings', 'meeting_rep', 'meeting_lead'] as $table) {
            self::assertSame([$this->tenant->id], DB::table($table)->distinct()->pluck('tenant_id')->map(static fn (mixed $id): int => (int) $id)->all(), $table);
        }
    }

    #[Test]
    public function applying_the_same_schedule_twice_changes_no_row_counts(): void
    {
        $meetings = [
            $this->meeting('evt_1', '2026-10-13 10:00', [$this->attendee('b@client.com'), $this->attendee('c@client.com')]),
            $this->meeting('evt_2', '2026-10-15 10:00', [$this->attendee('b@client.com', ResponseStatus::Declined)]),
        ];

        $this->apply($this->ana, $meetings);
        $before = $this->rowCounts();
        $second = $this->apply($this->ana, $meetings);

        self::assertSame($before, $this->rowCounts());
        self::assertSame(['leads' => 2, 'meetings' => 2, 'meeting_rep' => 2, 'meeting_lead' => 3], $before);
        self::assertSame(0, $second->detached);
        self::assertSame(0, $second->removed);
    }

    #[Test]
    public function lead_profile_fields_and_meeting_fields_take_the_latest_values(): void
    {
        $this->apply($this->ana, [$this->meeting('evt_1', '2026-10-14 10:00', [new AttendeeData('b@client.com', 'Bea', 'Old Co', 'https://www.linkedin.com/in/old')])]);
        $this->apply($this->ana, [$this->meeting('evt_1', '2026-10-14 11:00', [new AttendeeData('b@client.com', 'Bea Silva', null, null)], MeetingStatus::Cancelled, 'Moved')]);

        $lead = Lead::query()->sole();
        self::assertSame(['Bea Silva', null, null], [$lead->full_name, $lead->company_name, $lead->linkedin_url]);
        $meeting = Meeting::query()->sole();
        self::assertSame(['Moved', '2026-10-14 11:00:00', MeetingStatus::Cancelled], [$meeting->title, $meeting->start_at->format('Y-m-d H:i:s'), $meeting->status]);
    }

    #[Test]
    public function a_shared_meeting_is_one_row_linked_to_both_reps(): void
    {
        $shared = $this->meeting('evt_shared', '2026-10-14 10:00', [$this->attendee('b@client.com')]);

        $this->apply($this->ana, [$shared]);
        $this->apply($this->rui, [$shared]);

        self::assertSame(1, Meeting::query()->count());
        self::assertEqualsCanonicalizing([$this->ana->id, $this->rui->id], MeetingRep::query()->pluck('rep_id')->all());
        self::assertSame(1, MeetingLead::query()->count());
    }

    #[Test]
    public function the_shared_meeting_removal_rule(): void
    {
        $shared = $this->meeting('evt_shared', '2026-10-14 10:00', [$this->attendee('b@client.com')]);
        $this->apply($this->ana, [$shared]);
        $this->apply($this->rui, [$shared]);

        $dropA = $this->apply($this->ana, []);
        self::assertSame([1, 0], [$dropA->detached, $dropA->removed]);
        self::assertNull(Meeting::query()->sole()->removed_at, 'still visible for the other rep');
        self::assertSame([$this->rui->id], MeetingRep::query()->pluck('rep_id')->all());

        $dropB = $this->apply($this->rui, []);
        self::assertSame([1, 1], [$dropB->detached, $dropB->removed]);
        $removedAt = Meeting::query()->sole()->getAttribute('removed_at');
        self::assertInstanceOf(CarbonImmutable::class, $removedAt);
        self::assertSame('2026-10-14 06:00:00', $removedAt->format('Y-m-d H:i:s'));
        self::assertSame(1, Meeting::query()->count(), 'never deleted');
        self::assertSame(1, MeetingLead::query()->count(), 'lead link kept');

        $this->apply($this->ana, [$shared]);
        self::assertNull(Meeting::query()->sole()->removed_at, 'reappearing meeting is un-removed');
        self::assertSame([$this->ana->id], MeetingRep::query()->pluck('rep_id')->all());
    }

    #[Test]
    public function removal_only_considers_meetings_inside_the_synced_week(): void
    {
        $this->apply($this->ana, [$this->meeting('evt_last_week', '2026-10-09 10:00', [$this->attendee('b@client.com')])], '2026-10-05');
        $this->apply($this->ana, [$this->meeting('evt_next_week', '2026-10-19 10:00', [$this->attendee('b@client.com')])], '2026-10-19');

        $result = $this->apply($this->ana, [], '2026-10-12');

        self::assertSame(0, $result->detached);
        self::assertSame(2, MeetingRep::query()->where('rep_id', $this->ana->id)->count());
    }

    #[Test]
    public function the_week_window_is_the_reps_local_week(): void
    {
        $lisbon = Rep::factory()->for($this->tenant)->inTimezone('Europe/Lisbon')->create(['email' => 'lee@ourco.test']);
        // Monday 2026-10-12 00:30 Lisbon (WEST, UTC+1) is Sunday 23:30 UTC: inside Lisbon's week.
        $this->apply($lisbon, [$this->meeting('evt_edge', '2026-10-11 23:30', [$this->attendee('b@client.com')])], '2026-10-12', 'Europe/Lisbon');

        $result = $this->apply($lisbon, [], '2026-10-12', 'Europe/Lisbon');

        self::assertSame(1, $result->detached);
    }

    #[Test]
    public function a_declined_attendee_is_stored_with_its_response_status(): void
    {
        $this->apply($this->ana, [$this->meeting('evt_1', '2026-10-14 10:00', [
            $this->attendee('d@client.com', ResponseStatus::Declined),
            $this->attendee('t@client.com', ResponseStatus::Tentative),
        ])]);

        $statuses = MeetingLead::query()->get()->mapWithKeys(fn (MeetingLead $l): array => [Lead::query()->findOrFail($l->lead_id)->email => $l->response_status])->all();
        self::assertSame(['d@client.com' => ResponseStatus::Declined, 't@client.com' => ResponseStatus::Tentative], $statuses);

        $this->apply($this->ana, [$this->meeting('evt_1', '2026-10-14 10:00', [$this->attendee('d@client.com', ResponseStatus::Accepted)])]);
        self::assertSame(ResponseStatus::Accepted, MeetingLead::query()->where('lead_id', Lead::query()->where('email', 'd@client.com')->value('id'))->sole()->response_status);
    }

    #[Test]
    public function a_meeting_with_only_internal_attendees_is_stored_without_leads(): void
    {
        $result = $this->apply($this->ana, [$this->meeting('evt_internal', '2026-10-14 10:00', [$this->attendee('ana@ourco.test'), $this->attendee('bob@ourco.test')])]);

        self::assertSame(1, $result->meetingsUpserted);
        self::assertSame(0, $result->leadsUpserted);
        self::assertSame(0, Lead::query()->count());
        self::assertSame(1, MeetingRep::query()->count());
    }

    #[Test]
    public function a_duplicate_attendee_in_one_meeting_is_linked_once(): void
    {
        $this->apply($this->ana, [$this->meeting('evt_1', '2026-10-14 10:00', [$this->attendee('b@client.com'), $this->attendee('B@client.com')])]);

        self::assertSame(1, MeetingLead::query()->count());
    }

    #[Test]
    public function the_number_of_queries_does_not_grow_with_the_payload(): void
    {
        $one = $this->queriesFor($this->ana, 1);
        $twenty = $this->queriesFor($this->rui, 20);

        self::assertSame($one, $twenty);
        self::assertLessThanOrEqual(9, $twenty);
    }

    private function queriesFor(Rep $rep, int $meetings): int
    {
        $payload = [];
        for ($i = 0; $i < $meetings; $i++) {
            $payload[] = $this->meeting(sprintf('evt_%d_%d', $rep->id, $i), '2026-10-14 10:00', [
                $this->attendee(sprintf('a%d.%d@client.com', $rep->id, $i)),
                $this->attendee(sprintf('b%d.%d@client.com', $rep->id, $i)),
            ]);
        }
        // A previously linked meeting that is now missing, so the removal path runs too.
        $this->apply($rep, [$this->meeting('evt_gone_'.$rep->id, '2026-10-13 10:00', [$this->attendee('z@client.com')])]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $classifier = new LeadClassifier($this->tenant->internalDomains(), [$this->ana->email, $this->rui->email]);
        $this->app->make(MeetingUpserter::class)->apply($rep, $this->week($rep->email, '2026-10-12', $payload), $classifier);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @param  list<MeetingData>  $meetings
     */
    private function apply(Rep $rep, array $meetings, string $monday = '2026-10-12', string $timezone = 'UTC'): UpsertResult
    {
        $schedule = $this->week($rep->email, $monday, $meetings, $timezone);

        return $this->app->make(MeetingUpserter::class)->apply($rep, $schedule, LeadClassifier::forTenant($this->tenant));
    }

    private function attendee(string $email, ResponseStatus $status = ResponseStatus::Accepted): AttendeeData
    {
        return new AttendeeData($email, 'Lead '.$email, 'Client', null, $status);
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'leads' => Lead::query()->count(),
            'meetings' => Meeting::query()->count(),
            'meeting_rep' => MeetingRep::query()->count(),
            'meeting_lead' => MeetingLead::query()->count(),
        ];
    }
}
