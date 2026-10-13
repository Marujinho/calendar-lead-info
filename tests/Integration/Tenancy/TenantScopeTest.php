<?php

declare(strict_types=1);

namespace Tests\Integration\Tenancy;

use App\Data\Enums\ResponseStatus;
use App\Exceptions\MissingTenantContextException;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\MeetingLead;
use App\Models\MeetingRep;
use App\Models\Rep;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

final class TenantScopeTest extends IntegrationTestCase
{
    #[Test]
    public function querying_a_scoped_model_without_a_tenant_throws(): void
    {
        $this->expectException(MissingTenantContextException::class);

        Rep::query()->count();
    }

    #[Test]
    public function tenant_itself_is_not_scoped(): void
    {
        Tenant::factory()->count(2)->create();

        self::assertSame(2, Tenant::query()->count());
    }

    #[Test]
    public function tenant_id_is_filled_from_the_context_on_create(): void
    {
        $tenant = $this->actingAsTenant();

        $lead = Lead::query()->create(['email' => 'A@Client.com', 'full_name' => 'A']);

        self::assertSame($tenant->id, $lead->tenant_id);
        self::assertSame('a@client.com', $lead->email);
    }

    #[Test]
    public function the_same_lead_email_in_two_tenants_never_crosses(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $context = $this->app->make(TenantContext::class);

        $context->run($a, fn () => Lead::query()->create(['email' => 'shared@client.com', 'full_name' => 'In A']));
        $context->run($b, fn () => Lead::query()->create(['email' => 'shared@client.com', 'full_name' => 'In B']));

        $this->actingAsTenant($a);
        self::assertSame(1, Lead::query()->count());
        self::assertSame('In A', Lead::query()->firstOrFail()->full_name);
        self::assertNull(Lead::query()->find(Lead::query()->firstOrFail()->id + 1)); // B's row is invisible

        $this->actingAsTenant($b);
        self::assertSame('In B', Lead::query()->firstOrFail()->full_name);
    }

    #[Test]
    public function pivot_rows_carry_tenant_id_and_are_scoped(): void
    {
        $tenant = $this->actingAsTenant();
        $rep = Rep::factory()->for($tenant)->create();
        $lead = Lead::factory()->for($tenant)->create();
        $meeting = Meeting::factory()->for($tenant)->create();

        $meeting->reps()->attach($rep);
        $meeting->leads()->attach($lead, ['response_status' => ResponseStatus::Declined->value]);

        self::assertSame($tenant->id, MeetingRep::query()->firstOrFail()->tenant_id);
        $link = MeetingLead::query()->firstOrFail();
        self::assertSame($tenant->id, $link->tenant_id);
        self::assertSame(ResponseStatus::Declined, $link->response_status);

        $this->actingAsTenant(Tenant::factory()->create());
        self::assertSame(0, MeetingRep::query()->count());
        self::assertSame(0, MeetingLead::query()->count());
    }

    #[Test]
    public function relationships_are_scoped_too(): void
    {
        $a = $this->actingAsTenant();
        $rep = Rep::factory()->for($a)->create();
        $meeting = Meeting::factory()->for($a)->create();
        $meeting->reps()->attach($rep);

        self::assertCount(1, $rep->meetings()->get());

        $this->actingAsTenant(Tenant::factory()->create());
        self::assertCount(0, $rep->meetings()->get());
    }
}
