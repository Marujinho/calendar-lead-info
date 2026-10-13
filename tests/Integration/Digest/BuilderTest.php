<?php

declare(strict_types=1);

namespace Tests\Integration\Digest;

use App\Contracts\DigestBuilder;
use App\Data\DigestData;
use App\Data\DigestLead;
use App\Data\DigestMeeting;
use App\Data\Enums\ResponseStatus;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Rep;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

final class BuilderTest extends IntegrationTestCase
{
    private const ZONE = 'Europe/Lisbon';

    private Tenant $tenant;

    private Rep $rep;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt('2026-10-14 08:00', self::ZONE);
        $this->tenant = $this->actingAsTenant();
        $this->rep = Rep::factory()->for($this->tenant)->inTimezone(self::ZONE)->create();
        $this->today = $this->rep->clock()->today();
    }

    #[Test]
    public function it_lists_only_todays_held_meetings_with_leads_ordered_by_start(): void
    {
        $ana = $this->lead('Ana');
        $bob = $this->lead('Bob');
        $cid = $this->lead('Cid');

        $this->meeting('2026-10-13 15:00', [$ana]);                                   // yesterday
        $this->meeting('2026-10-14 09:00', [$ana], cancelled: true);                  // today, cancelled
        $this->meeting('2026-10-14 10:00', []);                                       // today, internal only
        $late = $this->meeting('2026-10-14 14:00', [$ana, $bob], declined: [$bob]);   // today, with leads
        $early = $this->meeting('2026-10-14 11:00', [$cid, $ana]);                    // today, with leads
        $this->meeting('2026-10-14 16:00', [$bob], declined: [$bob]);                 // today, only a declined lead
        $this->meeting('2026-10-15 09:00', [$ana]);                                   // tomorrow

        $digest = $this->build();

        self::assertFalse($digest->isEmpty());
        self::assertSame($this->rep->id, $digest->repId);
        self::assertSame(self::ZONE, $digest->timezone);
        self::assertSame('2026-10-14 00:00', $digest->localDate->format('Y-m-d H:i'));
        self::assertSame([$early->external_id, $late->external_id], $this->externalIds($digest->meetings));

        [$first, $second] = $digest->meetings;
        self::assertSame(['Ana', 'Cid'], $this->names($first));
        self::assertSame(['Ana'], $this->names($second), 'declined lead is removed');
        self::assertSame(3, $digest->leadCount());

        self::assertSame('11:00', $first->startAtLocal->format('H:i'));
        self::assertSame('11:30', $first->endAtLocal->format('H:i'));
        self::assertSame(self::ZONE, $first->startAtLocal->getTimezone()->getName());
        self::assertSame($early->title, $first->title);
    }

    #[Test]
    public function a_lead_in_two_meetings_today_has_the_same_count_in_both(): void
    {
        $ana = $this->lead('Ana');
        $this->meeting('2026-10-01 10:00', [$ana]);
        $this->meeting('2026-10-05 10:00', [$ana]);
        $this->meeting('2026-10-14 10:00', [$ana]);
        $this->meeting('2026-10-14 15:00', [$ana]);

        $digest = $this->build();

        self::assertCount(2, $digest->meetings);
        self::assertSame(2, $digest->meetings[0]->leads[0]->meetingsLast30Days);
        self::assertSame(2, $digest->meetings[1]->leads[0]->meetingsLast30Days);
    }

    #[Test]
    public function lead_fields_are_mapped_and_missing_ones_stay_null(): void
    {
        $lead = Lead::factory()->for($this->tenant)->withoutLinkedin()->create([
            'email' => 'ana@client.test', 'full_name' => 'Ana', 'company_name' => null,
        ]);
        $this->meeting('2026-10-14 10:00', [$lead]);

        $mapped = $this->build()->meetings[0]->leads[0];

        self::assertEquals(new DigestLead('ana@client.test', 'Ana', null, null, 0), $mapped);
    }

    #[Test]
    public function it_is_empty_when_only_internal_meetings_exist(): void
    {
        $this->meeting('2026-10-14 10:00', []);
        $this->meeting('2026-10-14 11:00', []);

        $digest = $this->build();

        self::assertTrue($digest->isEmpty());
        self::assertSame(0, $digest->leadCount());
    }

    #[Test]
    public function a_reps_digest_never_contains_another_reps_meeting_unless_shared(): void
    {
        $other = Rep::factory()->for($this->tenant)->inTimezone(self::ZONE)->create();
        $ana = $this->lead('Ana');

        $onlyOther = $this->meeting('2026-10-14 09:00', [$ana], rep: $other);
        $shared = $this->meeting('2026-10-14 10:00', [$ana]);
        $shared->reps()->attach($other);

        $mine = $this->build();
        $theirs = $this->app->make(DigestBuilder::class)->build($other, $this->today);

        self::assertSame([$shared->external_id], $this->externalIds($mine->meetings));
        self::assertSame([$onlyOther->external_id, $shared->external_id], $this->externalIds($theirs->meetings));
    }

    #[Test]
    public function another_tenant_never_leaks_into_the_digest_or_counts(): void
    {
        $ana = $this->lead('Ana', 'shared@client.test');
        $this->meeting('2026-10-14 10:00', [$ana]);

        $otherTenant = Tenant::factory()->create();
        $this->actingAsTenant($otherTenant);
        $otherRep = Rep::factory()->for($otherTenant)->inTimezone(self::ZONE)->create();
        $otherLead = Lead::factory()->for($otherTenant)->create(['email' => 'shared@client.test']);
        foreach (['2026-10-01 10:00', '2026-10-02 10:00', '2026-10-14 12:00'] as $at) {
            $meeting = Meeting::factory()->for($otherTenant)
                ->startingAt(CarbonImmutable::parse($at, self::ZONE)->utc())->create();
            $meeting->leads()->attach($otherLead);
            $meeting->reps()->attach($otherRep);
        }

        $this->actingAsTenant($this->tenant);
        $digest = $this->build();

        self::assertCount(1, $digest->meetings);
        self::assertSame(0, $digest->meetings[0]->leads[0]->meetingsLast30Days);
    }

    #[Test]
    public function today_is_the_reps_local_day_not_the_utc_day(): void
    {
        $paulo = Rep::factory()->for($this->tenant)->inTimezone('America/Sao_Paulo')->create();
        $ana = $this->lead('Ana');
        // 2026-10-15 01:00 UTC is 2026-10-14 22:00 in São Paulo: today for that rep.
        $evening = Meeting::factory()->for($this->tenant)
            ->startingAt(CarbonImmutable::parse('2026-10-15 01:00', 'UTC'))->create();
        $evening->leads()->attach($ana);
        $evening->reps()->attach($paulo);
        // 2026-10-14 02:00 UTC is 2026-10-13 23:00 in São Paulo: yesterday for that rep.
        $previous = Meeting::factory()->for($this->tenant)
            ->startingAt(CarbonImmutable::parse('2026-10-14 02:00', 'UTC'))->create();
        $previous->leads()->attach($ana);
        $previous->reps()->attach($paulo);

        $digest = $this->app->make(DigestBuilder::class)
            ->build($paulo, CarbonImmutable::parse('2026-10-14', 'America/Sao_Paulo'));

        self::assertSame([$evening->external_id], $this->externalIds($digest->meetings));
        self::assertSame('22:00', $digest->meetings[0]->startAtLocal->format('H:i'));
        self::assertSame(1, $digest->meetings[0]->leads[0]->meetingsLast30Days);
    }

    #[Test]
    public function the_query_count_does_not_depend_on_the_number_of_leads(): void
    {
        $single = $this->lead('Solo');
        $this->meeting('2026-10-14 09:00', [$single]);
        $this->meeting('2026-10-01 09:00', [$single]);

        $one = $this->queriesFor($this->rep);

        $big = Rep::factory()->for($this->tenant)->inTimezone(self::ZONE)->create();
        $leads = Lead::factory()->for($this->tenant)->count(20)->create()->all();
        foreach (array_chunk($leads, 5) as $i => $chunk) {
            $this->meeting(sprintf('2026-10-14 %02d:00', 10 + $i), $chunk, rep: $big);
            $this->meeting(sprintf('2026-10-%02d 09:00', 2 + $i), $chunk, rep: $big);
        }

        $twenty = $this->queriesFor($big);

        self::assertSame(3, $one);
        self::assertSame($one, $twenty);
    }

    #[Test]
    public function building_twenty_meetings_with_five_leads_each_is_fast(): void
    {
        for ($m = 0; $m < 20; $m++) {
            $leads = Lead::factory()->for($this->tenant)->count(5)->create()->all();
            $this->meeting(CarbonImmutable::parse('2026-10-14 07:00', self::ZONE)->addMinutes(30 * $m)->format('Y-m-d H:i'), $leads);
            $this->meeting(sprintf('2026-10-%02d 09:00', 1 + ($m % 13)), $leads);
        }

        $started = hrtime(true);
        $digest = $this->build();
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        self::assertCount(20, $digest->meetings);
        self::assertSame(100, $digest->leadCount());
        self::assertLessThan(500, $elapsedMs);
    }

    private function build(): DigestData
    {
        return $this->app->make(DigestBuilder::class)->build($this->rep, $this->today);
    }

    private function queriesFor(Rep $rep): int
    {
        $builder = $this->app->make(DigestBuilder::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $builder->build($rep, $this->today);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function lead(string $name, ?string $email = null): Lead
    {
        return Lead::factory()->for($this->tenant)->create(array_filter([
            'full_name' => $name,
            'email' => $email,
        ]));
    }

    /**
     * @param  list<Lead>  $leads
     * @param  list<Lead>  $declined
     */
    private function meeting(string $localStart, array $leads, bool $cancelled = false, array $declined = [], ?Rep $rep = null): Meeting
    {
        $factory = Meeting::factory()->for($this->tenant)
            ->startingAt(CarbonImmutable::parse($localStart, self::ZONE)->utc());
        $meeting = ($cancelled ? $factory->cancelled() : $factory)->create();

        $meeting->reps()->attach($rep ?? $this->rep);
        foreach ($leads as $lead) {
            $status = in_array($lead, $declined, true) ? ResponseStatus::Declined : ResponseStatus::Accepted;
            $meeting->leads()->attach($lead, ['response_status' => $status->value]);
        }

        return $meeting;
    }

    /**
     * @param  list<DigestMeeting>  $meetings
     * @return list<string>
     */
    private function externalIds(array $meetings): array
    {
        return array_map(static fn (DigestMeeting $m): string => $m->externalId, $meetings);
    }

    /**
     * @return list<string>
     */
    private function names(DigestMeeting $meeting): array
    {
        return array_map(static fn (DigestLead $l): string => $l->fullName, $meeting->leads);
    }
}
