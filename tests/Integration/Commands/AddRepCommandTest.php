<?php

declare(strict_types=1);

namespace Tests\Integration\Commands;

use App\Events\RepCreated;
use App\Models\Rep;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

final class AddRepCommandTest extends IntegrationTestCase
{
    use RecordsJobLog;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([RepCreated::class]);
        $this->recordJobLog();
        $this->tenant = Tenant::factory()->create(['default_timezone' => 'America/Sao_Paulo']);
    }

    /** @return list<Rep> */
    private function repsOf(Tenant $tenant): array
    {
        return $this->app->make(TenantContext::class)->run($tenant, static fn (): array => Rep::query()->orderBy('id')->get()->all());
    }

    #[Test]
    public function it_adds_a_rep_with_the_tenant_timezone_and_fires_rep_created(): void
    {
        $this->artisan('rep:add', ['tenant' => (string) $this->tenant->id, 'email' => ' Ana@ACME.test ', 'name' => 'Ana Lima'])
            ->expectsOutputToContain('Rep created: id ')
            ->assertExitCode(Command::SUCCESS);

        $reps = $this->repsOf($this->tenant);
        self::assertCount(1, $reps);
        self::assertSame('ana@acme.test', $reps[0]->email);
        self::assertSame('Ana Lima', $reps[0]->name);
        self::assertSame('America/Sao_Paulo', $reps[0]->timezone);
        self::assertTrue($reps[0]->active);
        self::assertSame($this->tenant->id, $reps[0]->tenant_id);

        $repId = $reps[0]->id;
        Event::assertDispatchedTimes(RepCreated::class, 1);
        Event::assertDispatched(RepCreated::class, fn (RepCreated $e): bool => $e->tenantId === $this->tenant->id && $e->repId === $repId);

        self::assertSame(['rep.created'], $this->loggedEvents());
        self::assertSame(['tenant_id' => $this->tenant->id, 'rep_id' => $repId, 'event' => 'rep.created'], $this->logRecords[0]['context']);
        self::assertStringNotContainsString('acme.test', $this->loggedText());
        self::assertStringNotContainsString('Ana', $this->loggedText());
    }

    #[Test]
    public function the_timezone_option_overrides_the_tenant_default(): void
    {
        $this->artisan('rep:add', ['tenant' => (string) $this->tenant->id, 'email' => 'rui@acme.test', 'name' => 'Rui', '--timezone' => 'Europe/Lisbon'])
            ->assertExitCode(Command::SUCCESS);

        self::assertSame('Europe/Lisbon', $this->repsOf($this->tenant)[0]->timezone);
    }

    #[Test]
    public function an_unknown_tenant_fails(): void
    {
        foreach ([(string) ($this->tenant->id + 1000), 'abc', '0', '-1', '1; drop'] as $tenantId) {
            $this->artisan('rep:add', ['tenant' => $tenantId, 'email' => 'ana@acme.test', 'name' => 'Ana'])
                ->expectsOutputToContain('Unknown tenant.')
                ->assertExitCode(Command::FAILURE);
        }

        self::assertSame([], $this->repsOf($this->tenant));
        Event::assertNotDispatched(RepCreated::class);
    }

    #[Test]
    public function a_bad_email_fails(): void
    {
        $this->artisan('rep:add', ['tenant' => (string) $this->tenant->id, 'email' => 'not-an-email', 'name' => 'Ana'])
            ->expectsOutputToContain('Invalid email address.')
            ->assertExitCode(Command::FAILURE);

        self::assertSame([], $this->repsOf($this->tenant));
        Event::assertNotDispatched(RepCreated::class);
    }

    #[Test]
    public function an_empty_name_fails(): void
    {
        $this->artisan('rep:add', ['tenant' => (string) $this->tenant->id, 'email' => 'ana@acme.test', 'name' => ' '])
            ->expectsOutputToContain('Name must be 1 to 255 characters.')
            ->assertExitCode(Command::FAILURE);

        self::assertSame([], $this->repsOf($this->tenant));
    }

    #[Test]
    public function an_unknown_timezone_fails(): void
    {
        $this->artisan('rep:add', ['tenant' => (string) $this->tenant->id, 'email' => 'ana@acme.test', 'name' => 'Ana', '--timezone' => 'Mars/Olympus'])
            ->expectsOutputToContain('Unknown timezone')
            ->assertExitCode(Command::FAILURE);

        self::assertSame([], $this->repsOf($this->tenant));
        Event::assertNotDispatched(RepCreated::class);
    }

    #[Test]
    public function a_duplicate_email_in_any_case_fails_without_a_second_row_or_event(): void
    {
        $this->artisan('rep:add', ['tenant' => (string) $this->tenant->id, 'email' => 'ana@acme.test', 'name' => 'Ana'])
            ->assertExitCode(Command::SUCCESS);

        $this->artisan('rep:add', ['tenant' => (string) $this->tenant->id, 'email' => 'ANA@Acme.Test', 'name' => 'Ana Again'])
            ->expectsOutputToContain('A rep with this email already exists in this tenant.')
            ->assertExitCode(Command::FAILURE);

        $reps = $this->repsOf($this->tenant);
        self::assertCount(1, $reps);
        self::assertSame('Ana', $reps[0]->name);
        Event::assertDispatchedTimes(RepCreated::class, 1);
        self::assertSame(['rep.created'], $this->loggedEvents());
    }

    #[Test]
    public function a_duplicate_inserted_concurrently_is_reported_as_a_duplicate(): void
    {
        // Simulates another process winning the race between the existence check and the insert.
        Rep::creating(function (Rep $rep): void {
            DB::table('reps')->insert([
                'tenant_id' => $this->tenant->id,
                'email' => $rep->email,
                'name' => 'Concurrent',
                'timezone' => 'UTC',
                'active' => true,
            ]);
        });

        $this->artisan('rep:add', ['tenant' => (string) $this->tenant->id, 'email' => 'ana@acme.test', 'name' => 'Ana'])
            ->expectsOutputToContain('A rep with this email already exists in this tenant.')
            ->assertExitCode(Command::FAILURE);

        $reps = $this->repsOf($this->tenant);
        self::assertCount(1, $reps);
        self::assertSame('Concurrent', $reps[0]->name);
        Event::assertNotDispatched(RepCreated::class);
        self::assertSame([], $this->logRecords);
    }

    #[Test]
    public function the_same_email_in_another_tenant_is_a_different_rep(): void
    {
        $other = Tenant::factory()->create();
        Rep::factory()->create(['tenant_id' => $other->id, 'email' => 'ana@acme.test']);

        $this->artisan('rep:add', ['tenant' => (string) $this->tenant->id, 'email' => 'ana@acme.test', 'name' => 'Ana'])
            ->assertExitCode(Command::SUCCESS);

        self::assertCount(1, $this->repsOf($this->tenant));
        self::assertCount(1, $this->repsOf($other));
    }

    #[Test]
    public function the_command_leaves_no_tenant_in_context(): void
    {
        $this->artisan('rep:add', ['tenant' => (string) $this->tenant->id, 'email' => 'ana@acme.test', 'name' => 'Ana'])
            ->assertExitCode(Command::SUCCESS);

        self::assertFalse($this->app->make(TenantContext::class)->has());
    }
}
