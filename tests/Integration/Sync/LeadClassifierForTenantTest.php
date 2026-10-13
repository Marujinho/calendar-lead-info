<?php

declare(strict_types=1);

namespace Tests\Integration\Sync;

use App\Data\AttendeeData;
use App\Models\Rep;
use App\Models\Tenant;
use App\Services\Sync\LeadClassifier;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

final class LeadClassifierForTenantTest extends IntegrationTestCase
{
    #[Test]
    public function it_excludes_active_and_inactive_reps_of_the_tenant_only(): void
    {
        $other = Tenant::factory()->create();
        $this->app->make(TenantContext::class)->run($other, fn () => Rep::factory()->for($other)->create(['email' => 'other.rep@gmail.com']));

        $tenant = $this->actingAsTenant(Tenant::factory()->create(['internal_domains' => ['OurCo.test']]));
        Rep::factory()->for($tenant)->create(['email' => 'Active@Gmail.com']);
        Rep::factory()->for($tenant)->inactive()->create(['email' => 'gone@yahoo.test']);

        DB::enableQueryLog();
        $classifier = LeadClassifier::forTenant($tenant);
        self::assertCount(1, DB::getQueryLog(), 'rep emails are loaded once');

        self::assertFalse($classifier->isLead(new AttendeeData('active@gmail.com', 'A')));
        self::assertFalse($classifier->isLead(new AttendeeData('gone@yahoo.test', 'G')));
        self::assertFalse($classifier->isLead(new AttendeeData('x@ourco.test', 'X')));
        self::assertTrue($classifier->isLead(new AttendeeData('other.rep@gmail.com', 'O')), 'another tenant\'s rep is a lead here');
    }
}
