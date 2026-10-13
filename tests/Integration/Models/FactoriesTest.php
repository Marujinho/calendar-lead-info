<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Data\Enums\CallOutcome;
use App\Data\Enums\DeliveryStatus;
use App\Data\Enums\MeetingStatus;
use App\Models\DigestDelivery;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\ProviderCall;
use App\Models\Rep;
use App\Models\RepWeekSync;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

final class FactoriesTest extends IntegrationTestCase
{
    #[Test]
    public function every_factory_builds_a_row_in_the_current_tenant(): void
    {
        $tenant = $this->actingAsTenant();
        $rep = Rep::factory()->for($tenant)->inTimezone('Europe/Lisbon')->create();

        $lead = Lead::factory()->for($tenant)->withoutLinkedin()->create();
        $meeting = Meeting::factory()->for($tenant)->cancelled()->create();
        $sync = RepWeekSync::factory()->for($rep)->neverSucceeded()->create();
        $delivery = DigestDelivery::factory()->for($rep)->create();
        $call = ProviderCall::factory()->for($rep)->create();

        self::assertSame('Europe/Lisbon', $rep->clock()->timezone);
        self::assertNull($lead->linkedin_url);
        self::assertSame(MeetingStatus::Cancelled, $meeting->status);
        self::assertNull($sync->last_success_at);
        self::assertSame($tenant->id, $sync->tenant_id);
        self::assertSame(DeliveryStatus::Pending, $delivery->status);
        self::assertSame($tenant->id, $delivery->tenant_id);
        self::assertSame(CallOutcome::Success, $call->outcome);
        self::assertSame($tenant->id, $call->tenant_id);
    }

    #[Test]
    public function held_scope_excludes_cancelled_and_removed_meetings(): void
    {
        $tenant = $this->actingAsTenant();
        $start = CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC');
        Meeting::factory()->for($tenant)->startingAt($start)->create();
        Meeting::factory()->for($tenant)->startingAt($start)->tentative()->create();
        Meeting::factory()->for($tenant)->startingAt($start)->cancelled()->create();
        Meeting::factory()->for($tenant)->startingAt($start)->removed()->create();

        self::assertSame(2, Meeting::query()->held()->count());
    }

    #[Test]
    public function rep_email_is_unique_per_tenant_and_case_insensitive(): void
    {
        $tenant = $this->actingAsTenant();
        Rep::factory()->for($tenant)->create(['email' => 'Ana@Acme.test']);

        $this->expectException(UniqueConstraintViolationException::class);
        Rep::factory()->for($tenant)->create(['email' => 'ana@acme.test']);
    }
}
