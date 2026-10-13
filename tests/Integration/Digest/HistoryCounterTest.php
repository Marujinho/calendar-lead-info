<?php

declare(strict_types=1);

namespace Tests\Integration\Digest;

use App\Data\Enums\ResponseStatus;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Rep;
use App\Models\Tenant;
use App\Services\Digest\LeadHistoryCounter;
use App\Support\RepClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

final class HistoryCounterTest extends IntegrationTestCase
{
    private const ZONE = 'Europe/Lisbon';

    private Tenant $tenant;

    private RepClock $clock;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt('2026-10-14 08:00', self::ZONE);
        $this->tenant = $this->actingAsTenant();
        $this->clock = new RepClock(self::ZONE);
        $this->today = $this->clock->today();
    }

    #[Test]
    public function it_counts_the_prd_fixture_as_two(): void
    {
        $lead = Lead::factory()->for($this->tenant)->create();
        $repA = Rep::factory()->for($this->tenant)->inTimezone(self::ZONE)->create();
        $repB = Rep::factory()->for($this->tenant)->inTimezone(self::ZONE)->create();

        $this->meetingAt('2026-09-13 10:00', $lead);                       // 31 d ago: out
        $this->meetingAt('2026-09-14 10:00', $lead);                       // 30 d ago: in
        $shared = $this->meetingAt('2026-10-04 10:00', $lead);             // 10 d ago, two reps, one external id: in once
        $shared->reps()->attach([$repA->id, $repB->id]);
        $this->meetingAt('2026-10-09 10:00', $lead, ResponseStatus::Declined); // 5 d ago, declined: out
        $this->meetingAt('2026-10-11 10:00', $lead, cancelled: true);      // 3 d ago, cancelled: out
        $this->meetingAt('2026-10-14 07:00', $lead);                       // earlier today: out

        self::assertSame([$lead->id => 2], $this->countsFor([$lead->id]));
    }

    #[Test]
    public function the_window_is_half_open_in_rep_local_time(): void
    {
        $atStart = Lead::factory()->for($this->tenant)->create();
        $justBefore = Lead::factory()->for($this->tenant)->create();
        $todayMidnight = Lead::factory()->for($this->tenant)->create();
        $justBeforeToday = Lead::factory()->for($this->tenant)->create();

        $this->meetingAt('2026-09-14 00:00:00', $atStart);          // exactly 30 d, 00:00 local: in
        $this->meetingAt('2026-09-13 23:59:59', $justBefore);       // 30 d + 1 s: out
        $this->meetingAt('2026-10-14 00:00:00', $todayMidnight);    // today 00:00: out
        $this->meetingAt('2026-10-13 23:59:59', $justBeforeToday);  // last second of yesterday: in

        self::assertSame(
            [$atStart->id => 1, $justBefore->id => 0, $todayMidnight->id => 0, $justBeforeToday->id => 1],
            $this->countsFor([$atStart->id, $justBefore->id, $todayMidnight->id, $justBeforeToday->id]),
        );
    }

    #[Test]
    public function removed_and_tentative_meetings_follow_the_held_rule(): void
    {
        $lead = Lead::factory()->for($this->tenant)->create();

        Meeting::factory()->for($this->tenant)->removed()
            ->startingAt(CarbonImmutable::parse('2026-10-01 10:00', self::ZONE)->utc())
            ->create()->leads()->attach($lead);
        Meeting::factory()->for($this->tenant)->tentative()
            ->startingAt(CarbonImmutable::parse('2026-10-02 10:00', self::ZONE)->utc())
            ->create()->leads()->attach($lead, ['response_status' => ResponseStatus::NeedsAction->value]);

        self::assertSame([$lead->id => 1], $this->countsFor([$lead->id]));
    }

    #[Test]
    public function leads_without_meetings_are_zero_and_no_leads_runs_no_query(): void
    {
        $lead = Lead::factory()->for($this->tenant)->create();

        self::assertSame([$lead->id => 0], $this->countsFor([$lead->id]));

        DB::enableQueryLog();
        DB::flushQueryLog();
        self::assertSame([], $this->countsFor([]));
        self::assertCount(0, DB::getQueryLog());
    }

    #[Test]
    public function many_leads_are_counted_in_one_query(): void
    {
        $leads = Lead::factory()->for($this->tenant)->count(5)->create();
        foreach ($leads as $i => $lead) {
            for ($n = 0; $n <= $i; $n++) {
                $this->meetingAt(sprintf('2026-10-%02d 10:00', 1 + $n), $lead);
            }
        }
        $ids = $leads->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $counts = $this->countsFor($ids);

        self::assertCount(1, DB::getQueryLog());
        self::assertSame(array_combine($ids, [1, 2, 3, 4, 5]), $counts);
    }

    #[Test]
    public function another_tenants_meetings_with_the_same_lead_email_have_no_effect(): void
    {
        $lead = Lead::factory()->for($this->tenant)->create(['email' => 'shared@client.test']);
        $this->meetingAt('2026-10-01 10:00', $lead);

        $other = Tenant::factory()->create();
        $this->actingAsTenant($other);
        $otherLead = Lead::factory()->for($other)->create(['email' => 'shared@client.test']);
        foreach (['2026-10-02 10:00', '2026-10-03 10:00', '2026-10-05 10:00'] as $at) {
            Meeting::factory()->for($other)->startingAt(CarbonImmutable::parse($at, self::ZONE)->utc())
                ->create()->leads()->attach($otherLead);
        }

        $this->actingAsTenant($this->tenant);
        self::assertSame([$lead->id => 1], $this->countsFor([$lead->id]));

        // Asking for this tenant's lead under the other tenant's id finds nothing.
        self::assertSame([$lead->id => 0], (new LeadHistoryCounter)->countFor($other, [$lead->id], $this->clock, $this->today));
    }

    #[Test]
    public function two_timezones_give_different_utc_windows(): void
    {
        $lead = Lead::factory()->for($this->tenant)->create();
        // 2026-09-13 23:30 UTC is 2026-09-14 00:30 in Lisbon (WEST, +1) but 20:30 on the 13th in São Paulo (-3).
        Meeting::factory()->for($this->tenant)
            ->startingAt(CarbonImmutable::parse('2026-09-13 23:30', 'UTC'))
            ->create()->leads()->attach($lead);

        $lisbon = new RepClock('Europe/Lisbon');
        $saoPaulo = new RepClock('America/Sao_Paulo');
        $counter = new LeadHistoryCounter;

        self::assertSame([$lead->id => 1], $counter->countFor($this->tenant, [$lead->id], $lisbon, CarbonImmutable::parse('2026-10-14', 'Europe/Lisbon')));
        self::assertSame([$lead->id => 0], $counter->countFor($this->tenant, [$lead->id], $saoPaulo, CarbonImmutable::parse('2026-10-14', 'America/Sao_Paulo')));
    }

    /**
     * @param  list<int>  $leadIds
     * @return array<int, int>
     */
    private function countsFor(array $leadIds): array
    {
        return (new LeadHistoryCounter)->countFor($this->tenant->id, $leadIds, $this->clock, $this->today);
    }

    private function meetingAt(string $localStart, Lead $lead, ResponseStatus $response = ResponseStatus::Accepted, bool $cancelled = false): Meeting
    {
        $factory = Meeting::factory()->for($this->tenant)
            ->startingAt(CarbonImmutable::parse($localStart, self::ZONE)->utc());

        $meeting = ($cancelled ? $factory->cancelled() : $factory)->create();
        $meeting->leads()->attach($lead, ['response_status' => $response->value]);

        return $meeting;
    }
}
