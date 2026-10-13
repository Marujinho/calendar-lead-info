<?php

declare(strict_types=1);

namespace Tests\Integration\Commands;

use App\Models\Rep;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

final class DeactivateRepCommandTest extends IntegrationTestCase
{
    use RecordsJobLog;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recordJobLog();
        $this->tenant = Tenant::factory()->create();
    }

    private function fresh(Rep $rep): Rep
    {
        return $this->app->make(TenantContext::class)->run($this->tenant, static fn (): Rep => Rep::query()->findOrFail($rep->id));
    }

    #[Test]
    public function it_deactivates_a_rep_case_insensitively_and_logs_ids_only(): void
    {
        $rep = Rep::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'ana@acme.test']);

        $this->artisan('rep:deactivate', ['tenant' => (string) $this->tenant->id, 'email' => 'ANA@acme.TEST'])
            ->expectsOutputToContain(sprintf('Rep %d deactivated.', $rep->id))
            ->assertExitCode(Command::SUCCESS);

        self::assertFalse($this->fresh($rep)->active);
        self::assertSame(['rep.deactivated'], $this->loggedEvents());
        self::assertSame(['tenant_id' => $this->tenant->id, 'rep_id' => $rep->id, 'event' => 'rep.deactivated'], $this->logRecords[0]['context']);
    }

    #[Test]
    public function a_deactivated_rep_drops_out_of_the_next_cycle(): void
    {
        $ana = Rep::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'ana@acme.test']);
        $rui = Rep::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'rui@acme.test']);

        $this->artisan('rep:deactivate', ['tenant' => (string) $this->tenant->id, 'email' => 'ana@acme.test'])
            ->assertExitCode(Command::SUCCESS);

        // Sync and digest dispatchers select reps through Rep::active().
        $activeIds = $this->app->make(TenantContext::class)->run(
            $this->tenant,
            static fn (): array => Rep::query()->active()->pluck('id')->all(),
        );
        self::assertSame([$rui->id], $activeIds);
        self::assertNotContains($ana->id, $activeIds);
    }

    #[Test]
    public function an_already_inactive_rep_is_a_successful_no_op(): void
    {
        $rep = Rep::factory()->inactive()->create(['tenant_id' => $this->tenant->id, 'email' => 'ana@acme.test']);

        $this->artisan('rep:deactivate', ['tenant' => (string) $this->tenant->id, 'email' => 'ana@acme.test'])
            ->expectsOutputToContain('already inactive')
            ->assertExitCode(Command::SUCCESS);

        self::assertFalse($this->fresh($rep)->active);
        self::assertSame([], $this->logRecords);
    }

    #[Test]
    public function an_unknown_rep_fails(): void
    {
        $this->artisan('rep:deactivate', ['tenant' => (string) $this->tenant->id, 'email' => 'nobody@acme.test'])
            ->expectsOutputToContain('No rep with this email in this tenant.')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function a_rep_of_another_tenant_is_not_found(): void
    {
        $other = Tenant::factory()->create();
        $rep = Rep::factory()->create(['tenant_id' => $other->id, 'email' => 'ana@acme.test']);

        $this->artisan('rep:deactivate', ['tenant' => (string) $this->tenant->id, 'email' => 'ana@acme.test'])
            ->expectsOutputToContain('No rep with this email in this tenant.')
            ->assertExitCode(Command::FAILURE);

        $stillActive = $this->app->make(TenantContext::class)->run($other, static fn (): bool => Rep::query()->findOrFail($rep->id)->active);
        self::assertTrue($stillActive);
    }

    #[Test]
    public function an_unknown_tenant_or_bad_email_fails(): void
    {
        $this->artisan('rep:deactivate', ['tenant' => (string) ($this->tenant->id + 1000), 'email' => 'ana@acme.test'])
            ->expectsOutputToContain('Unknown tenant.')
            ->assertExitCode(Command::FAILURE);

        $this->artisan('rep:deactivate', ['tenant' => (string) $this->tenant->id, 'email' => 'nope'])
            ->expectsOutputToContain('Invalid email address.')
            ->assertExitCode(Command::FAILURE);
    }
}
