<?php

declare(strict_types=1);

namespace Tests\Integration\Digest;

use App\Models\Rep;
use App\Models\RepWeekSync;
use App\Services\Digest\DataFreshness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

/**
 * PRD FR-11 / §20 P11: freshness is read from the rep_week_syncs row of the
 * ISO week containing the digest's local date.
 */
final class FreshnessDataTest extends IntegrationTestCase
{
    private Rep $rep;

    private CarbonImmutable $localDate;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->actingAsTenant();
        $this->rep = Rep::factory()->for($tenant)->inTimezone('America/Sao_Paulo')->create();
        // Wednesday; its ISO week starts Monday 2026-10-12.
        $this->localDate = CarbonImmutable::parse('2026-10-14', 'America/Sao_Paulo');
    }

    #[Test]
    public function no_row_is_no_data(): void
    {
        self::assertFalse((new DataFreshness)->for($this->rep, $this->localDate)->hasData());
    }

    #[Test]
    public function a_row_that_never_succeeded_is_no_data(): void
    {
        $this->syncRow('2026-10-12', null);

        self::assertFalse((new DataFreshness)->for($this->rep, $this->localDate)->hasData());
    }

    #[Test]
    public function a_success_before_local_midnight_is_stale_and_carries_the_timestamp(): void
    {
        // 23:59 local on the 13th = 02:59 UTC on the 14th: still yesterday for the rep.
        $refreshed = CarbonImmutable::parse('2026-10-13 23:59', 'America/Sao_Paulo')->utc();
        $this->syncRow('2026-10-12', $refreshed);

        $freshness = (new DataFreshness)->for($this->rep, $this->localDate);

        self::assertTrue($freshness->isStale());
        self::assertSame($refreshed->toDateTimeString(), $freshness->refreshedAt?->utc()->toDateTimeString());
    }

    #[Test]
    public function a_success_today_at_0500_local_is_fresh(): void
    {
        $this->syncRow('2026-10-12', CarbonImmutable::parse('2026-10-14 05:00', 'America/Sao_Paulo')->utc());

        $freshness = (new DataFreshness)->for($this->rep, $this->localDate);

        self::assertTrue($freshness->hasData());
        self::assertFalse($freshness->isStale());
    }

    #[Test]
    public function a_success_exactly_at_local_midnight_is_fresh(): void
    {
        $this->syncRow('2026-10-12', CarbonImmutable::parse('2026-10-14 00:00', 'America/Sao_Paulo')->utc());

        self::assertFalse((new DataFreshness)->for($this->rep, $this->localDate)->isStale());
    }

    #[Test]
    public function only_the_week_containing_the_date_counts(): void
    {
        // Sunday's fetch of next week (ADR-0002) does not cover this week.
        $this->syncRow('2026-10-19', CarbonImmutable::parse('2026-10-14 05:00', 'America/Sao_Paulo')->utc());

        self::assertFalse((new DataFreshness)->for($this->rep, $this->localDate)->hasData());
    }

    #[Test]
    public function another_reps_sync_does_not_count(): void
    {
        $other = Rep::factory()->for($this->rep->tenant)->inTimezone('America/Sao_Paulo')->create();
        RepWeekSync::factory()->for($other)->create([
            'week_start' => '2026-10-12',
            'last_success_at' => CarbonImmutable::parse('2026-10-14 05:00', 'America/Sao_Paulo')->utc(),
        ]);

        self::assertFalse((new DataFreshness)->for($this->rep, $this->localDate)->hasData());
    }

    #[Test]
    public function it_runs_one_query(): void
    {
        $this->syncRow('2026-10-12', CarbonImmutable::parse('2026-10-14 05:00', 'America/Sao_Paulo')->utc());

        DB::enableQueryLog();
        (new DataFreshness)->for($this->rep, $this->localDate);

        self::assertCount(1, DB::getQueryLog());
    }

    private function syncRow(string $weekStart, ?CarbonImmutable $lastSuccessAt): void
    {
        RepWeekSync::factory()->for($this->rep)->create([
            'week_start' => $weekStart,
            'last_success_at' => $lastSuccessAt,
        ]);
    }
}
