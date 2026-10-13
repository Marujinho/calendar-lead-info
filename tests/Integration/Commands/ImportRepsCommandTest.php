<?php

declare(strict_types=1);

namespace Tests\Integration\Commands;

use App\Events\RepCreated;
use App\Models\Rep;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

final class ImportRepsCommandTest extends IntegrationTestCase
{
    use RecordsJobLog;

    private Tenant $tenant;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([RepCreated::class]);
        $this->recordJobLog();
        $this->tenant = Tenant::factory()->create(['default_timezone' => 'America/Sao_Paulo']);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function csv(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'reps');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    /** @return array<string, string> email => timezone */
    private function reps(): array
    {
        return $this->app->make(TenantContext::class)->run(
            $this->tenant,
            static fn (): array => Rep::query()->orderBy('id')->pluck('timezone', 'email')->all(),
        );
    }

    #[Test]
    public function it_imports_every_row_and_fires_one_event_per_rep(): void
    {
        $path = $this->csv("email,name,timezone\nana@acme.test,Ana,America/Sao_Paulo\nRUI@acme.test,\"Rui, Jr\",Europe/Lisbon\nlee@acme.test,Lee,\n");

        $this->artisan('rep:import', ['tenant' => (string) $this->tenant->id, 'path' => $path])
            ->expectsOutputToContain('Import finished: 3 created, 0 skipped, 0 failed.')
            ->assertExitCode(Command::SUCCESS);

        self::assertSame([
            'ana@acme.test' => 'America/Sao_Paulo',
            'rui@acme.test' => 'Europe/Lisbon',
            'lee@acme.test' => 'America/Sao_Paulo',
        ], $this->reps());

        Event::assertDispatchedTimes(RepCreated::class, 3);
        Event::assertDispatched(RepCreated::class, fn (RepCreated $e): bool => $e->tenantId === $this->tenant->id);
        self::assertSame(['rep.created', 'rep.created', 'rep.created'], $this->loggedEvents());
        self::assertStringNotContainsString('acme.test', $this->loggedText());
    }

    #[Test]
    public function a_two_column_header_uses_the_tenant_timezone(): void
    {
        $path = $this->csv("\xEF\xBB\xBFEmail, Name\r\nana@acme.test,Ana\r\n\r\n");

        $this->artisan('rep:import', ['tenant' => (string) $this->tenant->id, 'path' => $path])
            ->expectsOutputToContain('Import finished: 1 created, 0 skipped, 0 failed.')
            ->assertExitCode(Command::SUCCESS);

        self::assertSame(['ana@acme.test' => 'America/Sao_Paulo'], $this->reps());
    }

    #[Test]
    public function a_bad_header_aborts_before_any_row(): void
    {
        foreach (["name,email,timezone\nana@acme.test,Ana,UTC\n", "email,name,tz\nana@acme.test,Ana,UTC\n", "email,name,timezone,extra\n", ''] as $contents) {
            $this->artisan('rep:import', ['tenant' => (string) $this->tenant->id, 'path' => $this->csv($contents)])
                ->expectsOutputToContain('Invalid CSV header')
                ->assertExitCode(Command::FAILURE);
        }

        self::assertSame([], $this->reps());
        Event::assertNotDispatched(RepCreated::class);
    }

    #[Test]
    public function an_invalid_row_is_reported_by_line_and_the_rest_are_imported(): void
    {
        $path = $this->csv(implode("\n", [
            'email,name,timezone',
            'ana@acme.test,Ana,UTC',
            'not-an-email,Bad,UTC',
            'rui@acme.test,Rui,Mars/Olympus',
            'kim@acme.test,,UTC',
            'too,many,columns,here',
            'lee@acme.test,Lee,Europe/Lisbon',
        ])."\n");

        $this->artisan('rep:import', ['tenant' => (string) $this->tenant->id, 'path' => $path])
            ->expectsOutputToContain('Line 3: Invalid email address.')
            ->expectsOutputToContain('Line 4: Unknown timezone')
            ->expectsOutputToContain('Line 5: Name must be 1 to 255 characters.')
            ->expectsOutputToContain('Line 6: expected 3 columns, got 4.')
            ->expectsOutputToContain('Import finished: 2 created, 0 skipped, 4 failed.')
            ->assertExitCode(Command::FAILURE);

        self::assertSame(['ana@acme.test' => 'UTC', 'lee@acme.test' => 'Europe/Lisbon'], $this->reps());
        Event::assertDispatchedTimes(RepCreated::class, 2);
    }

    #[Test]
    public function existing_reps_and_repeated_rows_are_skipped_and_counted(): void
    {
        Rep::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'ana@acme.test', 'name' => 'Original', 'timezone' => 'UTC']);

        $path = $this->csv("email,name,timezone\nANA@acme.test,Ana,Europe/Lisbon\nrui@acme.test,Rui,\nRui@Acme.test,Rui again,\n");

        $this->artisan('rep:import', ['tenant' => (string) $this->tenant->id, 'path' => $path])
            ->expectsOutputToContain('Line 2: rep already exists, skipped.')
            ->expectsOutputToContain('Line 4: rep already exists, skipped.')
            ->expectsOutputToContain('Import finished: 1 created, 2 skipped, 0 failed.')
            ->assertExitCode(Command::SUCCESS);

        self::assertSame(['ana@acme.test' => 'UTC', 'rui@acme.test' => 'America/Sao_Paulo'], $this->reps());
        Event::assertDispatchedTimes(RepCreated::class, 1);
    }

    #[Test]
    public function an_unknown_tenant_or_missing_file_fails(): void
    {
        $path = $this->csv("email,name,timezone\nana@acme.test,Ana,UTC\n");

        $this->artisan('rep:import', ['tenant' => (string) ($this->tenant->id + 1000), 'path' => $path])
            ->expectsOutputToContain('Unknown tenant.')
            ->assertExitCode(Command::FAILURE);

        $this->artisan('rep:import', ['tenant' => (string) $this->tenant->id, 'path' => $path.'.missing'])
            ->expectsOutputToContain('CSV file not found or not readable.')
            ->assertExitCode(Command::FAILURE);

        self::assertSame([], $this->reps());
    }
}
