<?php

declare(strict_types=1);

namespace Tests\Integration\Commands;

use App\Console\Commands\Tenant\CreateTenantCommand;
use App\Events\RepCreated;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

/**
 * PRD §12 A02/A09: across the whole onboarding flow, no credential reaches
 * console output or logs, and no email or name reaches a JobLog line.
 */
final class CommandsDoNotLeakSecretsOrPiiTest extends IntegrationTestCase
{
    use PipesStdin;
    use RecordsJobLog;

    private const SECRET = 'top-secret-credential-xyz';

    #[Test]
    public function the_demo_onboarding_flow_leaks_no_credential_email_or_name(): void
    {
        Event::fake([RepCreated::class]);
        $this->recordJobLog();

        [$code, $output] = $this->runWithStdin(
            CreateTenantCommand::class,
            ['name' => 'Acme', '--domains' => ['acme.test'], '--timezone' => 'America/Sao_Paulo', '--credentials-stdin' => true],
            '{"client_secret":"'.self::SECRET.'"}',
        );
        self::assertSame(Command::SUCCESS, $code);
        $tenantId = (string) Tenant::query()->sole()->id;

        $csv = (string) tempnam(sys_get_temp_dir(), 'reps');
        file_put_contents($csv, "email,name,timezone\nkim@acme.test,Kimberly Stone,\nbad-row,Nobody,UTC\n");

        $calls = [
            ['rep:add', ['tenant' => $tenantId, 'email' => 'ana@acme.test', 'name' => 'Ana Lima', '--timezone' => 'America/Sao_Paulo']],
            ['rep:add', ['tenant' => $tenantId, 'email' => 'rui@acme.test', 'name' => 'Rui Costa', '--timezone' => 'Europe/Lisbon']],
            ['rep:add', ['tenant' => $tenantId, 'email' => 'lee@acme.test', 'name' => 'Lee Park']],
            ['rep:import', ['tenant' => $tenantId, 'path' => $csv]],
            ['rep:deactivate', ['tenant' => $tenantId, 'email' => 'lee@acme.test']],
        ];

        try {
            foreach ($calls as [$name, $parameters]) {
                Artisan::call($name, $parameters);
                $output .= Artisan::output();
            }
        } finally {
            @unlink($csv);
        }

        self::assertStringNotContainsString(self::SECRET, $output);
        self::assertStringNotContainsString(self::SECRET, $this->loggedText());

        self::assertSame(
            ['tenant.created', 'rep.created', 'rep.created', 'rep.created', 'rep.created', 'rep.deactivated'],
            $this->loggedEvents(),
        );
        Event::assertDispatchedTimes(RepCreated::class, 4);

        $logs = $this->loggedText();
        self::assertStringNotContainsString('@', $logs);
        foreach (['acme.test', 'Ana', 'Rui', 'Lee', 'Kimberly', 'Nobody', 'bad-row'] as $pii) {
            self::assertStringNotContainsString($pii, $logs);
        }
        foreach ($this->logRecords as $record) {
            self::assertSame([], array_diff(array_keys($record['context']), ['tenant_id', 'rep_id', 'event']));
        }
    }
}
